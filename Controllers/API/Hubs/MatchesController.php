<?php
/*@todo Включить после проверки и поменять сравнение на strict ===
 * */
//declare(strict_types = 1);

namespace App\Http\Controllers\API\Hubs;

use App\Events\Hubs\ChangeStatus;
use App\Events\Hubs\ChangeStatusSingleMatch;
use App\Events\Hubs\ModerateMatch;
use App\Events\Hubs\ModerateMatchCounter;
use App\Events\Hubs\NewLog;
use App\Events\Hubs\NewPlayerInMatch;
use App\Events\Hubs\NewPlayerInSingleMatch;
use App\Events\Hubs\NewScreenshotInMatch;
use App\Events\Hubs\OnLeavePlayerFromMatch;
use App\Events\Hubs\OnLeavePlayerFromSingleMatch;
use App\Events\Hubs\PlayerAcceptedMatch;
use App\Events\Hubs\ZoneWarsCloseAvailability;
use App\Events\Notifications\NewNotification;
use App\Http\Controllers\API\Ratings\RatingController;
use App\Http\Controllers\API\SeasonPass\SeasonPassController;
use App\Http\Controllers\Controller;
use App\Models\Hubs\MatchToUser;
use App\Models\Hubs\Match;
use App\Models\Players\SeasonStats;
use App\Models\Users\UserNotification;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\Hubs\MatchLog;
use App\Models\Hubs\Screenshot;
use Illuminate\Validation\ValidationException;

class MatchesController extends Controller
{

    protected $ignore_status = [
        'completed', 'canceled', 'under_moderation', 'revoked', 'archived'
    ];

    /**
     * Call for moderate
     *
     * @param $id
     * @return JsonResponse
     */
    public function moderate($id): JsonResponse
    {
        $match = Match::find($id);

        $requests_array = $match->moderate_requests ?? [];
        if (!in_array(auth()->id(), $requests_array)) {
            $requests_array[] = auth()->id();
        }
        $requests_count = $requests_array ? count($requests_array) : 0;

        $match->update([
            'moderate_requests' => $requests_array
        ]);

        event(new ModerateMatchCounter($match->uuid, $requests_count));

        if ($requests_count <= round($match->slots / 2)) {
            $log = new MatchLog([
                'player_id' => auth()->id(),
                'is_service_message' => true,
                'text' => [
                    'ru' => 'Вызвать модератора (' . $requests_count . '/' . round($match->slots / 2) . ')',
                    'en' => 'Call for moderate (' . $requests_count . '/' . round($match->slots / 2) . ')',
                ]
            ]);

            $log->loggable()->associate($match)->save();
            event(new NewLog($match->uuid, $log));

            if ($requests_count == round($match->slots / 2)) {
                $log = new MatchLog([
                    'player_id' => auth()->id(),
                    'is_service_message' => true,
                    'text' => [
                        'ru' => 'Игроки решили вызвать модератора.',
                        'en' => 'Players decided to call for moderate.',
                    ]
                ]);

                $log->loggable()->associate($match)->save();
                event(new NewLog($match->uuid, $log));

                $match->update([
                    'needs_to_moderate' => true
                ]);

                event(new ModerateMatch($match->uuid));

                // Send notification to moderators
                $moderators = DB::table('role_user')
                    ->join('users', 'users.id', '=', 'role_user.user_id')
                    ->whereIn('role_user.role_id', [1, 2])
                    ->select('users.uuid', 'users.id')
                    ->get();

                $url_ru = '/ru/fortnite/hubs/' . $match->division . '/' . $match->type_game . '/' . $match->uuid;
                $url_en = '/en/fortnite/hubs/' . $match->division . '/' . $match->type_game . '/' . $match->uuid;

                foreach ($moderators as $moderator) {
                    // Create and send notification to user
                    $notification = UserNotification::create([
                        'user_id' => $moderator->id,
                        'category' => 'hubs',
                        'title' => [
                            'ru' => 'Необходима модерация!',
                            'en' => "Moderation required!"
                        ],
                        'text' => [
                            'ru' => 'Игроки вызывают модерацию на матч <a href="' . $url_ru . '">перейти к матчу</a>',
                            'en' => 'Players called for moderate in match <a href="' . $url_en . '">go to match</a>'
                        ],
                    ]);

                    event(new NewNotification($moderator->uuid, $notification));
                }

                return response()->json(['message' => 'Запрос модератору успешно отправлен!']);
            }

            return response()->json(['message' => 'Вы успешно подали запрос на модерирование матча!']);
        }
        return response()->json(['message' => 'Ошибка при отправке.'], $this->errorStatus);
    }

    public function ready(Request $request)
    {
        $match_id = (int)$request->match_id;
        if (!$match_id || gettype($match_id) != 'integer') {
            return response()->json(['error' => 'Неверный ID матча.'], $this->errorStatus);
        }
        DB::beginTransaction();

        $findRelatedOnMatch = MatchToUser::where('user_id', '=', auth()->id())->where('match_id', '=', $match_id)->first();

        if (!$findRelatedOnMatch->exists()) {
            return response()->json(['error' => 'Ошибка доступа.'], $this->accessDeniedStatus);
        }

        $update = $findRelatedOnMatch->update([
            'accepted' => 1,
        ]);

        $match = Match::where('id', '=', $match_id)->first();

        $log = new MatchLog([
            'player_id' => auth()->id(),
            'is_service_message' => false,
            'text' => [
                'ru' => 'Игрок ' . auth()->user()->nickname . ' приготовился к матчу.',
                'en' => 'Player ' . auth()->user()->nickname . ' is ready to play.',
            ]
        ]);

        $log->loggable()->associate($match)->save();

        event(new NewLog($match->uuid, $log));
        event(new PlayerAcceptedMatch($match->uuid, auth()->id()));

        if ($match->is_all_accepted) {
            $log = new MatchLog([
                'player_id' => auth()->id(),
                'is_service_message' => true,
                'text' => [
                    'ru' => 'Все игроки приготовились к началу матча.',
                    'en' => 'All players are ready to play.',
                ]
            ]);

            $log->loggable()->associate($match)->save();

            event(new NewLog($match->uuid, $log));

            $match->update([
                'status' => 'confirmation_hoster'
            ]);

            event(new ChangeStatusSingleMatch($match->uuid, 'confirmation_hoster'));
        }
        DB::commit();
        if ($update) {
            return response()->json(['message' => 'Вы успешно приготовились к матчу!']);
        }

        return response()->json([], $this->errorStatus);
    }


    public function start(Request $request): JsonResponse
    {
        $match_id = (int)$request->match_id;
        if (!$match_id || gettype($match_id) != 'integer') {
            return response()->json(['error' => 'Неверный ID матча.'], $this->errorStatus);
        }
        DB::beginTransaction();

        $findRelatedOnMatch = Match::where('assign_user_id', '=', auth()->id())->where('id', '=', $match_id);

        if (!$findRelatedOnMatch->exists()) {
            return response()->json(['error' => 'Ошибка доступа.'], $this->accessDeniedStatus);
        }

        $match = Match::find($match_id);

        $update = $match->update([
            'status' => 'ongoing'
        ]);

        $log = new MatchLog([
            'player_id' => auth()->id(),
            'is_service_message' => true,
            'text' => [
                'ru' => 'Матч запущен. Желаем Вам приятной игры.',
                'en' => 'Match is live. Wish you luck.',
            ]
        ]);

        $match->log()->save($log);

        event(new NewLog($match->uuid, $log));

        event(new ChangeStatus($match->id, null, 'ongoing', $match->discipline, $match->type_game, $match->division));
        event(new ChangeStatusSingleMatch($match->uuid, 'ongoing'));

        DB::commit();
        if ($update) {
            return response()->json(['message' => 'Вы успешно запустили матч!']);
        }

        return response()->json([], $this->errorStatus);
    }

    /**
     * Join match api
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function join(Request $request): JsonResponse
    {
        $id = (int)$request->get('id');
        if (!$id || gettype($id) != 'integer') {
            return response()->json(['error' => 'Неверный ID матча.'], $this->errorStatus);
        }

        // check match
        $match = Match::find($id);

        if (!$match) {
            return response()->json(['error' => 'Матч не найден.'], $this->notFoundStatus);
        }

        if ($match->status !== 'checkin') {
            return response()->json(['error' => 'Регистрация на этот матч завершена'], $this->errorStatus);
        }

        if (!auth()->user()->epic_id) {
            return response()->json(['error' => 'Для участия в Лейтах необходимо привязать Epic Games аккаунт в профиле на сайте!'], 322);
        }

        // check slots
        if ($match->players()->count() === $match->slots) {
            return response()->json(['error' => 'Все слоты заняты'], $this->errorStatus);
        }

        // check isset user in match
        $check = MatchToUser::where([
            ['match_id', '=', $id],
            ['user_id', '=', auth()->id()]
        ])->first();
        if ($check) {
            return response()->json(['error' => 'Вы уже зарегистрировались на данный матч'], $this->errorStatus);
        }

        // check if isset user is already registered for other match
        $otherMatch = MatchToUser::join('matches', 'matches.id', '=', 'match_to_users.match_id')
            ->where('match_to_users.user_id', '=', auth()->id())
            ->whereNotIn('matches.status', $this->ignore_status)
            ->first();

        if ($otherMatch) {
            return response()->json(['error' => 'Вы уже участвуете в другом матче'], $this->errorStatus);
        }

        $join = MatchToUser::create([
            'user_id' => auth()->id(),
            'match_id' => $id
        ]);

        auth()->user()->seasonStats()
            ->firstOrCreate(
                [
                    'season_id' => (new RatingController())->getCurrentSeason()->id,
                    'section' => 'zone_wars',
                    'game' => 'fortnite'
                ],
                [
                    'average_top' => 0,
                    'total' => 0,
                    'rating' => 1000
                ]);

        if (!$join) {
            return response()->json(['error' => 'Ошибка'], $this->errorStatus);
        }

        if ($match->players()->count() == $match->slots) {

            Match::where('id', '=', $id)->update([
                'status' => 'confirmation',
                'next_time_check' => \Carbon\Carbon::now()->addMinutes(5)->timestamp
            ]);

            event(new ChangeStatus($match->id, null, 'confirmation', $match->discipline, $match->type_game, $match->division));
            event(new ChangeStatusSingleMatch($match->uuid, 'confirmation'));
        }

        $match->update(['current_slots' => $match->players()->count()]);

        $joinInstance = MatchToUser::where('id', '=', $join->id)->with('user')->first();
        event(new NewPlayerInSingleMatch($match->uuid, $joinInstance));
        event(new NewPlayerInMatch($match, $joinInstance, $match->discipline, $match->type_game, $match->division));

        $log = new MatchLog([
            'player_id' => auth()->id(),
            'is_service_message' => false,
            'text' => [
                'ru' => 'Игрок ' . auth()->user()->nickname . ' присоединился к матчу.',
                'en' => 'Player ' . auth()->user()->nickname . ' has joined match.',
            ]
        ]);

        $log->loggable()->associate($match)->save();

        event(new NewLog($match->uuid, $log));

        return response()->json(['type' => 'success', 'message' => 'Вы присоединились к матчу.', 'uuid' => $match->uuid, 'match' => $match]);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function leave(Request $request): JsonResponse
    {
        $id = (int)$request->get('id');
        if (!$id || gettype($id) != 'integer') {
            return response()->json(['error' => 'Неверный ID матча.'], $this->errorStatus);
        }

        // check match
        $match = Match::find($id);

        if (!$match) {
            return response()->json(['error' => 'Матч не найден.'], $this->notFoundStatus);
        }

        $match->update([
            'current_slots' => $match->current_slots - 1
        ]);

        // check isset user in match
        $check = MatchToUser::where([
            ['match_id', '=', $id],
            ['user_id', '=', auth()->id()]
        ])->first();

        if (!$check) {
            return response()->json(['error' => 'Вы не зарегистрированы на этот матч'], $this->errorStatus);
        }

        $check->delete();

        event(new OnLeavePlayerFromSingleMatch($match->uuid, auth()->id()));
        event(new OnLeavePlayerFromMatch($match, auth()->id(), $match->discipline, $match->type_game, $match->division));

        $log = new MatchLog([
            'player_id' => auth()->id(),
            'is_service_message' => false,
            'text' => [
                'ru' => 'Игрок ' . auth()->user()->nickname . ' покинул матч.',
                'en' => 'Player ' . auth()->user()->nickname . ' has leave match.',
            ]
        ]);

        $log->loggable()->associate($match)->save();

        event(new NewLog($match->uuid, $log));

        return response()->json(['type' => 'success', 'message' => 'Вы вышли из матча.']);
    }

    /**
     * Send screenshot api
     *
     * @param Request $request
     * @return JsonResponse
     * @throws ValidationException
     */
    public function sendScreenshot(Request $request): JsonResponse
    {
        Validator::make($request->all(), [
            'screenshot' => 'required',
            'match_id' => 'required|integer|exists:App\Models\Hubs\Match,id',
        ])->validate();

        // check user in match
        $check = MatchToUser::where('match_id', '=', $request->get('match_id'))->where('user_id', '=', auth()->id())->first();

        if (!$check->exists()) {
            return response()->json(['error' => 'Ошибка доступа.'], $this->accessDeniedStatus);
        }

        $files = $request->file('screenshot');
        $countScreenshots = Screenshot::where('match_id', '=', $request->get('match_id'))->count();

        if (Screenshot::where('match_id', '=', $request->get('match_id'))->where('user_id', '=', auth()->id())->count() > 0) {
            return response()->json([
                'error' => "Вы уже загружали скриншот для данного матча."
            ], $this->errorStatus);
        }

        foreach ($files as $key => $file) {

            $fileName = $check->match->uuid . '.' . $file->getClientOriginalExtension() . '_' . ($countScreenshots + $key);
            $store = \Storage::disk('public')->put('/uploads/match_results/' . $fileName, file_get_contents($file));

            if ($store) {
                $insertScreenshot = Screenshot::create([
                    'user_id' => auth()->id(),
                    'match_id' => $request->get('match_id'),
                    'file_url' => $fileName
                ]);

                if (!$insertScreenshot) {
                    return response()->json(['error' => 'Возникла ошибка при загрузке.'], $this->errorStatus);
                }

                event(new NewScreenshotInMatch($check->match->uuid, $insertScreenshot));
            }
        }

        return response()->json(['type' => 'success', 'message' => 'Скриншоты успешно загружены.']);
    }

    /**
     * Save player statistics
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function savePlayerStats(Request $request): JsonResponse
    {
        $match_id = (int)$request->get('match_id');
        if (!$match_id || gettype($match_id) != 'integer') {
            return response()->json(['error' => 'Неверный ID матча.'], $this->errorStatus);
        }

        $user_id = (int)$request->get('user_id');
        if (!$user_id || gettype($user_id) != 'integer') {
            return response()->json(['error' => 'Неверный ID игрока.'], $this->errorStatus);
        }

        // check match
        $match = MatchToUser::where('match_id', $match_id)
            ->where('user_id', $user_id)
            ->first();

        if (!$match->exists()) {
            return response()->json(['error' => 'Матч не найден.'], $this->notFoundStatus);
        }

        if ($match->match->assign_user_id != auth()->id()) {
            return response()->json(['error' => 'Ошибка доступа.'], $this->accessDeniedStatus);
        }

        $match->update([
            'place' => $request->get('place')
        ]);

        $availableToClose = MatchToUser::where('match_id', '=', $match->match->id)->where('place', '=', null)->get()->count() === 0;
        event(new ZoneWarsCloseAvailability($match->match->uuid, $availableToClose));

        return response()->json([
            'message' => 'Информация успешно сохранена!',
            'status' => $match->match->status
        ]);
    }

    /**
     * Kick player from lobby
     *
     * @param $id
     * @return JsonResponse
     */
    public function kick($id): JsonResponse
    {
        try {
            $player = MatchToUser::find($id);

            $log = new MatchLog([
                'player_id' => auth()->id(),
                'is_service_message' => false,
                'text' => [
                    'ru' => 'Игрок ' . $player->user->nickname . ' исключен из матча.',
                    'en' => 'Player ' . $player->user->nickname . ' has been kicked from match.',
                ]
            ]);

            $log->loggable()->associate($player->match)->save();

            $player->match->update([
                'current_slots' => $player->match->current_slots - 1,
                'status' => 'checkin'
            ]);

            event(new ChangeStatus($player->match->id, null, 'checkin', $player->match->discipline, $player->match->type_game, $player->match->division));
            event(new ChangeStatusSingleMatch($player->match->uuid, 'checkin'));

            event(new NewLog($player->match->uuid, $log));
            event(new OnLeavePlayerFromSingleMatch($player->match->uuid, $player->user->id));
            event(new OnLeavePlayerFromMatch($player->match, auth()->id(), $player->match->discipline, $player->match->type_game, $player->match->division));

            $player->delete();

            return response()->json(['message' => 'Игрок успешно кикнут из матча!']);
        } catch (\Exception $exception) {
            return response()->json(['message' => 'Произошла ошибка.'], $this->errorStatus);
        }
    }

    public function completed(Request $request): JsonResponse
    {
        $match_id = (int)$request->get('match_id');
        if (!$match_id || gettype($match_id) != 'integer') {
            return response()->json(['error' => 'Неверный ID матча.'], $this->errorStatus);
        }

        $match = Match::find($match_id);

        if ($match->assign_user_id != auth()->id()) {
            return response()->json(['error' => 'Ошибка доступа.'], $this->accessDeniedStatus);
        }

        $players = MatchToUser::with('user')
            ->where('match_id', '=', $match->id)
            ->orderBy('place')
            ->get();

        //Season pass level points
        $SPController = new SeasonPassController();

        foreach ($players as $key => $player) {
            // Points to store in results
            $points = 0;

            if (
                ($match->slots === 4 && $key === 0) ||
                ($match->slots === 8 && $key < 3) ||
                ($match->slots === 12 && $key < 4) ||
                ($match->slots === 16 && $key < 6)
            ) {
                $SPController->updateUserPoints($player->user->id, 20);
                $player->user->update([
                    'account_points' => $player->user->account_points + 20,
                ]);
            } else {
                $SPController->updateUserPoints($player->user->id, 10);
                $player->user->update([
                    'account_points' => $player->user->account_points + 10,
                ]);
            }

            if ($match->slots === 4) {
                if ($key === 0) $points = 20;
                if ($key === 1) $points = 10;
                if ($key === 2) $points = 0;
                if ($key === 3) $points = -10;
            }
            if ($match->slots === 8) {
                if ($key === 0) $points = 30;
                if ($key === 1) $points = 20;
                if ($key === 2) $points = 10;
                if ($key === 3) $points = 5;
                if ($key === 4) $points = 0;
                if ($key === 5) $points = -5;
                if ($key === 6) $points = -10;
                if ($key === 7) $points = -15;
            }
            if ($match->slots === 12) {
                if ($key === 0) $points = 35;
                if ($key === 1) $points = 25;
                if ($key === 2) $points = 20;
                if ($key === 3) $points = 10;
                if ($key === 4) $points = 5;
                if ($key === 5) $points = 0;
                if ($key === 6) $points = -5;
                if ($key === 7) $points = -8;
                if ($key === 8) $points = -10;
                if ($key === 9) $points = -12;
                if ($key === 10) $points = -14;
                if ($key === 11) $points = -17;
            }
            if ($match->slots === 16) {
                if ($key === 0) $points = 40;
                if ($key === 1) $points = 30;
                if ($key === 2) $points = 25;
                if ($key === 3) $points = 20;
                if ($key === 4) $points = 15;
                if ($key === 5) $points = 10;
                if ($key === 6) $points = 5;
                if ($key === 7) $points = 0;
                if ($key === 8) $points = -5;
                if ($key === 9) $points = -7;
                if ($key === 10) $points = -9;
                if ($key === 11) $points = -11;
                if ($key === 12) $points = -13;
                if ($key === 13) $points = -15;
                if ($key === 14) $points = -17;
                if ($key === 15) $points = -19;
            }

            $player->update(['points' => $points]);

            $seasonStats = SeasonStats::where('user_id', '=', $player->user_id)
                ->where('season_id', '=', (new RatingController())->getCurrentSeason()->id)
                ->where('section', '=', 'zone_wars')
                ->where('game', '=', 'fortnite')
                ->first();


            $newAverageTop = $seasonStats->total >= 1 ? $seasonStats->average_top + ($key + 1) / 2 : $key + 1;

            $seasonStats->update([
                'total' => $seasonStats->total + 1,
                'rating' => $seasonStats->rating + $points,
                'average_top' => $newAverageTop,
                'wins' => $key === 0 ? $seasonStats->wins + 1 : $seasonStats->wins
            ]);

            if ($points >= 0) {
                $notification = UserNotification::create([
                    'user_id' => $player->user->id,
                    'category' => 'hubs',
                    'title' => [
                        'ru' => 'Заработаны очки в Лейтах.',
                        'en' => "Points earned in Zone Wars."
                    ],
                    'text' => [
                        'ru' => 'Поздравляем, Вам начислены очки за место в лейтах!',
                        'en' => 'Congrats, you have earned points in Zone Wars!'
                    ],
                ]);

                event(new NewNotification($player->user->uuid, $notification));
            } else {
                $notification = UserNotification::create([
                    'user_id' => $player->user->id,
                    'category' => 'hubs',
                    'title' => [
                        'ru' => 'Потеряны очки за место в Лейтах.',
                        'en' => "Points lose in Zone Wars."
                    ],
                    'text' => [
                        'ru' => 'К сожалению, у Вы потеряли очки в лейтах.',
                        'en' => 'Unfortunately, you have lose points in Zone Wars.'
                    ],
                ]);

                event(new NewNotification($player->uuid, $notification));
            }
        }

        $match->update([
            'status' => 'completed',
            'closed_at' => Carbon::now()
        ]);

        event(new ChangeStatus($match->id, null, 'completed', $match->discipline, $match->type_game, $match->division));
        event(new ChangeStatusSingleMatch($match->uuid, 'completed'));

        $log = new MatchLog([
            'player_id' => auth()->id(),
            'is_service_message' => true,
            'text' => [
                'ru' => 'Результаты матча заполнены. Матч завершен.',
                'en' => 'Results are filled. Match is over.',
            ]
        ]);

        $log->loggable()->associate($match)->save();

        event(new NewLog($match->uuid, $log));

        return response()->json(['message' => 'Матч завершен.']);

    }

}
