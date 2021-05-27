<?php

namespace App\Http\Controllers\API\Tournaments;

use App\Http\Controllers\Controller;
use App\Models\Tournaments\TournamentParticipant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TournamentParticipantController extends Controller
{

    /**
     * Get limited tournament participants
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getParticipants(Request $request): JsonResponse
    {
        if($request->limit >= config('api.max_per_page')){
            $request->limit = config('api.max_per_page');
        }
        $data = TournamentParticipant::query()
            ->with(['team','user'])
            ->paginate($request->limit, ['*'], 'page', $request->page);
        return response()->json(['data' => $data]);
    }

    /**
     * Get tournament participant by id
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getParticipant(Request $request): JsonResponse
    {
        $data = TournamentParticipant::query()
            ->where('uuid', '=', [$request->uuid])
            ->with(['tournament','team','user'])
            ->first();
        return response()->json(['data' => $data]);
    }

}
