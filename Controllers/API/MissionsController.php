<?php

namespace App\Http\Controllers\API;

use App\Events\Missions\NewMission;
use App\Events\Missions\UpdateMissionParticipants;
use App\Events\Notifications\NewNotification;
use App\Http\Controllers\API\Ratings\RatingController;
use App\Http\Controllers\API\SeasonPass\SeasonPassController;
use App\Http\Controllers\Controller;
use App\Models\Missions\Mission;
use App\Models\Missions\MissionFormat;
use App\Models\Missions\MissionMode;
use App\Models\Missions\MissionToUser;
use App\Models\Users\User;
use App\Models\Users\UserNotification;
use Carbon\Carbon;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MissionsController extends Controller
{

    /**
     * Get missions by discipline api
     *
     * @return JsonResponse
     */
    public function getActiveByUser(): JsonResponse
    {
        $response = Mission::join('mission_to_users', 'mission_to_users.mission_id', '=', 'missions.id')
            ->where('mission_to_users.user_id', '=', auth('api')->user()->id)
            ->where(function ($query) {
                $query->whereIn('missions.status', ['open', 'active']);
            })
            ->orderBy('missions.closed_at')
            ->select('missions.*')
            ->get();

        return response()->json($response);
    }

    /**
     * Get missions by discipline api
     *
     * @param $discipline
     * @return JsonResponse
     */
    public function getByDiscipline($discipline): JsonResponse
    {
        $response = Mission::with('format', 'mode')
            ->where('game', '=', $discipline)
            ->orderBy('closed_at', 'desc')
            ->get();

        return response()->json($response);
    }

    /**
     * Get missions or single api
     *
     * @param $uuid
     * @return JsonResponse
     */
    public function getById($uuid): JsonResponse
    {
        $response = Mission::with('format', 'mode')
            ->where('uuid', '=', $uuid)
            ->first();

        return response()->json($response);
    }

    public function getByCategory($discipline, $category): JsonResponse
    {
        $response = [];
        switch ($category) {
            // User's accepted missions category
            case 'my':
                if(auth('api')->id()) {
                    $response = Mission::with('format', 'mode')
                        ->join('mission_to_users', 'mission_to_users.mission_id', '=', 'missions.id')
                        ->where('game', '=', $discipline)
                        ->where('user_id', '=', auth('api')->user()->id)
                        ->orderBy('accepted_at')
                        ->select('missions.*', 'mission_to_users.completed as completed', 'mission_to_users.accepted_at as accepted_at')
                        ->get();
                }
                break;
            // All missions category
            case 'all':
                $response = Mission::with('format', 'mode')
                    ->where([
                        ['game', '=', $discipline],
                        ['status', '=', 'open'],
                        ['closed_at', '>', Carbon::now()],
                    ])
                    ->orderBy('closed_at')
                    ->get();
                break;
        }
        return response()->json([
            'missions' => $response,
            'next_update_time' => ($response && $response->count()) ? Carbon::parse($response[$response->count() - 1]->closed_at) : Carbon::now()->addMinutes(90)
        ]);
    }

    /**
     * Get mission by filter's values
     *
     * @param Request $filters
     * @return JsonResponse
     */
    public function getByFilters(Request $filters): JsonResponse
    {
        $response = Mission::with('format', 'mode')
            ->where('game', '=', $filters->get('game'))
            ->where('status', '=', 'open');
        $formats = [];
        $categories = [];
        $difficulties = [];
        foreach ($filters->all() as $key => $val) {

            // Mission formats
            if(in_array($key, ['solo', 'duo']) && $val) {
                $formats[] = MissionFormat::where('format', '=', $key)->first()->id;
            }

            // Mission categories
            if(in_array($key, ['free', 'private', 'legendary', 'season_pass']) && $val) {
                $categories[] = $key;
            }

            // Mission difficulties
            if(in_array($key, ['easy', 'middle', 'hard', 'very_hard']) && $val) {
                $difficulties[] = $key;
            }

        }

        // Formats
        if(count($formats)) {
            $response->whereIn('format_id', $formats);
        }
        // Categories
        if(count($categories)) {
            $response->whereIn('category', $categories);
        }
        // Difficulties
        if(count($difficulties)) {
            $response->whereIn('difficult', $difficulties);
        }
        return response()->json($response->orderBy('closed_at')->get());
    }

    /**
     * Create mission api
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function create(Request $request): JsonResponse
    {
        $format = MissionFormat::where('format', '=', $request->get('format'))->first();
        $mode = MissionMode::where('type', '=', $request->get('mode'))->first();

        $mission = Mission::create([
            'game' => $request->get('game'),
            'mode_id' => $mode->id,
            'format_id' => $format->id,
            'category' => $request->get('category'),
            'difficult' => $request->get('difficult'),
            'title' => [
                'ru' => $request->get('title_ru'),
                'en' => $request->get('title_en')
            ],
            'description' => [
                'ru' => $request->get('description_ru'),
                'en' => $request->get('description_en')
            ],
            'required_action' => $request->get('required_action'),
            'required_times' => $request->get('required_times'),
            'buy_in' => $request->get('buy_in'),
            'payment' => $request->get('payment'),
            'reward' => $request->get('reward'),
            'participants_min' => $request->get('participants_min') ?? 1,
            'participants_max' => $request->get('participants_max'),
            'participants_current' => $request->get('participants_current'),
            'duration' => (int)$request->get('duration') * 60,
            'published_at' => $request->get('published_at') ?? now(),
            'closed_at' => $request->get('closed_at'),
            'status' => 'open',
            'visibility' => $request->get('visibility')
        ]);

        event(new NewMission($mission, $mission->game));

        return response()->json(['message' => 'Миссия успешно добавлена!']);
    }

    /**
     * Update mission api
     *
     * @param $id
     * @param Request $request
     * @return JsonResponse
     */
    public function update($id, Request $request): JsonResponse
    {
        $mission = Mission::find($id);
        $mission->update([
            'title' => [
                'ru' => $request->get('title_ru'),
                'en' => $request->get('title_en')
            ],
            'description' => [
                'ru' => $request->get('description_ru'),
                'en' => $request->get('description_en')
            ],
            'required_action' => $request->get('required_action'),
            'required_times' => $request->get('required_times'),
            'buy_in' => $request->get('buy_in'),
            'reward' => $request->get('reward'),
            'participants_min' => $request->get('participants_min'),
            'participants_max' => $request->get('participants_max'),
            'duration' => ($request->get('duration') === '') ? null : (int)$request->get('duration') * 60,
            'closed_at' => $request->get('closed_at'),
        ]);

        return response()->json(['message' => 'Миссия успешно обновлена!']);
    }

    /**
     * Delete mission api
     *
     * @param $id
     * @return JsonResponse
     */
    public function delete($id): JsonResponse
    {
        $mission = Mission::find($id);
        foreach (MissionToUser::where('mission_id', '=', $mission->id)->get() as $player) {
            $player->delete();
        }
        $mission->delete();
        return response()->json(['message' => 'Миссия успешно удалена!']);
    }

    /**
     * Link mission to user api
     *
     * @param Request $request
     * @return JsonResponse
     * @throws GuzzleException
     */
    public function link(Request $request): JsonResponse
    {
        $bonus = $request->get('use_bonus') ?? false;
        $mission_id = (int)$request->post('mission_id');

        $mission = Mission::whereId($mission_id)->first();
        $user = User::find(auth()->id());

        if(MissionToUser::where('mission_id', $mission->id)->where('user_id', $user->id)->exists()) {
            return response()->json([
                'message' => __('messages.already_join_mission'),
                'success' => false,
            ], $this->errorStatus);
        }

//        $isSameMissionPlayed = MissionToUser::join('missions', 'missions.id', '=', 'mission_to_users.mission_id')
//            ->where('mission_to_users.user_id', '=', auth()->id())
//            ->where('missions.format_id', '=', $mission->format_id)
//            ->where('missions.mode_id', '=', $mission->mode_id)
//            ->where('missions.game', '=', $mission->game)
//            ->where('missions.difficult', '=', $mission->difficult)
//            ->where('missions.category', '=', $mission->category)
//            ->where('missions.reward', '=', $mission->reward)
//            ->where('missions.buy_in', '=', $mission->buy_in)
//            ->where('missions.required_action', '=', $mission->required_action)
//            ->where('missions.required_times', '=', $mission->required_times)
//            ->where('missions.closed_at', '>=', Carbon::parse($mission->closed_at)->subDay())
//            ->orderBy('missions.closed_at', 'desc')
//            ->first();
//
//        // Check if player has accepted same type of mission within a day
//        if($isSameMissionPlayed) {
//            return response()->json([
//                'message' => __('messages.same_mission_error'),
//                'success' => false,
//            ], 400);
//        }

        if($mission->game === 'fortnite') {
            // Check if player has open private privacy account stats
            if(!$user->epic_id) {
                return response()->json([
                    'message' => __('messages.epic_id_not_set'),
                    'success' => false,
                ], 420);
            }

            if(!$this->checkEpicPrivacy($user->epic_id)) {
                return response()->json([
                    'message' => __('messages.epic_account_privacy_error'),
                    'success' => false,
                ], 420);
            }
        }

        if($mission->game === 'dota2') {
            // Check if player has open private privacy account stats
            if(!$user->steam_id_32) {
                return response()->json([
                    'message' => __('messages.steam_id_not_set'),
                    'success' => false,
                ], 421);
            }

            if(!$this->checkSteamPrivacy($user->steam_id_32)) {
                return response()->json([
                    'message' => __('messages.steam_account_privacy_error'),
                    'success' => false,
                ], 421);
            }
        }

        if($mission->participants_max !== null && $mission->participants_current >= $mission->participants_max) {
            return response()->json([
                'message' => __('messages.error_limits_mission'),
                'success' => false,
            ], 400);
        }

        auth()->user()->seasonStats()
            ->firstOrCreate(
                [
                    'season_id' => (new RatingController())->getCurrentSeason()->id,
                    'section' => 'missions',
                    'game' => $mission->game
                ],
                [
                    'wins' => 0,
                    'loses' => 0,
                    'total' => 0,
                    'rating' => 1000,
                    'missions' => [
                        'legendary' => 0,
                        'private' => 0,
                        'free' => 0,
                        'season_pass' => 0,
                    ]
                ]);


        if ($mission->category === 'free') {
            try {
                $assigned = $this->assignUserAndUpdateParticipants($mission, $user, $bonus);

                if($assigned) {

                    event(new UpdateMissionParticipants($mission->participants_current, $mission->id, $mission->game));

                    // Create and send notification to user
                    $notification = UserNotification::create([
                        'user_id' => auth()->id(),
                        'category' => 'missions',
                        'title' => [
                            'ru' => 'Вы приняли миссию',
                            'en' => "You've had new mission"
                        ],
                        'text' => [
                            'ru' => 'Вы успешно зарегистрировались в миссии! Желаем успешного прохождения!',
                            'en' => "You've had successfully joined mission! Wish you luck with completing!"
                        ],
                    ]);

                    event(new NewNotification(auth()->user()->uuid, $notification));

                    return response()->json([
                        'message' => __('messages.success_link_mission_to_user'),
                        'success' => true,
                        'balance_points' => $user->balance_points
                    ]);
                }
            } catch (\Exception $e) {
                Log::error($e->getMessage());
                return response()->json([
                    'message' => __('messages.error_link_mission_to_user'),
                    'success' => false,
                ], $this->errorStatus);
            } catch (GuzzleException $e) {
                Log::error($e->getMessage());
            }
        }

        if($mission->category === 'legendary') {
            try {
                $bonuses = $user->bonuses;
                if (isset($bonuses->data['legendary_mission'])) {
                    if ($bonuses->data['legendary_mission'] >= 1) {
                        $user->bonuses()->update([
                            'data->legendary_mission' => $bonuses->data['legendary_mission'] - 1
                        ]);

                        $this->assignUserAndUpdateParticipants($mission, $user, false);

                        return response()->json([
                            'balance_points' => $user->balance_points,
                            'message' => __('messages.success_link_mission_to_user'),
                            'success' => true
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::error($e->getMessage());
                return response()->json([
                    'message' => __('messages.error_link_mission_to_user'),
                    'success' => false,
                ], $this->errorStatus);
            }
        }

        if ($mission->category === 'private') {
            $balance_points = (int)$user->balance_points;
            $mission_points = (int)$mission->buy_in;

            // For private missions
            if ($balance_points >= $mission_points || $bonus) {
                try {
                    if(!$bonus) {
                        $user->update(['balance_points' => ($balance_points - $mission_points)]);
                    } else {
                        $bonuses = $user->bonuses;
                        if(isset($bonuses->data['private_mission'])) {
                            if($bonuses->data['private_mission'] >= 1) {
                                $user->bonuses()->update([
                                    'data->private_mission' => $bonuses->data['private_mission'] - 1
                                ]);
                            } else {
                                return response()->json([
                                    'message' => __('messages.error_link_mission_to_user'),
                                    'success' => false,
                                ], $this->errorStatus);
                            }
                        } else {
                            return response()->json([
                                'message' => __('messages.error_link_mission_to_user'),
                                'success' => false,
                            ], $this->errorStatus);
                        }
                    }

                    $this->assignUserAndUpdateParticipants($mission, $user, $bonus);

                    return response()->json([
                        'balance_points' => $user->balance_points,
                        'message' => __('messages.success_link_mission_to_user'),
                        'success' => true
                    ]);
                } catch (\Exception $e) {
                    Log::error($e->getMessage());
                    return response()->json([
                        'message' => __('messages.error_link_mission_to_user'),
                        'success' => false,
                    ], $this->errorStatus);
                }
            }
            return response()->json([
                'message' => __('messages.error_link_mission_to_user'),
                'success' => false,
            ], 502);
        }
        return response()->json([
            'message' => __('messages.error_link_mission_to_user'),
            'success' => false,
        ], $this->errorStatus);
    }

    /**
     * Unlink missions to user api
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function unlink(Request $request): JsonResponse
    {
        try {
            $mission_id = $request->mission_id;

            $mission = Mission::find($mission_id);
            $user = User::find(auth()->id());

            if($mission->status !== 'open') {
                return response()->json([
                    'message' => __('messages.mission_is_active_error'),
                    'success' => false,
                ], $this->errorStatus);
            }

            $mtu = MissionToUser::where('user_id', '=', $user->id)
                ->where('mission_id', '=', $mission_id)
                ->first();

            if(!$mtu) {
                return response()->json([
                    'message' => __('messages.already_unlinked_mission'),
                    'success' => false,
                ], $this->errorStatus);
            }

            if($mission->category == 'private') {
                if(!$mtu->bonus) {
                    $user->update(['balance_points' => ($user->balance_points + $mission->buy_in)]);
                } else {
                    $user->bonuses()->update([
                        'data->private_mission' => $user->bonuses->data['private_mission'] + 1
                    ]);
                }
            }
            if($mission->category == 'legendary') {
                $user->bonuses()->update([
                    'data->legendary_mission' => $user->bonuses->data['legendary_mission'] + 1
                ]);
            }

            $mtu->delete();

            $newCurrent = $mission->participants_current - 1;

            $mission->update([
                'participants_current' => $newCurrent,
                'status' => ($newCurrent < $mission->participants_max) ? 'open' : $mission->status
            ]);

            event(new UpdateMissionParticipants($newCurrent, $mission->id, $mission->game));

            return response()->json([
                'balance_points' => $user->balance_points,
                'message' => __('messages.success_unlink_mission_to_user'),
                'success' => true,
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return response()->json([
                'message' => __('messages.error_unlink_mission_to_user'),
                'success' => false,
            ], $this->errorStatus);
        }
    }

    /**
     * Check epic games account privacy for user
     *
     * @param $fid
     * @return JsonResponse|Boolean
     * @throws GuzzleException
     */
    public function checkEpicPrivacy($fid)
    {
        $fromApi = false;
        if(preg_match('/^api_/',$fid)) {
            $fromApi = true;
            $fid = str_replace('api_', '', $fid);
        }

        // Setting up API call with requested code
        $endpoint = 'http://185.107.96.187:44133/check/'.$fid;
        $client = new \GuzzleHttp\Client();

        $response = $client->request('GET', $endpoint);

        // Decode requested info into json object
        $content = json_decode($response->getBody(), false, 512, JSON_THROW_ON_ERROR);

        if ($content->result === 'OK') {
            if($fromApi) {
                return response()->json(['message' => 'Настройки приватности установлены правильно!', 'success' => true]);
            }
            return true;
        }

        if($fromApi) {
            return response()->json(['message' => 'Настройки приватности установлены не правильно!', 'success' => false]);
        }
        return false;
    }

    /**
     * Check epic games account privacy for user
     *
     * @param $s32id
     * @param Request $request
     * @return JsonResponse|Boolean
     * @throws GuzzleException
     */
    public function checkSteamPrivacy($s32id)
    {
        $fromApi = false;
        if(preg_match('/^api_/',$s32id)) {
            $fromApi = true;
            $s32id = str_replace('api_', '', $s32id);
        }

        // Setting up API call with requested code
        $endpoint = 'http://185.107.96.187:44129/check/'.$s32id;
        $client = new \GuzzleHttp\Client();

        $response = $client->request('GET', $endpoint);

        // Decode requested info into json object
        $content = json_decode($response->getBody(), false, 512, JSON_THROW_ON_ERROR);

        if ($content->result === 'OK') {
            if($fromApi) {
                return response()->json(['message' => 'Настройки приватности установлены правильно!', 'success' => true]);
            }
            return true;
        }

        if($fromApi) {
            return response()->json(['message' => 'Настройки приватности установлены не правильно!', 'success' => false], $this->errorStatus);
        }
        return false;
    }

    /**
     * Assign user to mission and then update counts
     *
     * @param Mission $mission
     * @param User $user
     * @param bool $bonus
     * @return bool
     * @throws GuzzleException
     */
    public function assignUserAndUpdateParticipants(Mission $mission, User $user, bool $bonus = false): bool
    {
        DB::beginTransaction();

        MissionToUser::create([
            'mission_id' => $mission->id,
            'user_id' => $user->id,
            'completed' => false,
            'accepted_at' => Carbon::now(),
            'bonus' => $bonus
        ]);

        $mission->update(['participants_current' => ($mission->participants_current + 1)]);

        if($mission->participants_max === $mission->participants_current || $mission->closed_at <= Carbon::now()) {
            if ($mission->game == 'fortnite') {
                $response = $this->sendInformationToApi($mission);

                if($response['status']) {
                    DB::commit();

                    $mission->update(['status' => 'active']);
                    return true;
                }

                DB::rollBack();
                return false;
            }

            if($mission->game === 'dota2') {

                $response = $this->sendInformationToApi($mission);

                if($response['status']) {
                    DB::commit();

                    $mission->update(['status' => 'active']);
                    return true;
                }

                DB::rollBack();
                return false;
            }

            DB::commit();

            $mission->update(['status' => 'active']);
            return true;
        }

        DB::commit();
        return true;
    }

    /**
     * Sending info with mission data and epic_ids to api
     *
     * @param Mission $mission
     * @param bool $resend_back_date
     * @return array
     * @throws GuzzleException
     */
    public function sendInformationToApi(Mission $mission, $resend_back_date = false): array
    {
        $data = [];
        switch ($mission->game) {
            case 'fortnite':
                $fids = $mission->fids();

                $arrayFids = [];
                foreach ($fids as $k => $v) {
                    if($v['epic_id'] !== null) {
                        $arrayFids[] = $v['epic_id'];
                    }
                }

                // Calculate format for tops.
                $format = $mission->mode->type;

                if($mission->mode->type === 'tops') {
                    // Solo Public/Arena
                    if($mission->format->format === 'solo' || $mission->format->format === 'ar_solo') $format = 'top' . $mission->required_action . 'prc';

                    // Duo Public/Arena
                    if($mission->format->format === 'duo' || $mission->format->format === 'ar_duo') {
                        if($mission->required_action === 5) $format = 'top10prc';
                        if($mission->required_action === 12) $format = 'top25prc';
                    }

                    // Squad Public/Arena & Trio Arena
                    if($mission->format->format === 'squad' || $mission->format->format === 'ar_squads' || $mission->format->format === 'ar_trios') {
                        if($mission->required_action === 3) $format = 'top10prc';
                        if($mission->required_action === 6) $format = 'top25prc';
                    }
                }


                $data = [
                    "mid" => $mission->uuid,
                    "fids" => $arrayFids,
                    "mode" => $mission->format->format,
                    "format" => $format,
                    "required" => $mission->required_times,
                    "timeout" => $mission->duration,
                ];

                $endpoint = 'http://185.107.96.187:44133/quest';
                break;
            case 'dota2':
                $s32ids = $mission->s32ids();

                $arrayS32ids = [];
                foreach ($s32ids as $k => $v) {
                    if($v['steam_id_32'] !== null) {
                        $arrayS32ids[] = $v['steam_id_32'];
                    }
                }

                $streak = 0;

                switch ($mission->mode->type) {
                    case 'win_hero_id_summ':
                        $streak = -1;
                        break;
                    case 'no_deaths_single':
                    case 'win_timelimit_single':
                        $streak = 1;
                        break;
                }

                $data = [
                    "mid" => $mission->uuid,
                    "s32ids" => $arrayS32ids,
                    "mode" => $mission->format->format,
                    "format" => $mission->mode->type,
                    "required" => $mission->required_times,
                    "timeout" => $mission->duration,
                    "streak" => ($streak !== 0) ? $streak : (($mission->required_action !== null) ? $mission->required_action : -1),
                ];

                $endpoint = 'http://185.107.96.187:44129/quest';
                break;
            case 'csgo':
                $s32ids = $mission->s32ids();

                $arrayS32ids = [];
                foreach ($s32ids as $k => $v) {
                    if($v['steam_id_32'] !== null) {
                        $arrayS32ids[] = $v['steam_id_32'];
                    }
                }

                $data = [
                    "mid" => $mission->uuid,
                    "s32ids" => $arrayS32ids,
                    "format" => $mission->mode->type,
                    "required" => $mission->required_times,
                    "timeout" => $mission->duration,
                ];

                $endpoint = 'http://185.107.96.187:44128/quest';
                break;
        }

        // Если нужно отправить задним числом
        if($resend_back_date) {
            $data["back_startdate"] = Carbon::parse($mission->closed_at)->timestamp;
        }

        $client = new \GuzzleHttp\Client();
        $response = $client->request('POST', $endpoint, ["body" => json_encode($data)]);

        $content = json_decode($response->getBody(), false, 512, JSON_THROW_ON_ERROR);

        return [
            "status" => $content->status,
            "message" => $content->message,
            "datas" => $content->datas,
            "broken_fids" => $content->broken_fids
        ];
    }

    /**
     * Awaits update status for mission
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateResult(Request $request): JsonResponse
    {
        try {
            $mission = Mission::where('uuid', '=', $request->get('mid'))->first();
            $results = $request->get('results');

            if($mission) {
                $mission->update(['status' => 'completed']);

                // Season pass level points
                $SPController = new SeasonPassController();

                foreach ($results as $item => $result) {
                    if($mission->game === 'fortnite') {
                        $missionToUser = MissionToUser::join('missions', 'missions.id', '=', 'mission_to_users.mission_id')
                            ->join('users', 'users.id', '=', 'mission_to_users.user_id')
                            ->where([
                                ['missions.uuid', '=', $request->get('mid')],
                                ['users.epic_id', '=', $item]
                            ])
                            ->select('mission_to_users.*')
                            ->first();
                    } elseif ($mission->game === 'dota2' || $mission->game === 'csgo') {
                        $missionToUser = MissionToUser::join('missions', 'missions.id', '=', 'mission_to_users.mission_id')
                            ->join('users', 'users.id', '=', 'mission_to_users.user_id')
                            ->where([
                                ['missions.uuid', '=', $request->get('mid')],
                                ['users.steam_id_32', '=', $item]
                            ])
                            ->select('mission_to_users.*')
                            ->first();
                    }

                    if($result[0] === null) {
                        $missionToUser->delete();
                    } else {
                        $missionToUser->update([
                            'completed' => $result[0],
                            'result' => $result[1],
                            'finished_at' => Carbon::now()
                        ]);

                        // Find user
                        $user = null;
                        if($mission->game === 'fortnite') {
                            $user = User::where('epic_id', '=', $item)->first();
                        } elseif ($mission->game === 'dota2' || $mission->game === 'csgo') {
                            $user = User::where('steam_id_32', '=', $item)->first();
                        }

                        $points = [
                            'free' => [
                                'win' => 10,
                                'lose' => 20
                            ],
                            'private' => [
                                'win' => 20,
                                'lose' => 10
                            ],
                            'legendary' => [
                                'win' => 25,
                                'lose' => 5
                            ],
                            'season_pass' => [
                                'win' => 30,
                                'lose' => 0
                            ],
                        ];

                        $stats = $user->seasonStats()
                            ->firstOrCreate(
                                [
                                    'season_id' => (new RatingController())->getCurrentSeason()->id,
                                    'section' => 'missions',
                                    'game' => $mission->game
                                ],
                                [
                                    'wins' => 0,
                                    'loses' => 0,
                                    'total' => 0,
                                    'rating' => 1000,
                                    'missions' => [
                                        'legendary' => 0,
                                        'private' => 0,
                                        'free' => 0,
                                        'season_pass' => 0,
                                    ]
                                ]);

                        if($result[0]) {

                            $multiplier = 1;

                            // If user has valid multiplier for mission reward
                            if(isset($user->bonuses->prizes['missions_boost']) && Carbon::parse($user->bonuses->prizes['missions_boost']['date_end']) >= Carbon::now()) {
                                $multiplier = $user->bonuses->prizes['missions_boost']['multiplier'];
                            }

                            $user->update([
                                'balance_points' => ((int)$user->balance_points + ((int)$mission->reward * $multiplier)),
                                'account_points' => ((int)$user->account_points + (25 * $multiplier))
                            ]);

                            // Win
                            $stats->update([
                                'wins' => DB::raw('wins + 1'),
                                'total' => DB::raw('total + 1'),
                                'rating' => $stats->rating + $points[$mission->category]['win'],
                                'missions->legendary' => $mission->category === 'legendary' ? ($stats->missions['legendary'] + 1) : $stats->missions['legendary'],
                                'missions->private' => $mission->category === 'private' ? ($stats->missions['private'] + 1) : $stats->missions['private'],
                                'missions->free' => $mission->category === 'free' ? ($stats->missions['free'] + 1) : $stats->missions['free'],
                                'missions->season_pass' => $mission->category === 'season_pass' ? ($stats->missions['season_pass'] + 1) : $stats->missions['season_pass'],
                            ]);

                            $SPController->updateUserPoints($user->id, 20);
                        } else {
                            $newRating = $stats->rating - $points[$mission->category]['lose'];
                            // Lose
                            $stats->update([
                                'loses' => DB::raw('loses + 1'),
                                'total' => DB::raw('total + 1'),
                                'rating' => $newRating >= 0 ? $newRating : 0,
                            ]);

                            $SPController->updateUserPoints($user->id, 10);
                        }

                        // Create notification to user
                        UserNotification::create([
                            'user_id' => $user->id,
                            'category' => 'missions',
                            'title' => [
                                'ru' => 'Миссия завершилась',
                                'en' => "Mission has been ended"
                            ],
                            'text' => [
                                'ru' => $result[0] ? 'Поздравляем! Миссия успешно выполнена, очки начислены на Ваш аккаунт!' : 'К сожалению Вы не выполнили миссию, попробуйте пройти еще!',
                                'en' => $result[0] ? 'Congrats! Mission has been successfully completed, points has been added to your account!' : "Unfortunately you've failed a mission, try again with another one!"
                            ],
                        ]);
                    }
                }

                return response()->json('');
            }
        } catch (\Exception $e) {
            Log::info('Ошибка с получением инфы с миссий');
            Log::info($e->getTraceAsString());

            return response()->json('');
        }
    }

    /**
     * Bot mission creation api
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function yaPoshuMissii(Request $request): JsonResponse
    {
//        $format = MissionFormat::where('format', '=', $request->get('format'))->first();
//        $mode = MissionMode::where('type', '=', $request->get('mode'))->first();

        try {
            Mission::create([
                'game' => $request->post('game'),
                'mode_id' => $request->post('mode_id'),
                'format_id' => $request->post('format_id'),
                'category' => $request->post('category'),
                'difficult' => $request->post('difficult'),
                'title' => [
                    'ru' => $request->post('title_ru'),
                    'en' => $request->post('title_en')
                ],
                'description' => [
                    'ru' => $request->post('description_ru',null),
                    'en' => $request->post('description_en',null)
                ],
                'required_action' => $request->post('required_action'),
                'required_times' => $request->post('required_times'),
                'buy_in' => $request->post('buy_in'),
                'payment' => $request->post('buy_in') ? 'pay' : 'free',
                'reward' => $request->post('reward'),
                'participants_min' => 1,
                'participants_max' => null,
                'participants_current' => 0,
                'duration' => $request->post('duration'),
                'published_at' => $request->post('published_at', now()),
                'closed_at' => $request->post('closed_at'),
                'status' => 'open',
                'visibility' => 'public'
            ]);

//            event(new NewMission($mission, $mission->game));
            return response()->json(['message' => 'Миссия успешно добавлена!']);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
            return response()->json(['message' => $exception->getMessage()], $this->errorStatus);
        }
    }
}
