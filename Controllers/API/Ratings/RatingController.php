<?php

namespace App\Http\Controllers\API\Ratings;

use App\Http\Controllers\API\SeasonPass\SeasonPassController;
use App\Http\Controllers\Controller;
use App\Models\Players\SeasonStats;
use App\Models\Season;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;


class RatingController extends Controller
{

    public function getCurrentSeason()
    {
        return Season::where('start', '<', Carbon::now())->where('end', '>', Carbon::now())->first();
    }

    public function getCurrentSeasonJSON(): JsonResponse
    {
        return response()->json([
            'current' => Season::where('start', '<', Carbon::now())->where('end', '>', Carbon::now())->first(),
            'seasons' => Season::orderByDesc('end')->get()
        ]);
    }

    /**
     * Get Missions rating
     *
     * @param string $category
     * @param int|string $season
     * @param string $game
     * @return JsonResponse
     * @throws Exception
     */
    public function getRatingMissions(string $category, $season, string $game) : JsonResponse
    {
//        cache()->forget("rating.missions.$season.$game.$category");
//        $cache = cache()->remember("rating.missions.$season.$game.$category", $this->cache_for, function () use ($category, $season, $game) {
            $response = [
                'data' => [],
                'cache_time' => null
            ];

            $SP = (new SeasonPassController())->getCurrentSeasonPass();

            $response['data'] = SeasonStats::with('user')
                ->join('season_pass_to_users', 'season_pass_to_users.user_id', '=', 'season_stats.user_id')
                ->where('season_pass_to_users.season_pass_id', '=', $SP->id)
                ->where('season_pass_to_users.paid', '=', $category === 'season_pass')
                ->where('season_stats.season_id', '=', $this->getCurrentSeason()->id)
                ->where('season_stats.section', '=', 'missions')
                ->where('season_stats.game', '=', $game)
                ->orderByDesc('season_stats.rating')
                ->select('season_stats.*')
                ->limit(100)
                ->get();

//            return $response;
//        });

        return response()->json($response);
    }

    /**
     * Get Missions rating
     *
     * @param string $category
     * @param int|string|null $season
     * @param string|null $game
     * @param string $type
     * @return JsonResponse
     * @throws Exception
     */
    public function getRatingHubs(string $category, $season, string $game, string $type) : JsonResponse
    {
//        $cache = cache()->remember("rating.hubs.$season.$game.$category.$type", $this->cache_for, function () use ($category, $season, $game, $type) {
            $response = [
                'data' => [],
                'cache_time' => null
            ];

            $SP = (new SeasonPassController())->getCurrentSeasonPass();

            $response['data'] = SeasonStats::with('user')
                ->join('season_pass_to_users', 'season_pass_to_users.user_id', '=', 'season_stats.user_id')
                ->where('season_pass_to_users.season_pass_id', '=', $SP->id)
                ->where('season_pass_to_users.paid', '=', $category === 'season_pass')
                ->where('season_stats.season_id', '=', $this->getCurrentSeason()->id)
                ->where('season_stats.section', '=', $type)
                ->orderByDesc('season_stats.rating')
                ->limit(100)
                ->get();

//            return $response;
//        });

        return response()->json($response);
    }
}
