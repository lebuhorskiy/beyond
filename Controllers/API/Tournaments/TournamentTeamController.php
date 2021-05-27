<?php

namespace App\Http\Controllers\API\Tournaments;

use App\Http\Controllers\Controller;
use App\Models\Tournaments\TournamentTeam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TournamentTeamController extends Controller
{

    /**
     * Get limited tournament teams
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getTeams(Request $request): JsonResponse
    {
        $data = TournamentTeam::query()
            ->where('tournament_id',$request->id)
            ->with(['players','players.team'])
            ->get();
        return response()->json(['data' => $data]);
    }





}
