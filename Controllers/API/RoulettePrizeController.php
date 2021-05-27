<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\RoulettePrize;
use App\Models\RoulettePrizeToUser;
use App\Models\Users\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class RoulettePrizeController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $prizes = RoulettePrize::all()->sortByDesc('chance');

        return response()->json(array_values($prizes->toArray()));
    }

    public function checkPermissions(): JsonResponse
    {
        if(!$this->_checkVerifiedEmail()) {
            return response()->json([
                'message' => __('roulette.error_verified_email'),
                'status' => false
            ]);
        }

        if(!$this->_checkSteamAccount() && !$this->_checkEpicAccount()) {
            return response()->json([
                'message' => __('roulette.error_account_not_linked'),
                'status' => false
            ]);
        }

        return response()->json(['status' => true], 200);
    }

    private function _checkTimes($lastRolled): bool
    {
        return !($lastRolled > Carbon::now() || Carbon::now() < Carbon::parse($lastRolled)->addDay());
    }

    private function _checkVerifiedEmail()
    {
        return !!auth('api')->user()->email_verified_at;
    }

    private function _checkEpicAccount()
    {
        return !!auth('api')->user()->epic_nickname;
    }

    private function _checkSteamAccount()
    {
        return !!auth('api')->user()->steam_nickname;
    }

    public function resolveRoulettePrize(Request $request, RoulettePrize $prize)
    {
        $user = User::find(auth('api')->user()->id);

        if(!$this->_checkVerifiedEmail()) {
            return response()->json([
                'message' => __('roulette.error_verified_email')
            ], $this->errorStatus);
        }

        if(!$this->_checkSteamAccount() && !$this->_checkEpicAccount()) {
            return response()->json([
                'message' => __('roulette.error_account_not_linked')
            ], $this->errorStatus);
        }

        if($request->free) {
            if($user->roulette_free_rolled_at && !$this->_checkTimes($user->roulette_free_rolled_at)) {
                return response()->json([
                    'message' => __('roulette.error_time')
                ], $this->errorStatus);
            }
            $user->update([
                'roulette_free_rolled_at' => Carbon::now()
            ]);
        }

        $data = [];
        if (!$prize->prize) {
            $data['icon'] = 'error';
            $data['message'] = $prize->title[App::getLocale()];
            $data['title'] = __('roulette.lose');
        } else {
            $data['icon'] = 'success';
            $data['message'] = __('roulette.prize', [
                "title" => $prize->title[App::getLocale()]
            ]);
            $data['title'] = __('roulette.win');

            $prize->resolvePrize($request->free);

            RoulettePrizeToUser::create([
                'user_id' => auth('api')->user()->id,
                'roulette_prize_id' => $prize->id
            ]);
        }

        return response()->json($data);
    }
}
