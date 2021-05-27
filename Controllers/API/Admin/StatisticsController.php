<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Hubs\Fortnite\FortniteSoloMatch;
use App\Models\Hubs\Match;
use App\Models\Missions\Mission;
use App\Models\Missions\MissionToUser;
use App\Models\Users\User;
use App\Models\Users\UserSocialAccount;
use Illuminate\Http\JsonResponse;

class StatisticsController extends Controller
{

    /**
     * Get users registrations
     *
     * @return JsonResponse
     */
    public function getRegistrations(): ?JsonResponse
    {
        return response()->json([
            'total' => User::all()->count()
        ]);
    }

    /**
     * Get users amount with verified email
     *
     * @return JsonResponse
     */
    public function getVerifiedEmails(): ?JsonResponse
    {
        return response()->json([
            'total' => User::where('email_verified_at', '!=', null)->count()
        ]);
    }

    /**
     * Get users amount with connected gaming accounts
     *
     * @return JsonResponse
     */
    public function getPlatformsConnected(): ?JsonResponse
    {
        return response()->json([
            'platforms' => [
                0 => [
                        'name' => 'epic-games',
                        'total' => User::where('epic_id', '!=', null)->count(),
                ],
                1 => [
                        'name' => 'origin',
                        'total' => User::where('origin_id', '!=', null)->count(),
                ],
                2 => [
                        'name' => 'steam',
                        'total' => UserSocialAccount::where('provider', '=', 'steam')->count(),
                ],
                3 => [
                        'name' => 'battlenet',
                        'total' => UserSocialAccount::where('provider', '=', 'battlenet')->count(),
                ]
            ]
        ]);
    }

    /**
     * Get users amount with connected social accounts
     *
     * @return JsonResponse
     */
    public function getSocialsConnected(): ?JsonResponse
    {
        return response()->json([
            'socials' => [
                0 => [
                        'name' => 'vkontakte',
                        'total' => UserSocialAccount::where('provider', '=', 'vkontakte')->count(),
                ],
                1 => [
                        'name' => 'discord',
                        'total' => UserSocialAccount::where('provider', '=', 'discord')->count(),
                ],
                2 => [
                        'name' => 'twitch',
                        'total' => UserSocialAccount::where('provider', '=', 'twitch')->count(),
                ]
            ]
        ], 200);
    }

    /**
     * Get total box-fights played
     *
     * @return JsonResponse
     */
    public function getBoxFights(): ?JsonResponse
    {
        $oldMatches = Match::where('type_game', '=', 'box-fights')
                        ->where('status', '=', 'completed')
                        ->count();
        $newMatches = FortniteSoloMatch::where('status', '=', 'completed')
                        ->count();

        return response()->json([
            'total' => $oldMatches + $newMatches
        ]);
    }

    /**
     * Get total zone-wars played
     *
     * @return JsonResponse
     */
    public function getZoneWars(): ?JsonResponse
    {
        $matches = Match::where('type_game', '=', 'zone-wars')
                        ->where('status', '=', 'completed')
                        ->count();

        return response()->json([
            'total' => $matches
        ]);
    }

    /**
     * Get total missions played for each game
     *
     * @return JsonResponse
     */
    public function getMissions(): ?JsonResponse
    {
        $games = [
            0 => 'csgo',
            1 => 'dota2',
            2 => 'fortnite',
            3 => 'lol',
            4 => 'tft',
            5 => 'pubgm',
            6 => 'freefire',
            7 => 'cod',
            8 => 'valorant',
            9 => 'apex'
        ];

        $data = [];

        foreach($games as $key => $game) {
            $missions = Mission::where('game', '=', $game)->where('status', '=', 'completed')->get();

            $data[$key]['players'] = 0;

            foreach ($missions as $mission) {
                $data[$key]['players'] += MissionToUser::where('mission_id', '=', $mission->id)->count();
            }

            $data[$key]['game'] = $game;
            $data[$key]['total'] = $missions->count();
        }

        return response()->json([
            'missions' => $data,
        ]);
    }
}
