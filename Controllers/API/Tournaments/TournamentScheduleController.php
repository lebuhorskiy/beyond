<?php

namespace App\Http\Controllers\API\Tournaments;

use App\Http\Controllers\Controller;
use App\Models\Tournaments\TournamentSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TournamentScheduleController extends Controller
{

    /**
     * Get limited tournament schedules
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getSchedules(Request $request): JsonResponse
    {
        $data = TournamentSchedule::query()->where('tournament_id','=',$request->id)->get();
        return response()->json(['data' => $data]);
    }


}
