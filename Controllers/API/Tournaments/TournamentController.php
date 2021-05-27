<?php

namespace App\Http\Controllers\API\Tournaments;

use App\Http\Controllers\Controller;
use App\Models\Tournaments\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TournamentController extends Controller
{

    /**
     * Get limited tournaments
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getTournaments(Request $request): JsonResponse
    {
        if ($request->limit >= config('api.max_per_page')) {
            $request->limit = config('api.max_per_page');
        }
        $data = Tournament::query()
            ->with([
                'awards',
            ])
            ->paginate($request->limit, ['*'], 'page', $request->page);
        return response()->json(['data' => $data]);
    }

    /**
     * Get tournament
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getTournament(Request $request): JsonResponse
    {
        $data = Tournament::query()
            ->where('uuid', '=', [$request->uuid])
            ->with([
                'awards',
                'matches.user',
                'participants.user',
                'participants.team',
                'participants.team.players',
                'participants.team.players.team',
                'schedules',
               // 'brackets.children',
                'matches.results',
                // 'matches.results.match',
            ])
            ->first();
        return response()->json(['data' => $data]);
    }


}
