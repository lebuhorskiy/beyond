<?php

namespace App\Http\Controllers\API\Hubs\Fortnite;

use App\Events\Hubs\AutomatedMatchCreated;
use App\Events\Hubs\BeforeMatchLobbyDeleted;
use App\Events\Hubs\CancelRequest;
use App\Events\Hubs\CancelSingleMatch;
use App\Events\Hubs\ChangeStatusSingleMatch;
use App\Events\Hubs\CancelMatch;
use App\Events\Hubs\ChangeStatus;
use App\Events\Hubs\ModerateMatch;
use App\Events\Hubs\NewLog;
use App\Events\Hubs\NewMatch;
use App\Events\Hubs\NewMatchForModerate;
use App\Events\Hubs\NewPlayerInSingleMatch;
use App\Events\Hubs\NewScreenshot;
use App\Events\Hubs\SearchUpdated;
use App\Events\Notifications\NewNotification;
use App\Helpers\ApplyPercentageHelper;
use App\Http\Controllers\API\Ratings\RatingController;
use App\Http\Controllers\API\SeasonPass\SeasonPassController;
use App\Http\Controllers\Controller;
use App\Models\Hubs\BeforeMatchLobby;
use App\Models\Hubs\BeforeMatchLobbyToUser;
use App\Models\Hubs\Fortnite\FortniteSoloMatch;
use App\Models\Hubs\Fortnite\FortniteSoloMatchToUser;
use App\Models\Hubs\SearchLobby;
use App\Models\Hubs\MatchLog;
use App\Models\Hubs\MatchToUser;
use App\Models\Players\PlayerRating;
use App\Models\Users\User;
use App\Models\Users\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MaartenStaa\Glicko2\Rating;
use MaartenStaa\Glicko2\RatingCalculator;
use MaartenStaa\Glicko2\RatingPeriodResults;
use Storage;
use function React\Promise\Stream\first;

class SoloMatchController extends Controller
{
    public $ratingDeviation = 100;

    protected $__LVL_POINTS = [
        'wager' => 5,
        'box-fight' => 5
    ];

    protected $__SP_POINTS_WINNER = 15;
    protected $__SP_POINTS_LOOSER = 5;

    protected $ignore_status = [
        'completed', 'canceled', 'under_moderation', 'revoked', 'archived'
    ];

    /**
     * Get list of 15 newer matches by division and type and status is "checkin" or "ongoing" or "confirmation"
     *
     * @param $division
     * @return JsonResponse
     */
    public function list($division): JsonResponse
    {
        $matches = FortniteSoloMatch::with('first_player', 'second_player')
            ->where('division', '=', $division)
            ->whereNotIn('status', $this->ignore_status)
            ->orderBy('created_at', 'desc')
            ->limit(15)
            ->get();

        return response()->json($matches->reverse()->values());
    }

    /**
     * Create match
     *
     * @param Request $request
     * @param array|null $automated
     * @return JsonResponse|array
     */
    public function create(Request $request, $automated = null)
    {
        // Wager with manual creation
        if (!$automated) {
            // Check another match
            $otherMatch = FortniteSoloMatchToUser::join('fortnite_solo_matches', 'fortnite_solo_matches.id', '=', 'fortnite_solo_match_to_users.match_id')
                ->where('fortnite_solo_match_to_users.user_id', '=', auth()->id())
                ->whereNotIn('fortnite_solo_matches.status', $this->ignore_status)
                ->first();

            if ($otherMatch) {
                return response()->json(['error' => 'Вы уже участвуете в другом матче'], $this->errorStatus);
            }
        }

        try {
            DB::beginTransaction();

            // Закрытый матч
            $closed = $automated ? false : $request->post('closed', false);

            // Платный матч
            $type = $automated ? false : $request->post('type', false);

            // Сумма оплаты
            $buy_in = $automated ? 0 : $request->post('buy_in', 0);

            if (($buy_in !== 0) && auth()->user()->wager_points < $buy_in) {
                return response()->json(['message' => 'У вас не хватает Вагер-Поинтов для создания матча! Пополните свой счёт и создайте матч снова'], $this->errorStatus);
            }

            // Wager
            if ($buy_in > 0) {
//                $buy_in = ApplyPercentageHelper::percentage($buy_in, 10);
                $type = true;
            }

            // Match by password access
            if ($closed) {
                $password = Str::random(10);
                $close = true;
            } else {
                $close = null;
                $password = null;
            }

            $create = FortniteSoloMatch::create([
                'division' => $request->post('division', 'free'),
                'first_player' => $automated ? $request->post('players')[0]['user_id'] : auth()->id(),
                'second_player' => $automated ? $request->post('players')[1]['user_id'] : null,
                'map_name' => $request->post('map_name'),
                'status' => $automated ? 'ongoing' : 'pending',
                'password' => $password,
                'type' => $type,
                'buy_in' => $buy_in,
                'closed' => $close,
                'format' => $automated ? $request->post('format') : 'box-fight',
            ]);

            // If automated creation
            if ($automated) {
                $match = FortniteSoloMatch::whereId($create->id)->first();

                foreach ($request->post('players') as $player) {
                    FortniteSoloMatchToUser::create([
                        'user_id' => $player['user_id'],
                        'match_id' => $match->id,
                        'accepted' => true,
                        'ready' => true
                    ]);
                }

                $log = new MatchLog([
                    'player_id' => auth()->id(),
                    'is_service_message' => true,
                    'text' => [
                        'ru' => 'Матч создан.',
                        'en' => 'Match created.',
                    ]
                ]);
            } else { // Wager
                $match = FortniteSoloMatch::with('first_player')
                    ->whereId($create->id)
                    ->first();

                FortniteSoloMatchToUser::create([
                    'user_id' => auth()->id(),
                    'match_id' => $match->id
                ]);

                $log = new MatchLog([
                    'player_id' => auth()->id(),
                    'is_service_message' => false,
                    'text' => [
                        'ru' => 'Игрок ' . auth()->user()->nickname . ' создал матч.',
                        'en' => 'Player ' . auth()->user()->nickname . ' created a match.',
                    ]
                ]);
            }

            $log->loggable()->associate($match)->save();

            DB::commit();

            // Wager
            if (!$automated) {
                event(new NewMatch($match, 'fortnite', 'box-fights', $match->division));
            }

            if ($match->type !== false) {
                return response()->json(['uuid' => $match->uuid, 'message' => 'Матч успешно создан! Мы зарезервировали ' . $match->buy_in . ' Вагер-Поинтов с Вашего счёта']);
            }

            // Wager
            if (!$automated) {
                return response()->json(['password' => $password, 'uuid' => $match->uuid, 'message' => __('hubs.match_created_successfully')]);
            }
            return ['password' => $password, 'uuid' => $match->uuid, 'message' => __('hubs.match_created_successfully')];

        } catch (\Exception $exception) {
            DB::rollBack();
            Log::error($exception->getMessage());
            return response()->json(['message' => __('messages.general_error')], $this->errorStatus);
        }
    }

    /**
     * Cancel match
     *
     * @param $id
     * @return JsonResponse
     */
    public function cancel($id): JsonResponse
    {
        try {
            $match = FortniteSoloMatch::find($id);

            if(auth()->user()->hasRole(['moderator', 'admin'])) {
                $match->update([
                    'status' => 'canceled',
                    'canceled_by' => auth()->id(),
                    'needs_to_moderate' => false
                ]);
            }

            if ($match->first_player === auth()->id() || $match->second_player === auth()->id()) {
                $match->update(['status' => 'canceled']);
            }

            $log = new MatchLog([
                'is_service_message' => true,
                'text' => [
                    'ru' => 'Игроки отменили матч.',
                    'en' => 'Players have been cancelled match.',
                ]
            ]);

            $log->loggable()->associate($match)->save();

            event(new CancelMatch($id, 'fortnite', 'box-fights', $match->division));
            event(new CancelSingleMatch($match->uuid));
            return response()->json(['message' => 'Матч отменен']);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
            return response()->json(['error' => __('messages.general_error')], $this->errorStatus);
        }

    }

    /**
     * Revoke match
     *
     * @param $id
     * @return JsonResponse
     */
    public function revoke($id): JsonResponse
    {
        try {
            $match = FortniteSoloMatch::with('first_player', 'second_player')->find($id);

            if (!auth()->user()->hasRole(['moderator', 'admin'])) {
                return response()->json(['error' => __('messages.no_permissions')], $this->accessDeniedStatus);
            }

            $match->update([
                'status' => 'revoked',
                'revoked_by' => auth()->id(),
                'needs_to_moderate' => false
            ]);

            $winner = User::find($match->winner_id);
            $loser = User::find( ($match->first_player === $match->winner_id) ? $match->second_player : $match->first_player );

            $winnerMatch = FortniteSoloMatchToUser::where('user_id', '=', $winner->id)->where('match_id', '=', $match->id)->first();
            $loserMatch = FortniteSoloMatchToUser::where('user_id', '!=', $winner->id)->where('match_id', '=', $match->id)->first();

            $firstRating = $winner->seasonStats()
                ->where('season_id', '=', (new RatingController())->getCurrentSeason()->id)
                ->where('section', '=', 'box_fights')
                ->first();

            $firstRating->update([
                'rating' => ($firstRating->user_id === $winner->id) ? ($firstRating->rating - $winnerMatch->rating) : ($firstRating->rating + $winnerMatch->rating),
                'total' => $firstRating->total - 1,
                'wins' => $firstRating->wins - 1
            ]);

            $secondRating = $loser->seasonStats()
                ->where('season_id', '=', (new RatingController())->getCurrentSeason()->id)
                ->where('section', '=', 'box_fights')
                ->first();

            $secondRating->update([
                'rating' => ($secondRating->user_id === $winner->id) ? ($secondRating->rating - $loserMatch->rating) : ($secondRating->rating + $loserMatch->rating),
                'total' => $secondRating->total - 1,
                'loses' => $secondRating->loses - 1
            ]);

            // Season pass level points
            $SPController = new SeasonPassController();
            // Winner
            $SPController->updateUserPoints($winner->id, -$this->__SP_POINTS_WINNER);
            // Loser
            $SPController->updateUserPoints($loser->id, -$this->__SP_POINTS_LOOSER);

            return response()->json(['message' => 'Матч отменен']);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
            return response()->json(['error' => __('messages.general_error')], $this->errorStatus);
        }
    }

    /**
     * Get player active matches
     *
     * @return JsonResponse
     */
    public function active(): JsonResponse
    {
        $matches = [];

        if(auth('api')->user()) {
            $matches = FortniteSoloMatch::whereNotIn('status', ['completed', 'canceled', 'revoked'])
                ->join('fortnite_solo_match_to_users', 'fortnite_solo_match_to_users.match_id', '=', 'fortnite_solo_matches.id')
                ->where('fortnite_solo_match_to_users.user_id', '=', auth('api')->user()->id)
                ->get();
        }

        return response()->json([
            'matches' => $matches
        ]);
    }

    /**
     * Get single match
     *
     * @param $uuid
     * @return JsonResponse
     */
    public function get($uuid): JsonResponse
    {
        $match = FortniteSoloMatch::with('first_player', 'second_player', 'log')
            ->whereUuid($uuid)
            ->first();

        if (!$match) {
            return response()->json(['error' => __('messages.general_error')], $this->errorStatus);
        }

        return response()->json($match);
    }

    /**
     * Join match api
     *
     * @param Request $request
     * @param $id
     * @return JsonResponse
     */
    public function join(Request $request, $id): JsonResponse
    {
        $match = FortniteSoloMatch::find($id);

        if (!isset($match->id)) {
            return response()->json(['error' => __('messages.general_error')], $this->notFoundStatus);
        }

        if (isset($match->second_player)) {
            return response()->json(['error' => 'Все слоты заняты'], $this->errorStatus);
        }

        if (!auth()->user()->epic_id) {
            return response()->json(['error' => 'Пожалуйста привяжите аккаунт Epic Games в настройках профиля!'], $this->errorStatus);
        }

        $otherMatch = FortniteSoloMatchToUser::join('fortnite_solo_matches', 'fortnite_solo_matches.id', '=', 'fortnite_solo_match_to_users.match_id')
            ->where('fortnite_solo_match_to_users.user_id', '=', auth()->id())
            ->whereNotIn('fortnite_solo_matches.status', $this->ignore_status)
            ->first();

        if ($otherMatch) {
            return response()->json(['error' => 'Вы уже участвуете в другом матче'], $this->errorStatus);
        }

        if (isset($match->password)) {
            if (($match->password !== null) && $request->post('password') !== $match->password) {
                return response()->json(['error' => 'Пароль к матчу введён не верно'], $this->errorStatus);
            }
        }

        // @todo Раскомментировать после тестирования (Списание Wager-поинтов, если матч платный с игроков)
        if (isset($match->type)) {
            if ($match->type === true) {
                $creator_wager_points = User::whereId($match->first_player)->value('wager_points');
                User::whereId($match->first_player)->update([
                    'wager_points' => $creator_wager_points - $match->buy_in
                ]);
                User::whereId(auth()->id())->update([
                    'wager_points' => auth()->user()->wager_points - $match->buy_in
                ]);
            }
        }

        DB::beginTransaction();

        FortniteSoloMatchToUser::create([
            'user_id' => auth()->id(),
            'match_id' => $match->id
        ]);

        $match->update([
            'status' => 'confirmation',
            'second_player' => auth()->id()
        ]);

        event(new NewPlayerInSingleMatch($match->uuid, auth()->user()));
        event(new ChangeStatusSingleMatch($match->uuid, 'confirmation'));

        event(new ChangeStatus($match->id, auth()->user(), 'confirmation', 'fortnite', 'box-fights', $match->division));

        $log = new MatchLog([
            'player_id' => auth()->id(),
            'is_service_message' => false,
            'text' => [
                'ru' => 'Игрок ' . auth()->user()->nickname . ' присоединился к матчу.',
                'en' => 'Player ' . auth()->user()->nickname . ' joined a match.',
            ]
        ]);

        $log->loggable()->associate($match)->save();

        DB::commit();

        return response()->json(['type' => 'success', 'message' => __('hubs.ready_for_match')]);
    }

    /**
     * Set player as ready for match
     *
     * @param $id
     * @return JsonResponse
     */
    public function ready($id): JsonResponse
    {
        try {
            $match = FortniteSoloMatch::find($id);

            $match_to_user = FortniteSoloMatchToUser::where('match_id', $id)
                ->where('user_id', auth()->id())
                ->first();

            DB::beginTransaction();

            $match_to_user->update([
                'ready' => true,
            ]);

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

            $other_player = FortniteSoloMatchToUser::where('match_id', $id)
                ->where('user_id', '!=', auth()->id())
                ->first();

            if ($other_player->ready) {

                //@todo Раскомментировать после тестирования
                if ($match->type === true) {
                    $creator_wager_points = User::whereId($match->first_player)->value('wager_points');
                    User::whereId($match->first_player)->update([
                        'wager_points' => $creator_wager_points - $match->buy_in
                    ]);
                    User::whereId(auth()->id())->update([
                        'wager_points' => auth()->user()->wager_points - $match->buy_in
                    ]);
                }

                $log = new MatchLog([
                    'player_id' => auth()->id(),
                    'is_service_message' => true,
                    'text' => [
                        'ru' => 'Все игроки приготовились к началу матча.',
                        'en' => 'Players are ready to play.',
                    ]
                ]);

                $log->loggable()->associate($match)->save();

                event(new NewLog($match->uuid, $log));

                $match->update([
                    'status' => 'ongoing'
                ]);

                $log = new MatchLog([
                    'player_id' => auth()->id(),
                    'is_service_message' => true,
                    'text' => [
                        'ru' => 'Матч запущен. Желаем Вам приятной игры!',
                        'en' => 'Match is ready. Wish you luck!',
                    ]
                ]);

                $log->loggable()->associate($match)->save();

                event(new NewLog($match->uuid, $log));

                event(new ChangeStatus($match->id, null, 'ongoing', 'fortnite', 'box-fights', $match->division));
                event(new ChangeStatusSingleMatch($match->uuid, 'ongoing'));
            }

            DB::commit();

            return response()->json(['message' => __('hubs.ready_for_match')]);
        } catch (\Exception $exception) {
            return response()->json(['message' => __('hubs.general_error')], $this->errorStatus);
        }
    }

    /**
     * Get list of 15 last completed matches
     *
     * @return JsonResponse
     */
    public function history(): JsonResponse
    {
        $matches = FortniteSoloMatch::with('first_player', 'second_player')
            ->where('status', '=', 'completed')
            ->orderBy('created_at', 'DESC')
            ->skip(0)
            ->take(30)
            ->get();

        return response()->json($matches, 200);
    }

    /**
     * Calculate points and then assign them to players. Also sends notifications
     *
     * @param $match_id
     */
    public function assignPointsAndNotifyPlayers($match_id): void
    {
        $match = FortniteSoloMatch::find($match_id);

        // Score points to winner
        $user_winner = User::find($match->winner_id);

        if ($match->is_wager) {
            $user_winner->update([
                'account_points' => $user_winner->account_points + $this->__LVL_POINTS['wager'],
//                'wager_points' => $user_winner->wager_points + 5
            ]);
        } else {
            $user_winner->update([
                'account_points' => $user_winner->account_points + $this->__LVL_POINTS['box-fight'],
            ]);
        }

        // Notify winner
        $notification = UserNotification::create([
            'user_id' => $user_winner->id,
            'category' => 'hubs',
            'title' => [
                'ru' => 'Победа в матче!',
                'en' => "You won a match!"
            ],
            'text' => [
                'ru' => 'Поздравляем, Вы одержали победу в матче и мы начислили Вам очки!',
                'en' => 'Congrats, you won a match and we have scored points to your account'
            ],
        ]);

        event(new NewNotification($user_winner->uuid, $notification));

        $winner = FortniteSoloMatchToUser::where([
            ['user_id', '=', $user_winner->id],
            ['match_id', '=', $match->id]
        ])
            ->first();
        $loser = FortniteSoloMatchToUser::where([
            ['user_id', '!=', $user_winner->id],
            ['match_id', '=', $match->id]
        ])
            ->first();

        $user_loser = User::find($loser->user_id);

        if ($match->is_wager) {
            $user_loser->update([
                'account_points' => $user_loser->account_points + $this->__LVL_POINTS['wager'],
//                'wager_points' => $user_loser_new_balance >= 0 ? $user_loser_new_balance : 0
            ]);
        } else {
            $user_loser->update([
                'account_points' => $user_loser->account_points + $this->__LVL_POINTS['box-fight'],
            ]);
        }

        // Notify loser
        $notification = UserNotification::create([
            'user_id' => $user_loser->id,
            'category' => 'hubs',
            'title' => [
                'ru' => 'Поражение в матче.',
                'en' => "You lose a match."
            ],
            'text' => [
                'ru' => 'Сожалеем, но Вы проиграли матч и у вас списались очки за поражение.',
                'en' => 'Sorry, but you lose a match and points have been taken away from your account.'
            ],
        ]);

        event(new NewNotification($user_loser->uuid, $notification));

        // Calculate and store new players ratings
        $calculator = new RatingCalculator();

        $user_winner_rating = $user_winner->seasonStats()
            ->where('season_id', '=', (new RatingController())->getCurrentSeason()->id)
            ->where('section', '=', 'box_fights')
            ->value('rating');

        $user_loser_rating = $user_loser->seasonStats()
            ->where('season_id', '=', (new RatingController())->getCurrentSeason()->id)
            ->where('section', '=', 'box_fights')
            ->value('rating');

        $player1 = new Rating($calculator, $user_winner_rating, $this->ratingDeviation);
        $player2 = new Rating($calculator, $user_loser_rating, $this->ratingDeviation);

        $results = new RatingPeriodResults();

        $results->addResult($player1, $player2);

        $calculator->updateRatings($results);

        $season = new RatingController();

        foreach (array($player1, $player2) as $index => $player) {
            $rating = round($player->getRating());
            $ratingHistoryData = new PlayerRating([
                'rating' => $rating,
                'discipline' => 'fortnite',
                'division' => $match->division,
                'type' => 'box_fights'
            ]);

            // Winner
            if ($index === 0) {
                $user_winner->ratingsHistory()->save($ratingHistoryData);
                $user_winner->seasonStats()->updateOrCreate(
                    [
                        'season_id' => $season->getCurrentSeason()->id,
                        'section' => 'box_fights',
                        'game' => 'fortnite'
                    ],
                    [
                        'wins' => DB::raw('wins + 1'),
                        'total' => DB::raw('total + 1'),
                        'rating' => $rating,
                    ]
                );

                $winner->update([
                    'rating' => ($rating - $user_winner_rating),
                ]);
            } else {
                // Loser
                $user_loser->ratingsHistory()->save($ratingHistoryData);
                $user_loser->seasonStats()->updateOrCreate(
                    [
                        'season_id' => $season->getCurrentSeason()->id,
                        'section' => 'box_fights',
                        'game' => 'fortnite'
                    ],
                    [
                        'loses' => DB::raw('loses + 1'),
                        'total' => DB::raw('total + 1'),
                        'rating' => $rating,
                    ]
                );

                $loser->update([
                    'rating' => ($user_loser_rating - $rating),
                ]);
            }
        }

        // Season pass level points
        $SPController = new SeasonPassController();
        // Winner
        $SPController->updateUserPoints($user_winner->id, $this->__SP_POINTS_WINNER);
        // Loser
        $SPController->updateUserPoints($user_loser->id, $this->__SP_POINTS_LOOSER);
    }

    /**
     * Send result
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendResult(Request $request): JsonResponse
    {
        $win_id = $request->post('win_id');
        $match_id = $request->post('match_id');

        $match = FortniteSoloMatch::find($match_id);

        // If user has role of admin or moderator and isn't a participant of match (cause its cheating :D)
        if (
            auth()->user()->hasRole(['admin', 'moderator'])
            && $match->first_player !== auth()->id()
            && $match->second_player !== auth()->id()
        ) {
            $suggest_winner = FortniteSoloMatchToUser::with('user')
                ->where([
                    ['user_id', '=', $win_id],
                    ['match_id', '=', $match_id]
                ])
                ->first();

            // Update match
            $match->update([
                'status' => 'completed',
                'closed_by' => auth()->id(),
                'winner_id' => $win_id,
                'played_at' => Carbon::now(),
                'needs_to_moderate' => false
            ]);


            $log = new MatchLog([
                'player_id' => auth()->id(),
                'is_service_message' => true,
                'text' => [
                    'ru' => 'Модератор ' . auth()->user()->nickname . ' завершил матч. Победа игрока ' . $suggest_winner->user->nickname,
                    'en' => 'Moderator ' . auth()->user()->nickname . ' has finished match. Player ' . $suggest_winner->user->nickname . ' has won.',
                ]
            ]);

            $log->loggable()->associate($match)->save();

            event(new NewLog($match->uuid, $log));
            event(new ChangeStatus($match_id, null, 'completed', 'fortnite', 'box-fights', $match->division));
            event(new ChangeStatusSingleMatch($match->uuid, 'completed'));

            $this->assignPointsAndNotifyPlayers($match->id);

            return response()->json(['message' => __('hubs.closed_by_moderator')]);
        }

        $self = FortniteSoloMatchToUser::with('user')
            ->where([
                ['user_id', '=', auth()->id()],
                ['match_id', '=', $match_id]
            ])
            ->first();

        $other_player = FortniteSoloMatchToUser::with('user')
            ->where([
                ['user_id', '!=', auth()->id()],
                ['match_id', '=', $match_id]
            ])
            ->first();

        // @todo Раскомментировать после тестирования (Пополнение Вагер-поинтов победителю, если матч платный)
        if ($match->type === true) {
            $b_point_prize = ApplyPercentageHelper::discount(($match->buy_in) * 2, 10);
            $current_winner_wager_points = User::whereId($win_id)->value('wager_points');
            User::whereId($win_id)->update([
                'wager_points' => ($b_point_prize + $current_winner_wager_points)
            ]);
        }

        $self->update([
            'winner' => $win_id,
            'winner_picked_at' => Carbon::now()
        ]);

        // If other player can send result
        if (!$self->winner || !$other_player->winner) {

            $log = new MatchLog([
                'player_id' => auth()->id(),
                'is_service_message' => false,
                'text' => [
                    'ru' => ($win_id === auth()->id() ?
                        'Игрок ' . auth()->user()->nickname . ' отметил себя победителем.' :
                        'Игрок ' . auth()->user()->nickname . ' отметил игрока ' . $other_player->user->nickname . ' победителем.'),
                    'en' => ($win_id === auth()->id() ?
                        'Player ' . auth()->user()->nickname . ' has choose himself as winner.' :
                        'Player ' . auth()->user()->nickname . ' has choose ' . $other_player->user->nickname . ' as winner.'),
                ]
            ]);

            $log->loggable()->associate($match)->save();

            event(new NewLog($match->uuid, $log));

            return response()->json(['message' => __('hubs.result_stored')]);
        }

        // If results are the same than close match
        if ($self->winner === $other_player->winner) {

            $log = new MatchLog([
                'player_id' => auth()->id(),
                'is_service_message' => false,
                'text' => [
                    'ru' => ($win_id === auth()->id() ?
                        'Игрок ' . auth()->user()->nickname . ' отметил себя победителем.' :
                        'Игрок ' . auth()->user()->nickname . ' отметил игрока ' . $other_player->user->nickname . ' победителем.'),
                    'en' => ($win_id === auth()->id() ?
                        'Player ' . auth()->user()->nickname . ' has choose himself as winner.' :
                        'Player ' . auth()->user()->nickname . ' has choose ' . $other_player->user->nickname . ' as winner.'),
                ]
            ]);

            $log->loggable()->associate($match)->save();
            event(new NewLog($match->uuid, $log));

            // Update match
            $match->update([
                'status' => 'completed',
                'winner_id' => $win_id,
                'played_at' => Carbon::now(),
                'needs_to_moderate' => false
            ]);

            $log = new MatchLog([
                'player_id' => auth()->id(),
                'is_service_message' => true,
                'text' => [
                    'ru' => 'Матч завершен. Победа игрока ' . User::find($win_id)->value('nickname'),
                    'en' => 'Match is over. Player ' . User::find($win_id)->value('nickname') . ' has won the match.',
                ]
            ]);

            $log->loggable()->associate($match)->save();

            event(new NewLog($match->uuid, $log));
            event(new ChangeStatus($match_id, null, 'completed', 'fortnite', 'box-fights', $match->division));
            event(new ChangeStatusSingleMatch($match->uuid, 'completed'));

            $this->assignPointsAndNotifyPlayers($match->id);

            return response()->json(['message' => __('hubs.match_completed')]);
        }

        $log = new MatchLog([
            'player_id' => auth()->id(),
            'is_service_message' => false,
            'text' => [
                'ru' => ($win_id === auth()->id() ?
                    'Игрок ' . auth()->user()->nickname . ' отметил себя победителем.' :
                    'Игрок ' . auth()->user()->nickname . ' отметил игрока ' . $other_player->user->nickname . ' победителем.'),
                'en' => ($win_id === auth()->id() ?
                    'Player ' . auth()->user()->nickname . ' has choose himself as winner.' :
                    'Player ' . auth()->user()->nickname . ' has choose ' . $other_player->user->nickname . ' as winner.'),
            ]
        ]);

        $log->loggable()->associate($match)->save();
        event(new NewLog($match->uuid, $log));

        // Update match
        $match->update([
            'needs_to_moderate' => true
        ]);

        $log = new MatchLog([
            'player_id' => auth()->id(),
            'is_service_message' => true,
            'text' => [
                'ru' => 'Результаты отличаются! Измените результат или загрузите скриншот игры и вызовите модератора.',
                'en' => 'Results are different! Change result or upload match screenshot and call for support.',
            ]
        ]);

        $log->loggable()->associate($match)->save();
        event(new NewLog($match->uuid, $log));

        return response()->json(['message' => __('hubs.results_different')]);
    }

    /**
     * Send screenshot api
     *
     * @param Request $request
     * @return JsonResponse
     * @throws ValidationException
     */
    public function uploadScreenshot(Request $request): JsonResponse
    {
        Validator::make($request->all(), [
            'screenshot' => 'required|file|max:100000|mimes:jpeg,png,bmp,jpg',
            'match_id' => 'required|integer|exists:App\Models\Hubs\Fortnite\FortniteSoloMatch,id',
        ])->validate();

        $match = FortniteSoloMatchToUser::where('match_id', $request->get('match_id'))
            ->where('user_id', auth()->id())
            ->first();

        $file = $request->file('screenshot');

        $fileName = $match->user->nickname . '_' . Carbon::now()->timestamp . '_' . $match->match->uuid . '.' . $file->getClientOriginalExtension();

        $store = Storage::disk('public')->put('/uploads/match_results/' . $fileName, file_get_contents($file));

        if ($store) {
            $url = '/storage/uploads/match_results/' . $fileName;

            $match->update([
                'screenshot' => $url,
            ]);

            $url_to_event = '//' . $_SERVER['HTTP_HOST'] . $url;

            event(new NewScreenshot($match->match->uuid, auth()->id(), $url_to_event));

            $log = new MatchLog([
                'player_id' => auth()->id(),
                'is_service_message' => false,
                'text' => [
                    'ru' => 'Игрок ' . auth()->user()->nickname . ' загрузил скриншот',
                    'en' => 'Player ' . auth()->user()->nickname . ' has upload a screenshot',
                ]
            ]);

            $log->loggable()->associate($match->match)->save();

            event(new NewLog($match->match->uuid, $log));

            return response()->json(['message' => __('hubs.screenshot_saved_successfully')]);
        }

        return response()->json(['error' => __('hubs.screenshot_saved_error')], $this->errorStatus);
    }

    public function updateSearchCounters($game, $division): void
    {
        $searching = SearchLobby::where('game', '=', $game)
            ->where('division', '=', $division)
            ->get()
            ->groupBy('user_id')
            ->count();

        $playing = FortniteSoloMatch::whereNotIn('status', $this->ignore_status)
            ->where('division', '=', $division)
            ->count();

        event(new SearchUpdated($game, $division, $searching, ($playing * 2)));
    }

    public function registerToSearchLobby(Request $request): JsonResponse
    {
        if (!auth()->user()->epic_id) {
            return response()->json(['error' => 'Для доступа к поиску игры, пожалуйста привяжите аккаунт Epic Games в настройках профиля!'], 400);
        }

        $alreadyPlaying = FortniteSoloMatchToUser::join('fortnite_solo_matches', 'fortnite_solo_matches.id', '=', 'fortnite_solo_match_to_users.match_id')
            ->where('fortnite_solo_match_to_users.user_id', '=', auth()->id())
            ->whereNotIn('fortnite_solo_matches.status', $this->ignore_status)
            ->first();

        if ($alreadyPlaying) {
            return response()->json(['error' => 'Вы уже участвуете в другом матче'], $this->errorStatus);
        }

        $division = str_replace('-', '_', $request->post('division'));

        $lobbies = [];

        $ranks = (new User())->RANKS;

        $levels = $ranks['fortnite'][str_replace('-', '_', 'box_fights')];
        $skill_group = null;
        $userStats = auth()->user()->seasonStats()
            ->firstOrCreate(
                [
                    'season_id' => (new RatingController())->getCurrentSeason()->id,
                    'section' => 'box_fights',
                    'game' => 'fortnite'
                ],
                [
                    'wins' => 0,
                    'loses' => 0,
                    'total' => 0,
                    'rating' => 1000
                ]);

        foreach($levels as $key => $level) {
            if($level['max'] >= $userStats->rating && $level['min'] <= $userStats->rating) {
                $skill_group = $key;
            }
        }

        foreach ($request->post('formats') as $format) {
            $map = '';

            if($format === 'Box-fight') {
                $map = 'Clix (7620-0771-9529)';
            }
            if($format === 'Realistic') {
                $map = 'Finestyt (7950-6306-4857)';
            }

            $lobbies[] = [
                'rating' => $userStats->rating,
                'skill_group' => $skill_group,
                'format' => $format,
                'map' => $map,
                'division' => $division,
                'game' => 'fortnite',
            ];
        }

        auth()->user()->searchLobbies()->createMany($lobbies);

        Artisan::call('matches:search');

        $this->updateSearchCounters('fortnite', $request->post('division'));

        return response()->json([
            'message' => __('hubs.search_registered_successfully'),
        ]);
    }

    public function cancelSearchLobby(): JsonResponse
    {
        $division = auth()->user()->searchLobbies()->first() ? auth()->user()->searchLobbies()->first()->division : null;

        if($division) {
            auth()->user()->searchLobbies()->forceDelete();
            $this->updateSearchCounters('fortnite', $division);
        }

        return response()->json([
            'message' => __('hubs.search_cancel_successfully'),
        ], 200);
    }

    public function acceptMatch(): JsonResponse
    {
        $userMatchLobby = auth()->user()->beforeMatchLobby;

        if($userMatchLobby) {
            $opponent = $userMatchLobby->opponent;
            $opponentMatchLobby = $opponent->beforeMatchLobby;

            $division = $userMatchLobby->lobby->division;
            $map = $userMatchLobby->lobby->map;
            $format = $userMatchLobby->lobby->format;

            $userMatchLobby->update([
                'confirmed' => true,
            ]);

            if ($userMatchLobby->confirmed && $opponentMatchLobby && $opponentMatchLobby->confirmed) {
                // Remove players from search
                auth()->user()->searchLobbies()->forceDelete();
                $opponent->searchLobbies()->forceDelete();

                // Delete before match lobby
                auth()->user()->beforeMatchLobby()->delete();
                $opponent->beforeMatchLobby()->delete();
                auth()->user()->beforeMatchLobby->lobby->delete();

                $request = new Request();
                $request->setMethod('POST');
                $request->request->add([
                    'division' => $division,
                    'map_name' => $map,
                    'format' => $format,
                    'players' => [
                        [
                            'user_id' => auth()->id()
                        ],
                        [
                            'user_id' => $opponent->id
                        ],
                    ]
                ]);

                $match = $this->create($request, true);

                event(new AutomatedMatchCreated(auth()->user()->uuid, 'fortnite', $division, $match['uuid'], $match['message']));
                event(new AutomatedMatchCreated($opponent->uuid, 'fortnite', $division, $match['uuid'], $match['message']));
            }

            $this->updateSearchCounters('fortnite', $division);
        }

        return response()->json([
            'message' => __('hubs.match_confirmed'),
        ]);
    }

    public function declineMatch(): JsonResponse
    {
        $userMatchLobby = auth()->user()->beforeMatchLobby;

        if($userMatchLobby) {
            $opponent = $userMatchLobby->opponent;
            $opponentMatchLobby = $opponent->beforeMatchLobby;

            $game = $userMatchLobby->lobby->game;
            $division = $userMatchLobby->lobby->division;

            $userMatchLobby->delete();
            if($opponentMatchLobby) {
                $opponentMatchLobby->delete();
            }
            if($userMatchLobby->lobby) {
                $userMatchLobby->lobby->delete();
            }

            // Store opponents ids to avoid selection between these 2 players in future and restore their searching queues
            // First player
            $player_1_lobbies = SearchLobby::onlyTrashed()
                ->where('user_id', '=', auth()->id());

            $player_1_list = $player_1_lobbies->count() ? $player_1_lobbies->get()[0]->ignore : [];
            if(!$player_1_list || !in_array($opponent->id, $player_1_list)) {
                $player_1_list[] = $opponent->id;
            }

            $player_1_lobbies->update([
                'ignore' => $player_1_list
            ]);
            auth()->user()->searchLobbies()->restore();

            // Second player
            $player_2_lobbies = SearchLobby::onlyTrashed()
                ->where('user_id', '=', $opponent->id);

            $player_2_list = $player_2_lobbies->count() ? $player_2_lobbies->get()[0]->ignore : [];
            if(!$player_2_list || !in_array(auth()->id(), $player_2_list)) {
                $player_2_list[] = auth()->id();
            }

            $player_2_lobbies->update([
                'ignore' => $player_2_list
            ]);
            $opponent->searchLobbies()->restore();

            event(new BeforeMatchLobbyDeleted(auth()->user()->uuid, $game, $division, __('hubs.before_match_lobby_closed')));
            event(new BeforeMatchLobbyDeleted($opponent->uuid, $game, $division, __('hubs.before_match_lobby_closed')));

            $this->updateSearchCounters('fortnite', $division);
        }

        return response()->json([
            'message' => __('hubs.before_match_lobby_closed'),
        ]);
    }

    public function getPlayersCounters($game, $division): JsonResponse
    {
        $searching = SearchLobby::where('game', '=', $game)
            ->where('division', '=', $division)
            ->get()
            ->groupBy('user_id');

        $playing = FortniteSoloMatch::whereNotIn('status', $this->ignore_status)
            ->where('division', '=', $division)
            ->count();

        $self = 0;
        $otherMatch = 0;
        $before_match_lobby = null;

        if (auth('api')->id()) {
            $self = SearchLobby::where('game', '=', $game)
                ->where('division', '=', $division)
                ->where('user_id', auth('api')->user()->id)
                ->count();
            $before_match_lobby = BeforeMatchLobbyToUser::with('lobby')
                ->where('user_id', '=', auth('api')->user()->id)
                ->first();

            $otherMatch = FortniteSoloMatchToUser::join('fortnite_solo_matches', 'fortnite_solo_matches.id', '=', 'fortnite_solo_match_to_users.match_id')
                ->where('fortnite_solo_match_to_users.user_id', '=', auth('api')->user()->id)
                ->whereNotIn('fortnite_solo_matches.status', $this->ignore_status)
                ->get()
                ->count();
        }

        return response()->json([
            'searching' => count($searching),
            'playing' => $playing * 2,
            'already_searching' => $self > 0,
            'already_playing' => $otherMatch > 0,
            'before_match_lobby' => $before_match_lobby ? $before_match_lobby->lobby->uuid : null,
        ]);
    }

    public function getBeforeMatchLobbyInfo($uuid): JsonResponse
    {
        $lobby = BeforeMatchLobby::with('players')->whereUuid($uuid)->first();

        if (!$lobby) {
            return response()->json([
                'message' => __('hubs.not_found')
            ], 404);
        }

        if (auth()->id() !== $lobby->players[0]->user_id && auth()->id() !== $lobby->players[0]->opponent_id) {
            return response()->json([
                'message' => __('hubs.no_rights')
            ], 403);
        }

        $opponent = [];

        foreach ($lobby->players as $player) {
            if (auth()->id() === $player->user_id) {
                $opponent = User::find($player->opponent_id);
            }
        }

        return response()->json([
            'lobby' => $lobby,
            'opponent' => $opponent
        ]);
    }

    /**
     * Call for moderate
     *
     * @param $id
     * @return JsonResponse
     */
    public function moderate($id): JsonResponse
    {
        $match = FortniteSoloMatch::with('first_player', 'second_player')->find($id);

        $match->update([
            'status' => 'under_moderation',
            'needs_to_moderate' => true
        ]);

        event(new ModerateMatch($match->uuid));

        $log = new MatchLog([
            'player_id' => auth()->id(),
            'is_service_message' => true,
            'text' => [
                'ru' => 'Игрок ' . auth()->user()->nickname . ' вызвал модератора.',
                'en' => 'Player ' . auth()->user()->nickname . ' has called for moderator.',
            ]
        ]);

        $log->loggable()->associate($match)->save();

        event(new NewLog($match->uuid, $log));

        // Send notification to moderators
        event(new NewMatchForModerate($match, 'box_fights'));

        return response()->json(['message' => __('hubs.request_for_moderate_sent')]);
    }

    /**
     * Call for moderate
     *
     * @param $id
     * @return JsonResponse
     */
    public function cancelRequest($id): JsonResponse
    {
        $match = FortniteSoloMatch::with('first_player', 'second_player', 'log')->find($id);

        $player = FortniteSoloMatchToUser::where('match_id', '=', $match->id)
            ->where('user_id', '=', auth()->id())
            ->first();

        $player->update(['cancel_request' => true]);

        event(new CancelRequest($match));

        $log = new MatchLog([
            'player_id' => auth()->id(),
            'is_service_message' => true,
            'text' => [
                'ru' => 'Игрок ' . auth()->user()->nickname . ' предложил отменить матч.',
                'en' => 'Player ' . auth()->user()->nickname . ' propose for match cancellation.',
            ]
        ]);

        $log->loggable()->associate($match)->save();
        event(new NewLog($match->uuid, $log));

        $totalRequests = FortniteSoloMatchToUser::where('match_id', '=', $match->id)
            ->where('cancel_request', '=', true)
            ->count();

        if($totalRequests === 2) {
            return $this->cancel($match->id);
        }

        return response()->json(['message' => __('hubs.request_for_cancel_sent')]);
    }
}
