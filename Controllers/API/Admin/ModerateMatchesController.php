<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Hubs\Fortnite\FortniteSoloMatch;
use Illuminate\Http\JsonResponse;

class ModerateMatchesController extends Controller
{

    /**
     * Get list of matches needed in moderate
     *
     * @param $mode
     * @return JsonResponse
     */
    public function getMatchesList($mode): JsonResponse
    {
        $matches = [];

        if($mode === 'box_fights') {
            $matches = FortniteSoloMatch::with('first_player', 'second_player')
                ->whereNotIn('status', ['completed', 'canceled', 'revoked', 'archived'])
                ->where('needs_to_moderate', '=', true)
                ->orderBy('created_at', 'desc')
                ->get();
        }

        return response()->json([
            'matches' => $matches
        ]);
    }



}
