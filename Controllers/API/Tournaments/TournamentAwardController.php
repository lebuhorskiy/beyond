<?php

namespace App\Http\Controllers\API\Tournaments;

use App\Http\Controllers\Controller;
use App\Models\Tournaments\TournamentAward;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TournamentAwardController extends Controller
{

    /**
     * Get limited awards
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getAwards(Request $request): JsonResponse
    {
        if ($request->limit >= config('api.max_per_page')) {
            $request->limit = config('api.max_per_page');
        }
        $data = TournamentAward::query()
            ->paginate($request->limit, ['*'], 'page', $request->page);
        return response()->json(['data' => $data]);
    }

    /**
     * Get award by id
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getAward(Request $request): JsonResponse
    {
        $data = TournamentAward::query()
            ->where('uuid', '=', [$request->uuid])
            ->first();
        return response()->json(['data' => $data]);
    }


}
