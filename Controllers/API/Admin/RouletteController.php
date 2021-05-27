<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\RoulettePrize;
use App\Models\RoulettePrizeToUser;
use Illuminate\Http\JsonResponse;

class RouletteController extends Controller
{

    /**
     * Get list of all prizes
     *
     * @return JsonResponse
     */
    public function getPrizes(): ?JsonResponse
    {
        return response()->json([
            'list' => RoulettePrize::orderByDesc('chance')->get(),
        ]);
    }

    /**
     * Get list of prizes received by prize
     *
     * @param $prizeId
     * @return JsonResponse
     */
    public function getPrizesToUsersByPrize($prizeId): ?JsonResponse
    {
        $list = RoulettePrizeToUser::with('user', 'prize')
            ->where('roulette_prize_id', '=', $prizeId)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'list' => $list,
        ]);
    }

    /**
     * Get list of prizes by latest with limit
     *
     * @return JsonResponse
     */
    public function getPrizesToUsersByLatest(): ?JsonResponse
    {
        $list = RoulettePrizeToUser::with('user', 'prize')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'list' => $list,
        ]);
    }
}
