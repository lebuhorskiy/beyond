<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Users\User;
use App\Models\Users\Withdrawal;
use App\Services\UserWithdrawalNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WithdrawalsController extends Controller
{

    /**
     * Get list of withdrawals by category
     *
     * @param $category
     * @return JsonResponse
     */
    public function getListByCategory($category): ?JsonResponse
    {
        $list = [];

        if($category === 'all') {
            $list = Withdrawal::with('user')
                ->orderByDesc('created_at')
                ->get();
        } else {
            $list = Withdrawal::with('user')
                ->where('status', '=', $category)
                ->orderByDesc('created_at')
                ->get();
        }


        return \response()->json(['list' => $list]);
    }

    /**
     * Get withdrawal request
     *
     * @param $id
     * @return JsonResponse
     */
    public function get($id): ?JsonResponse
    {
        $withdrawal = Withdrawal::with('user')->where('id', '=', $id)->first();

        return \response()->json(['withdrawal' => $withdrawal]);
    }

    /**
     * Update withdrawal request
     *
     * @param Request $request
     * @param $id
     * @return JsonResponse
     */
    public function update(Request $request, $id): ?JsonResponse
    {
        $withdrawal = Withdrawal::find($id);

        $withdrawal->update([
            'completed' => $request->get('completed'),
        ]);

        if($request->get('completed')) {
            $withdrawal->update([
                'status' => 'completed'
            ]);

            $user = User::find($withdrawal->user_id);

            (new UserWithdrawalNotifications())->notifyWithdrawalCompleted($user);
        }

        return \response()->json(['message' => 'Запрос на вывод успешно обновлен']);
    }

    /**
     * Decline withdrawal request
     *
     * @param $id
     * @param Request $request
     * @return JsonResponse
     */
    public function decline($id, Request $request): ?JsonResponse
    {
        $withdrawal = Withdrawal::find($id);
        $withdrawal->update([
            'status' => 'declined',
            'comment' => $request->get('comment')
        ]);

        $user = User::find($withdrawal->user_id);
        $user->update([
            'balance_points' => $user->balance_points + $withdrawal->sum
        ]);

        (new UserWithdrawalNotifications())->notifyWithdrawalDeclined($user, $request->get('comment'));

        return \response()->json(['message' => 'Запрос на вывод успешно отменен']);
    }
}
