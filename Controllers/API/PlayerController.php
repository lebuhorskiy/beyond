<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Hubs\Fortnite\FortniteSoloMatchToUser;
use App\Models\Hubs\Match;
use App\Models\Hubs\MatchToUser;
use App\Models\Hubs\Message;
use App\Models\Missions\MissionToUser;
use App\Models\Users\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlayerController extends Controller
{
    public $user_id;

    /**
     * Player's general api
     *
     * @param $uuid
     * @return JsonResponse
     */
    public function general($uuid): JsonResponse
    {
        $user = User::with('integrations')->whereUuid($uuid)->first();

        if(!$user) {
            return response()->json([
                'error' => __('messages.player_not_found'),
                'success' => false
            ], $this->notFoundStatus);
        }

        return response()->json([
            'user' => $user,
            'success' => true
        ]);
    }

    /**
     * Player's profile api
     *
     * @param $uuid
     * @return JsonResponse
     */
    public function profile($uuid): JsonResponse
    {
        $user = User::with('country', 'twitch')->whereUuid($uuid)->first();

        if(!$user) {
            return response()->json([
                'error' => __('messages.player_not_found'),
                'success' => false
            ], $this->notFoundStatus);
        }

        return response()->json([
            'user' => $user,
            'success' => true
        ]);
    }

    /**
     * Player's statistics api
     *
     * @param $uuid
     * @return JsonResponse
     */
    public function statistics($uuid): JsonResponse
    {
        $user = User::whereUuid($uuid)->first();

        if(!$user) {
            return response()->json([
                'error' => __('messages.player_not_found'),
                'success' => false
            ], $this->notFoundStatus);
        }

        $this->user_id = $user->id;

        $matches = MatchToUser::join('matches', 'matches.id', '=', 'match_to_users.match_id')
            ->where('user_id', $user->id)
            ->where([
                'accepted' => 1,
                'leaver' => false,
                'matches.status' => 'completed'
            ])
            ->select(
                'matches.id as id',
                'matches.discipline as discipline',
                'matches.type_game as type',
                'matches.division as division',
                'matches.user_win as user_win',
                'matches.user_lose as user_lose',
                'matches.is_team_fight as is_team_fight',
                'matches.first_team as first_team',
                'matches.second_team as second_team',
                'matches.team_win as team_win',
                'matches.team_lose as team_lose'
            )
            ->get();

        $arrayStats = [];
        $statistics = [
            'missions' => [
                'total' => 0,
                'completed' => 0,
                'failed' => 0
            ],
            'fortnite' => [
                'box-fights' => [
                    'free' => [
                        'total' => 0,
                        'wins' => 0,
                        'loses' => 0
                    ],
                    'season_pass' => [
                        'total' => 0,
                        'wins' => 0,
                        'loses' => 0
                    ],
                    'pro' => [
                        'total' => 0,
                        'wins' => 0,
                        'loses' => 0
                    ],
                ],
                'zone-wars' => [
                    'free' => [
                        'total' => 0,
                        'wins' => 0,
                        'loses' => 0
                    ],
                    'season_pass' => [
                        'total' => 0,
                        'wins' => 0,
                        'loses' => 0
                    ],
                    'pro' => [
                        'total' => 0,
                        'wins' => 0,
                        'loses' => 0
                    ],
                ],
                'customs' => [
                    'free' => [
                        'total' => 0,
                        'wins' => 0,
                        'loses' => 0
                    ],
                    'season_pass' => [
                        'total' => 0,
                        'wins' => 0,
                        'loses' => 0
                    ],
                    'pro' => [
                        'total' => 0,
                        'wins' => 0,
                        'loses' => 0
                    ],
                ]
            ]
        ];

        $statistics['missions'] = [
            'total' => MissionToUser::join('missions', 'missions.id', '=', 'mission_to_users.mission_id')
                ->where('mission_to_users.user_id', '=', $user->id)
                ->where('missions.status', '=', 'completed')
                ->count(),
            'completed' => MissionToUser::join('missions', 'missions.id', '=', 'mission_to_users.mission_id')
                ->where('mission_to_users.user_id', '=', $user->id)
                ->where('missions.status', '=', 'completed')
                ->where('mission_to_users.completed', true)
                ->count(),
            'failed' => MissionToUser::join('missions', 'missions.id', '=', 'mission_to_users.mission_id')
                ->where('mission_to_users.user_id', '=', $user->id)
                ->where('missions.status', '=', 'completed')
                ->where('mission_to_users.completed', false)
                ->count()
        ];

        foreach ($matches as $stat) {
            $arrayStats[$stat['discipline']][$stat['type']][$stat['division']][] = $stat;
        }

        foreach ($arrayStats as $discipline => $types) {
            foreach ($types as $type => $divisions) {
                foreach ($divisions as $division => $match) {
                    foreach ($match as $data) {
                        if($type !== 'box-fights') {
                            $all_podium_winners = Match::find($data['id'])->all_podium_winners->toArray();

                            $isInPodium = array_filter($all_podium_winners, function ($item) {
                                return $item['user_id'] === $this->user_id;
                            });

                            $win = count($isInPodium) === 1 ? 1 : 0;
                            $lose = count($isInPodium) === 0 ? 1 : 0;

                            $statistics[$discipline][$type][$division]['total'] =
                                isset($statistics[$discipline][$type][$division]['total']) ?
                                    ($statistics[$discipline][$type][$division]['total'] + 1) :
                                    1;

                            $statistics[$discipline][$type][$division]['wins'] =
                                isset($statistics[$discipline][$type][$division]['wins']) ?
                                    ($statistics[$discipline][$type][$division]['wins'] + $win) :
                                    $win;

                            $statistics[$discipline][$type][$division]['loses'] =
                                isset($statistics[$discipline][$type][$division]['loses']) ?
                                    ($statistics[$discipline][$type][$division]['loses'] + $lose) :
                                    $lose;
                        } else {
                            if($data['is_team_fight']) {
                                $first_team = json_decode($data['first_team'], true, 512, JSON_THROW_ON_ERROR);
                                $second_team = json_decode($data['second_team'], true, 512, JSON_THROW_ON_ERROR);

                                $isInFirstTeam = array_filter($first_team, function ($item) {
                                    return $item['user']['id'] === $this->user_id;
                                });

                                $isInSecondTeam = [];

                                if(count($isInFirstTeam) === 0) {
                                    $isInSecondTeam = array_filter($second_team, function ($item) {
                                        return $item['user']['id'] === $this->user_id;
                                    });
                                }

                                $win = (count($isInFirstTeam) && $data['team_win'] === 1 || count($isInSecondTeam) && $data['team_win'] === 2) ? 1 : 0;
                                $lose = (count($isInFirstTeam) && $data['team_lose'] === 1 || count($isInSecondTeam) && $data['team_lose'] === 2) ? 1 : 0;

                                $statistics[$discipline][$type][$division]['total'] =
                                    isset($statistics[$discipline][$type][$division]['total']) ?
                                        ($statistics[$discipline][$type][$division]['total'] + 1) :
                                        1;

                                $statistics[$discipline][$type][$division]['wins'] =
                                    isset($statistics[$discipline][$type][$division]['wins']) ?
                                        ($statistics[$discipline][$type][$division]['wins'] + $win) :
                                        $win;

                                $statistics[$discipline][$type][$division]['loses'] =
                                    isset($statistics[$discipline][$type][$division]['loses']) ?
                                        ($statistics[$discipline][$type][$division]['loses'] + $lose) :
                                        $lose;
                            } else {
                                $win = ($data['user_win'] === $this->user_id) ? 1 : 0;
                                $lose = ($data['user_lose'] === $this->user_id) ? 1 : 0;

                                $statistics[$discipline][$type][$division]['total'] =
                                    isset($statistics[$discipline][$type][$division]['total']) ?
                                        ($statistics[$discipline][$type][$division]['total'] + 1) :
                                        1;

                                $statistics[$discipline][$type][$division]['wins'] =
                                    isset($statistics[$discipline][$type][$division]['wins']) ?
                                        ($statistics[$discipline][$type][$division]['wins'] + $win) :
                                        $win;

                                $statistics[$discipline][$type][$division]['loses'] =
                                    isset($statistics[$discipline][$type][$division]['loses']) ?
                                        ($statistics[$discipline][$type][$division]['loses'] + $lose) :
                                        $lose;
                            }
                        }
                    }
                }
            }
        }

        $soloBoxFights = FortniteSoloMatchToUser::join('fortnite_solo_matches', 'fortnite_solo_matches.id', '=', 'fortnite_solo_match_to_users.match_id')
            ->where('fortnite_solo_match_to_users.user_id', $user->id)
            ->where('fortnite_solo_matches.status', 'completed')
            ->get();

        foreach ($soloBoxFights as $solobf) {
            $statistics['fortnite']['box-fights'][$solobf->division]['total'] += 1;
            if($solobf->winner_id === $user->id) {
                $statistics['fortnite']['box-fights'][$solobf->division]['wins'] += 1;
            } else {
                $statistics['fortnite']['box-fights'][$solobf->division]['loses'] += 1;
            }
        }

        return response()->json([
            'statistics' => $statistics,
            'success' => true
        ]);
    }
}
