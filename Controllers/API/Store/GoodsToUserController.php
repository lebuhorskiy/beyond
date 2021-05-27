<?php

namespace App\Http\Controllers\API\Store;

use App\Http\Controllers\API\UserController;
use App\Http\Controllers\Controller;
use App\Models\Store\Good;
use App\Models\Store\GoodToUser;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class GoodsToUserController extends Controller
{
    public $successStatus = 200;

    /**
     * Get list of all purchases api
     *
     * @return JsonResponse
     */
    public function getPurchases()
    {
        $goods = GoodToUser::with('good', 'user')
            ->orderByDesc('created_at')
            ->get();
        return response()->json([
            'goods' => $goods,
            'success' => true
        ], $this->successStatus);
    }

    /**
     * Get purchase api
     *
     * @param $id
     * @return JsonResponse
     */
    public function getPurchase($id)
    {
        $order = GoodToUser::with('good', 'user')->where('id', '=', $id)->first();
        return response()->json([
            'order' => $order,
            'success' => true
        ], $this->successStatus);
    }

    /**
     * Get list of all goods api
     *
     * @return JsonResponse
     */
    public function getGoodsToUser()
    {
        $goods = GoodToUser::with('good')->where('user_id','=',auth()->id())
            ->orderByDesc('created_at')
            ->get();
        return response()->json([
            'goods' => $goods,
            'success' => true
        ], $this->successStatus);
    }

    /**
     * Get list of goods by catalog id api
     *
     * @param $id
     * @return JsonResponse
     */
    public function getGoodsToUserByGoods($id)
    {
        $goods = GoodToUser::with('good')
            ->where('good_id', '=', $id)
            ->where('user_id','=',auth()->id())
            ->get();
        return response()->json([
            'goods' => $goods,
            'success' => true
        ], $this->successStatus);
    }

    /**
     * Get one from goods api
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function makeGoodToUser(Request $request)
    {
        $good = Good::find($request->get('good_id'));
        $user = auth()->user();

        if($user->balance_points >= $good->price) {
            $goodToUser = GoodToUser::create([
                'user_id' => $user->id,
                'good_id' => $request->get('good_id'),
                'available' => false
            ]);

            $user->update([
                'balance_points' => $user->balance_points - $good->price
            ]);

            if($good->action) {
                $feature = new UserController();
                if($good->action !== 'nickname_change') {
                    $action_array = explode(':', $good->action);

                    $feature->actionFeature($user->id, $action_array[0], $action_array[1]);
                } else {
                    $feature->actionFeature($user->id, $good->action, 0);
                }

                $goodToUser->update(['available' => true]);
            }

            return response()->json([
                'success' => true,
                'balance' => $user->balance_points,
            ], $this->successStatus);
        } else {
            return response()->json([
                'success' => true,
                'message' => __('shop.not_enough_balance')
            ], 400);
        }
    }

    /**
     * Update one from goods api
     *
     * @param $id
     * @param Request $request
     * @return JsonResponse
     */
    public function updateGoodToUser($id, Request $request)
    {
        GoodToUser::find($id)->update(['available' => $request->get('available')]);

        return response()->json([
            'success' => true,
            'message' => $request->get('available') ? 'Заказ отмечен выполненым' : 'Заказ отмечен не выполненым',
        ], $this->successStatus);
    }

    /**
     * Delete one from goods api
     *
     * @param $id
     * @return JsonResponse
     */
    public function deleteGoodToUser($id)
    {
        GoodToUser::where('good_id', '=', $id)->delete();

        return response()->json([
            'success' => true
        ], $this->successStatus);
    }


}
