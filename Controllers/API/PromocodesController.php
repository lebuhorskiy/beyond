<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Users\User;
use Carbon\Carbon;
use App\Classes\Promocodes\Facades\Promocodes;
use App\Classes\Promocodes\Models\Promocode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PromocodesController extends Controller
{

    /**
     * Generate promo code request api
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function generate(Request $request): JsonResponse
    {
        try {
            //Кол-во промокодов
            $amount = $request->post('amount', 1);
            //Одноразовый промокод?
            $is_disposable = $request->post('is_disposable', false);
            //Кол-во применений
            $quantity = $request->post('quantity', null);
            //Награда за промокод
            $reward = $request->post('reward', null);
            //Дата окончания промокода
            $expires_in = $request->post('expires_in', null);
            //Дополнительная информация для промокода
            $data = $request->post('data', null);
            $codes = Promocodes::create((int)$amount, (int)$reward, $data, $expires_in, (int)$quantity, (float)$is_disposable);

            $generated = [];
            $i = 1;
            foreach ($codes as $code) {
                $generated[] = [
                    'code' => $code['code'],
                    'reward' => $code['reward']
                ];
                $i++;
            }

            return response()->json([
                'message' => $amount === 1 ? 'Промокод ' . $codes[0]['code'] . ' успешно создан' : 'Промокоды успешно созданы',
                'generated' => $generated
            ]);
        } catch (\Exception $exception) {
            return response()->json([
                'message' => 'Ошибка при создании промокода',
            ], $this->errorStatus);
        }
    }

    /**
     * List promo codes request api
     *
     * @return JsonResponse
     */
    public function list(): JsonResponse
    {
        return response()->json([
            'list' => Promocode::with('users')->orderByDesc('id')->get(),
        ]);
    }

    /**
     * Apply promo code admin request api
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function apply_admin(Request $request): JsonResponse
    {
        //Id пользователя
        $user_id = $request->post('user_id');

        if (!$user_id) {
            return response()->json([
                'data' => 'Параметр user_id не заполнен',
            ], $this->errorStatus);
        }

        //Id промокода
        $promo_code_id = $request->post('promo_code_id');

        if (!$promo_code_id) {
            return response()->json([
                'data' => 'Параметр promo_code_id не заполнен',
            ], $this->errorStatus);
        }

        $user = User::whereId($user_id)->first();

        if (!$user) {
            return response()->json([
                'data' => 'Пользователь c id=' . $user_id . ' не найден',
            ], $this->errorStatus);
        }
        $promo_code = Promocode::whereId($promo_code_id)->first();

        if (!$promo_code) {
            return response()->json([
                'data' => 'Промокод c id=' . $promo_code_id . ' не найден',
            ], $this->errorStatus);
        }

        if (Promocodes::check($promo_code->code) === false) {
            return response()->json([
                'data' => 'Промокод ' . $promo_code->code . ' недействителен',
            ], $this->errorStatus);
        }

        $used_at = Carbon::now();
        DB::table('promocode_user')->updateOrInsert([
            'user_id' => (int)$user_id,
            'promocode_id' => (int)$promo_code_id,
        ], [
            'user_id' => (int)$user_id,
            'promocode_id' => (int)$promo_code_id,
            'used_at' => $used_at,
        ]);

        DB::table('users')->whereId($user_id)->update([
            'balance_points' => ((int)$user->balance_points) + (int)$promo_code->reward,
        ]);

        return response()->json([
            'message' => 'Промокод успешно применен!',
        ]);
    }

    /**
     * Apply promo code user request api
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function apply(Request $request): JsonResponse
    {
        //Код промокода
        $code = $request->post('code');

        if (empty($code)) {
            return response()->json([
                'message' => __('promocodes.error_not_filled'),
            ], $this->errorStatus);
        }

        if (Promocodes::check($code) === false) {
            return response()->json([
                'message' => __('promocodes.error_invalid', ['code' => $code]),
            ], $this->errorStatus);
        }

        $user = DB::table('users')->whereId(auth()->id())->first();
        $promo_code = DB::table('promocodes')->whereCode($code)->first();

        $alreadyApplied = DB::table('promocode_user')
            ->where('promocode_id', '=', $promo_code->id)
            ->where('user_id', '=', $user->id)
            ->first();

        if ($alreadyApplied) {
            return response()->json([
                'message' => __('promocodes.error_already_applied', ['code' => $code]),
            ], $this->errorStatus);
        }

        Promocodes::apply($code);

        $newBalance = ((int)$user->balance_points) + (int)$promo_code->reward;

        DB::table('users')->whereId(auth()->id())->update([
            'balance_points' => $newBalance,
        ]);

        return response()->json([
            'message' => __('promocodes.successfully_applied'),
            'balance' => $newBalance
        ]);
    }

    /**
     * Disable promo code
     *
     * @param $id
     * @return JsonResponse
     */
    public function disable($id): JsonResponse
    {
        $code = DB::table('promocodes')->where('id', $id)->first();
        Promocodes::disable($code->code);

        return response()->json([
            'message' => 'Промокод отключен!',
        ]);
    }

    /**
     * Get promo code by id
     *
     * @param $id
     * @return JsonResponse
     */
    public function get($id): JsonResponse
    {
        $promocode = DB::table('promocodes')->where('id', $id)->first();
        $users = DB::table('promocode_user')
            ->join('users', 'users.id', '=', 'promocode_user.user_id')
            ->where('promocode_user.promocode_id', '=', $promocode->id)
            ->select('promocode_user.used_at', 'users.uuid', 'users.nickname')
            ->get();

        return response()->json([
            'promocode' => $promocode,
            'users' => $users
        ]);
    }

    /**
     * Update promo code by id
     *
     * @param Request $request
     * @param $id
     * @return JsonResponse
     */
    public function update(Request $request, $id): JsonResponse
    {
        Promocode::find($id)->update([
            'quantity' => $request->post('quantity'),
            'reward' => $request->post('reward'),
            'is_disposable' => $request->post('is_disposable'),
            'expires_at' => $request->post('expires_at'),
        ]);
        return response()->json([
            'message' => 'Промокод изменен!',
        ]);
    }
}
