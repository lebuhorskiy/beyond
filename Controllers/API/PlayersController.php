<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Users\User;
use Illuminate\Http\JsonResponse;

class PlayersController extends Controller
{

    /**
     * Search players api
     *
     * @param $nickname
     * @return JsonResponse
     */
    public function search($nickname): JsonResponse
    {
        $response = User::where('nickname', 'ilike', "%$nickname%")
            ->select('id', 'uuid', 'nickname', 'avatar')
            ->orderByDesc('account_points')
            ->limit(50)
            ->get();

        return response()->json($response);
    }

    /**
     * Get rankings by account points with limit
     *
     * @return JsonResponse
     */
    public function rankingByAccountPoints(): JsonResponse
    {
        $response = User::orderBy('account_points', 'desc')
            ->select('avatar', 'account_points', 'uuid', 'nickname', 'highlight_avatar', 'highlight_nickname')
            ->limit(5)
            ->get();

        return response()->json($response);
    }
}
