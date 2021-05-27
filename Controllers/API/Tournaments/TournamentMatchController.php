<?php

namespace App\Http\Controllers\API\Tournaments;

use App\Http\Controllers\Controller;
use App\Models\Tournaments\TournamentMatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TournamentMatchController extends Controller
{

    /**
     * Get tournament match by id
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getMatches(Request $request, $id): JsonResponse
    {
        $data = TournamentMatch::query();
        if (isset($request->finish) and !empty($request->finish)) {
            $data = $data->whereNotNull('finish_at');
        } else {
            $data = $data->whereNull('finish_at');
        }
        $data = $data->with(['results.player', 'results.player_vs'])
            ->whereHas('tournament', function ($query) use ($id) {
                $query->where('id', '=', $id);
            })
            ->get();
        return response()->json(['data' => $data]);
    }


}
