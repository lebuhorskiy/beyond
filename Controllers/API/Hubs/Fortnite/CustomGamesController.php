<?php

namespace App\Http\Controllers\API\Hubs\Fortnite;

use App\Events\Hubs\Fortnite\CustomGame\DeleteMatch;
use App\Events\Hubs\Fortnite\CustomGame\MatchFinished;
use App\Events\Hubs\Fortnite\CustomGame\MatchPasswordUpdated;
use App\Events\Hubs\Fortnite\CustomGame\MatchStatusUpdated;
use App\Events\Hubs\Fortnite\CustomGame\NewMatch;
use App\Events\Hubs\Fortnite\CustomGame\NewPlayerInMatch;
use App\Events\Hubs\Fortnite\CustomGame\NewReplayInMatch;
use App\Events\Hubs\Fortnite\CustomGame\OnDeleteReplayInMatch;
use App\Events\Hubs\Fortnite\CustomGame\OnLeavePlayerFromMatch;
use App\Events\Hubs\Fortnite\CustomGame\OnDataUpdatedByReplayProcessed;
use App\Events\Hubs\Fortnite\CustomGame\OnReplayProcessed;
use App\Events\Hubs\Fortnite\CustomGame\OnResultsConfirmed;
use App\Events\Notifications\NewNotification;
use App\Http\Controllers\Controller;
use App\Models\Hubs\Fortnite\Customs\CustomGame;
use App\Models\Hubs\Fortnite\Customs\CustomGameReplay;
use App\Models\Hubs\Fortnite\Customs\CustomGameToUser;
use App\Models\Users\User;
use App\Models\Users\UserNotification;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CustomGamesController extends Controller
{

    protected $__KILLS = 5;
    protected $__POINTS = [
        1 => 55, 2 => 49, 3 => 46, 4 => 43, 5 => 40, 6 => 37, 7 => 35, 8 => 34, 9 => 33, 10 => 32, 11 => 31, 12 => 30, 13 => 29, 14 => 28, 15 => 27, 16 => 26, 17 => 25, 18 => 24, 19 => 23, 20 => 22, 21 => 21, 22 => 20, 23 => 19, 24 => 18, 25 => 17, 26 => 16, 27 => 15, 28 => 14, 29 => 13, 30 => 12, 31 => 11, 32 => 10, 33 => 9, 34 => 8, 35 => 7, 36 => 6, 37 => 5, 38 => 4, 39 => 3, 40 => 2
    ];

    /**
     * Get list of 15 newer matches where status in "checkin" and "ongoing"
     *
     * @return JsonResponse
     */
    public function list(): JsonResponse
    {
        $matches = Cache::rememberForever('custom-games:list', function () {
            return CustomGame::with('creator', 'players', 'replays')
                ->where('status', '!=', 'completed')
                ->get();
        });

        return response()->json([
            'matches' => $matches
        ]);
    }

    /**
     * Get list of 15 newer matches where status is "checkin" or "ongoing"
     *
     * @return JsonResponse
     */
    public function history(): JsonResponse
    {
        $matches = Cache::rememberForever('custom-games:history', function () {
            return CustomGame::with('creator', 'players', 'replays')
                ->where('status', '=', 'completed')
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get();
        });

        return response()->json([
            'matches' => $matches
        ]);
    }

    /**
     * Create match
     *
     * @param Request $request
     * @return JsonResponse|array
     */
    public function create(Request $request)
    {
        try {
            $create = CustomGame::create([
                'user_id' => auth()->id(),
                'format' => $request->post('format'),
                'prizes' => $request->post('prizes'),
                'password' => $request->post('password'),
                'status' => 'checkin',
            ]);

            event(new NewMatch(CustomGame::with('creator', 'players', 'replays')->find($create->id)));

            return response()->json([
                'message' => 'Кастомка успешно создана!'
            ], 200);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
            return response()->json(['message' => __('messages.general_error')], $this->errorStatus);
        }
    }

    /**
     * Join match
     *
     * @param integer $id
     * @return JsonResponse|array
     */
    public function join(int $id)
    {
        try {
            $match = CustomGame::with('players', 'creator', 'replays')->find($id);

            $alreadyPlaying = CustomGameToUser::where('game_id', '=', $match->id)
                ->where('user_id', '=', auth()->id())
                ->first();

            if ($alreadyPlaying) {
                return response()->json(['message' => 'Вы уже участвуете в данной кастомке!'], $this->errorStatus);
            }

            $otherPlaying = CustomGameToUser::join('fortnite_custom_games', 'fortnite_custom_games.id', '=', 'fortnite_custom_game_to_users.game_id')
                ->whereNotIn('fortnite_custom_games.status', ['completed'])
                ->where('fortnite_custom_game_to_users.user_id', '=', auth()->id())
                ->first();

            if ($otherPlaying) {
                return response()->json(['message' => 'Вы уже участвуете в другой кастомке!'], $this->errorStatus);
            }

            if (!auth()->user()->epic_id) {
                return response()->json(['message' => 'Вы должны привязать свой Epic Games аккаунт в интеграциях профиля на сайте!'], 400);
            }

            $match->players()->create([
                'user_id' => auth()->id()
            ]);

            event(new NewPlayerInMatch($match));

            $cache = Cache::get('custom-games:list');
            $matchKeyInCache = null;
            foreach ($cache as $key => $item) {
                if ($item->id === $id) {
                    $matchKeyInCache = $key;
                    $cache[$key]['players'][] = CustomGameToUser::with('user')
                        ->where('user_id', '=', auth()->id())
                        ->where('game_id', '=', $id)
                        ->first();

                    event(new NewPlayerInMatch($cache[$key]));
                }
            }

            Cache::put('custom-games:list', $cache);

            if (count($cache[$matchKeyInCache]['players']) === 2) {
                $match->update([
                    'status' => 'ongoing'
                ]);

                $cache[$matchKeyInCache]['status'] = 'ongoing';
                $cache[$matchKeyInCache]['updated_at'] = now();

                event(new MatchStatusUpdated($cache[$matchKeyInCache]));
            }

            return response()->json([
                'message' => 'Вы успешно присоединились к кастомке!'
            ], 200);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
            return response()->json(['message' => __('messages.general_error')], $this->errorStatus);
        }
    }

    /**
     * Leave match
     *
     * @param integer $id
     * @return JsonResponse|array
     */
    public function leave(int $id)
    {
        try {
            $match = CustomGame::find($id);

            $player = CustomGameToUser::where('game_id', '=', $match->id)
                ->where('user_id', '=', auth()->id())
                ->first();

            if (!$player) {
                return response()->json(['message' => 'Вы не участвуете в данной кастомке!'], $this->errorStatus);
            }

            $player->delete();

            event(new OnLeavePlayerFromMatch($match));

            $cache = Cache::get('custom-games:list');
            $newCache = [];
            foreach ($cache as $item) {
                if ($item->id === $id) {
                    foreach ($item->players as $key => $player) {
                        if ($player->user_id === auth()->id()) {
                            unset($item->players[$key]);
                        }
                    }
                }
                $newCache[] = $item;
            }

            Cache::put('custom-games:list', $newCache);

            return response()->json([
                'message' => 'Вы успешно покинули кастомку!'
            ]);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
            return response()->json(['message' => __('messages.general_error')], $this->errorStatus);
        }
    }

    /**
     * Delete match
     *
     * @param integer $id
     * @return JsonResponse|array
     */
    public function delete(int $id)
    {
        try {
            $match = CustomGame::find($id);

            if (!$match) {
                return response()->json(['message' => 'Матч не найден!'], $this->errorStatus);
            }

            if ($match->user_id !== auth()->id()) {
                return response()->json(['message' => 'Вы не являетесь админом в данной кастомке!'], $this->accessDeniedStatus);
            }

            $match->delete();

            event(new DeleteMatch($match));

            return response()->json([
                'message' => 'Вы успешно удалили кастомку!'
            ], 200);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
            return response()->json(['message' => __('messages.general_error')], $this->errorStatus);
        }
    }

    /**
     * Start match
     *
     * @param integer $id
     * @return JsonResponse|array
     */
    public function start(int $id)
    {
        try {
            $match = CustomGame::find($id);

            if (!$match) {
                return response()->json(['message' => 'Матч не найден!'], $this->errorStatus);
            }

            if ($match->user_id !== auth()->id()) {
                return response()->json(['message' => 'Вы не являетесь админом в данной кастомке!'], $this->accessDeniedStatus);
            }

            $match->update([
                'status' => 'ongoing'
            ]);

            event(new MatchStatusUpdated($match));

            return response()->json([
                'message' => 'Вы успешно запустили кастомку!'
            ]);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
            return response()->json(['message' => __('messages.general_error')], $this->errorStatus);
        }
    }

    /**
     * Start match
     *
     * @param integer $id
     * @return JsonResponse|array
     */
    public function finish(int $id)
    {
        try {
            $match = CustomGame::with('creator', 'playersSorted', 'playersSorted.user')->find($id);

            if (!$match) {
                return response()->json(['message' => 'Матч не найден!'], $this->notFoundStatus);
            }

            if ($match->user_id !== auth()->id() && !auth()->user()->hasRole(['admin', 'moderator'])) {
                return response()->json(['message' => 'Вы не являетесь админом в данной кастомке!'], $this->accessDeniedStatus);
            }

            foreach ($match->playersSorted as $key => $player) {
                // For kills
                $points = $player->kills * 5;

                // Apply points for place
                if (isset($this->__POINTS[$player->place])) {
                    $points = $points + $this->__POINTS[$player->place];
                }

                $player->update([
                    'place' => $player->place,
                    'points' => $points,
                    'prize' => isset($match->prizes[$key]) ? $key : null
                ]);

                if (isset($match->prizes[$key]) && $match->prizes[$key]['bp']) {
                    $player->user->update([
                        'balance_points' => ($player->user->balance_points + $match->prizes[$key]['bp'])
                    ]);

                    // Notify winner
                    $notification = UserNotification::create([
                        'user_id' => $player->user->id,
                        'category' => 'hubs',
                        'title' => [
                            'ru' => 'Награда за участие в Кастомке!',
                            'en' => "Reward for Custom Match!"
                        ],
                        'text' => [
                            'ru' => 'Поздравляем, Вы заняли ' . ($key + 1) . ' место и выиграли ' . $match->prizes[$key]['bp'] . ' BP!',
                            'en' => 'Congrats, you have take ' . ($key + 1) . ' place and won ' . $match->prizes[$key]['bp'] . ' BP!'
                        ],
                    ]);

                    event(new NewNotification($player->user->uuid, $notification));
                }
            }

            $match->update([
                'status' => 'completed'
            ]);

            event(new MatchStatusUpdated($match));
            event(new MatchFinished($match));

            $cacheList = Cache::get('custom-games:list');
            foreach ($cacheList as $key => $item) {
                if ($item->id === $id) {
                    unset($cacheList[$key]);
                }
            }

            $cacheHistory = Cache::get('custom-games:history');
            $cacheHistory[] = CustomGame::with('creator', 'players', 'replays')->find($id);

            Cache::put('custom-games:history', $cacheHistory);

            return response()->json([
                'message' => 'Вы успешно завершили кастомку!'
            ], 200);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
            return response()->json(['message' => __('messages.general_error')], $this->errorStatus);
        }
    }

    /**
     * Generate new password for match
     *
     * @param Request $request
     * @param integer $id
     * @return JsonResponse|array
     */
    public function generateNewPassword(Request $request, int $id)
    {
        try {
            $match = CustomGame::find($id);

            if (!$match) {
                return response()->json(['message' => 'Матч не найден!'], $this->notFoundStatus);
            }

            if ($match->user_id !== auth()->id()) {
                return response()->json(['message' => 'Вы не являетесь админом в данной кастомке!'], $this->accessDeniedStatus);
            }

            $match->update([
                'password' => $request->post('password')
            ]);

            event(new MatchPasswordUpdated($match));

            return response()->json([
                'message' => 'Вы успешно сменили пароль на кастомку!'
            ], 200);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
            return response()->json(['message' => __('messages.general_error')], $this->errorStatus);
        }
    }

    /**
     * Upload replay
     *
     * @param Request $request
     * @param integer $id
     * @return JsonResponse|array
     */
    public function uploadReplay(Request $request, int $id)
    {
        try {
            $match = CustomGame::find($id);

            if (!$match) {
                return response()->json(['message' => 'Матч не найден!'], $this->notFoundStatus);
            }

            $isInMatchPlayer = CustomGameToUser::where('game_id', '=', $match->id)
                ->where('user_id', '=', auth()->id())
                ->first();

            if (!$isInMatchPlayer && auth()->id() !== $match->user_id) {
                return response()->json(['message' => 'Вы не участник данной кастомки!'], $this->accessDeniedStatus);
            }

            if (!$request->hasFile('replay')) {
                return response()->json(['message' => 'Вы не выбрали реплей!'], $this->errorStatus);
            }

            $file = $request->file('replay');

            $exists = CustomGameReplay::where('user_id', '=', auth()->id())
                ->where('game_id', $match->id)
                ->first();

            if ($exists) {
                return response()->json(['message' => 'Вы уже загружали реплей для этой кастомки!'], $this->errorStatus);
            }

            $fileName = auth()->user()->epic_nickname . '.' . $file->getClientOriginalExtension();

            $store = \Storage::disk('public')->put('/uploads/hubs/customs/' . $fileName, file_get_contents($file));

            if (!$store) {
                return response()->json(['error' => 'Возникла ошибка при загрузке.'], $this->errorStatus);
            }

            $replay = $match->replays()->create([
                'user_id' => auth()->id(),
                'name' => $fileName,
                'status' => 0,
                'weight' => 0
            ]);

            event(new NewReplayInMatch($match, $replay));

            return response()->json([
                'message' => 'Вы успешно загрузили реплей!'
            ], 200);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
            return response()->json(['message' => __('messages.general_error')], $this->errorStatus);
        }
    }

    /**
     * Send replay to api
     *
     * @param Request $request
     * @param integer $id
     * @return JsonResponse|array
     */
    public function processReplay(Request $request, int $id)
    {
        try {
            $match = CustomGame::find($id);

            if (!$match) {
                return response()->json(['message' => 'Матч не найден!'], $this->notFoundStatus);
            }

            if ($match->user_id !== auth()->id() && !auth()->user()->hasRole(['admin', 'moderator'])) {
                return response()->json(['message' => 'Вы не являетесь админом в данной кастомке!'], $this->accessDeniedStatus);
            }

            $replay = CustomGameReplay::find($request->post('replay_id'));

            $client = new Client();

            $endpoint = 'http://185.107.96.187:44135/replay';

            $res = $client->request('POST', $endpoint, [
                'multipart' => [
                    [
                        'name' => 'replay',
                        'contents' => file_get_contents(storage_path('app/public/uploads/hubs/customs/') . $replay->name),
                        'filename' => $replay->name
                    ]
                ],
            ]);

            $response = json_decode($res->getBody(), false, 512, JSON_THROW_ON_ERROR);

            $replay->update(['replay_id' => $response->replay_id]);

            event(new OnReplayProcessed($match));

            return response()->json([
                'replay_id' => $response->replay_id,
                'result_url' => $response->result_url,
            ], 200);
        } catch (GuzzleException $exception) {
            Log::error($exception->getMessage());
            return response()->json(['message' => __('messages.general_error')], $this->errorStatus);
        } catch (\JsonException $e) {
            return response()->json(['message' => __('messages.general_error')], $this->errorStatus);
        }
    }

    /**
     * Send replay to api
     *
     * @param integer $id
     * @param string $replay_id
     * @return JsonResponse|array
     */
    public function checkReplayStatus(int $id, string $replay_id)
    {
        try {
            $match = CustomGame::with('playersAll', 'playersAll.user')->find($id);

            $match->players = $match->playersAll;

            if (!$match) {
                return response()->json(['message' => 'Матч не найден!'], $this->notFoundStatus);
            }

            if ($match->user_id !== auth()->id() && !auth()->user()->hasRole(['admin', 'moderator'])) {
                return response()->json(['message' => 'Вы не являетесь админом в данной кастомке!'], $this->accessDeniedStatus);
            }

            $replay = CustomGameReplay::where('replay_id', '=', $replay_id)->first();

            if (!$replay) {
                return response()->json(['message' => 'Повтор не найден!'], $this->notFoundStatus);
            }

            $client = new \GuzzleHttp\Client();

            $endpoint = 'http://185.107.96.187:44135/result/' . $replay_id;

            $res = $client->request('GET', $endpoint);

            $response = json_decode($res->getBody());

            if ($response->status === 0) {
                return response()->json(['message' => 'Повтор загружен на сервер...']);
            }
            if ($response->status === 1) {
                return response()->json(['message' => 'Повтор находится в обработке...']);
            }
            if ($response->status === 2) {

                if(!$response->data->status) {
                    $replay->delete();
                    event(new OnDeleteReplayInMatch($match));
                    return response()->json(['message' => 'Файл повтора поврежден и был удален. Попробуйте обработать другой повтор!'], 400);
                }

                if($response->data->custom_key !== $match->password) {
                    $replay->delete();
                    event(new OnDeleteReplayInMatch($match));
                    return response()->json(['message' => 'Пароли матча и повтора не совпадают, повтор был удален!'], 400);
                }

                $data = [];
                foreach ($response->data->result as $result) {
                    $players = explode(':', $result->team_id);

                    // Solo
                    if (count($players) === 1) {
                        // Try to find user by epic_id provided
                        $user = User::where('epic_id', '=', $players[0])->first();

                        // If user exists
                        if ($user) {
                            // Check if this user is actually playing in match
                            $updatePlayer = CustomGameToUser::where('game_id', '=', $match->id)
                                ->where('user_id', '=', $user->id)
                                ->first();

                            // If playing
                            if ($updatePlayer) {
                                // Apply points for kills
                                $points = $result->kills->{$players[0]} * $this->__KILLS;

                                // Apply points for place
                                if (isset($this->__POINTS[$result->place])) {
                                    $points = $points + $this->__POINTS[$result->place];
                                }

                                $updatePlayer->update([
                                    'place' => $result->place,
                                    'kills' => $result->kills->{$players[0]},
                                    'points' => $points,
                                ]);
                                $data[] = [
                                    'nickname' => $user->nickname,
                                    'epic_nickname' => $user->epic_nickname,
                                    'place' => $result->place,
                                    'kills' => $result->kills->{$players[0]},
                                ];
                            }
                        }
                    } else { // Teams
                        // TODO:duo/trio/squad
                    }
                }

                $replay->update([
                    'status' => $response->status,
                    'processed' => true,
                    'weight' => $response->data->weight,
                ]);

                event(new OnDataUpdatedByReplayProcessed($match));

                return response()->json([
                    'message' => 'Повтор успешно обработан, проверьте таблицу!',
                    'players' => $match->playersAll
                ]);
            }

        } catch (GuzzleException $exception) {
            Log::error($exception->getMessage());
            return response()->json(['message' => __('messages.general_error')], $this->errorStatus);
        }
    }

    /**
     * Confirm match results
     *
     * @param integer $id
     * @return JsonResponse|array
     */
    public function confirmResults(int $id)
    {
        try {
            $match = CustomGame::find($id);

            if (!$match) {
                return response()->json(['message' => 'Матч не найден!'], $this->notFoundStatus);
            }

            if ($match->user_id !== auth()->id() && !auth()->user()->hasRole(['admin', 'moderator'])) {
                return response()->json(['message' => 'Вы не являетесь админом в данной кастомке!'], $this->accessDeniedStatus);
            }

            $match->update([
                'results_confirmed' => true
            ]);

            event(new OnResultsConfirmed($match->id));

            return response()->json([
                'message' => 'Вы успешно подтвердили результаты! Теперь вы можете завершить кастомку!'
            ]);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
            return response()->json(['message' => __('messages.general_error')], $this->errorStatus);
        }
    }
}
