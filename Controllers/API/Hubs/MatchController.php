<?php

namespace App\Http\Controllers\API\Hubs;

use App\Events\Hubs\CancelSingleMatch;
use App\Events\Hubs\CancelMatch;
use App\Events\Hubs\NewLog;
use App\Http\Controllers\Controller;
use App\Models\Hubs\MatchToUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\Hubs\Match;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Events\Hubs\NewMatch;
use Illuminate\Validation\ValidationException;
use App\Models\Hubs\MatchLog;

class MatchController extends Controller
{

    protected $ignore_status = [
        'completed', 'canceled', 'under_moderation', 'revoked', 'archived'
    ];

    /**
     * Get list of 15 newer matches by division and type and status is "checkin" or "ongoing" or "confirmation"
     *
     * @param $discipline
     * @param $division
     * @param $type
     * @return JsonResponse
     */
    public function getMatches($discipline, $division, $type): JsonResponse
    {
        $matches = Match::where([
                ['division', '=', $division],
                ['type_game', '=', $type]
            ])
            ->whereNotIn('status', $this->ignore_status)
            ->orderBy('started_at', 'desc')
            ->limit(15)
            ->get();

        return response()->json([
            'matches' => $matches->reverse()->values()
        ]);
    }

    /**
     * Create match and assign user itself to
     *
     * @param $discipline
     * @param $division
     * @param $type
     * @param Request $request
     * @return JsonResponse
     * @throws ValidationException
     */
    public function createMatch($discipline, $division, $type, Request $request): JsonResponse
    {
        // validation
        Validator::make($request->all(), [
            'format' => 'required|integer',
            'map' => 'required|string',
        ])->validate();

        // check isset match
        $otherMatch = MatchToUser::join('matches', 'matches.id', '=', 'match_to_users.match_id')
            ->where('match_to_users.user_id', '=', auth()->id())
            ->whereNotIn('matches.status', $this->ignore_status)
            ->first();

        if ($otherMatch) {
            return response()->json(['error' => 'Вы уже участвуете в другом матче.'], $this->errorStatus);
        }

        DB::beginTransaction();

        $map = $request->post('map');
        $map = explode(' ' , $map)[1];
        $map = str_replace(array('(', ')'), '', $map);

        // data create
        $createArray = [
            'assign_user_id' => auth()->id(),
            'slots' => (int)$request->input('format'),
            'started_at' => now(),
            'discipline' => $discipline,
            'division' => $division,
            'type_game' => $type,
            'status' => 'checkin',
            'map_name' => $map,
            'current_slots' => 1,
        ];

        // create
        $createMatch = Match::create($createArray);

        if (!$createMatch) {
            return response()->json(['error' => 'Ошибка при создании матча.'], $this->errorStatus);
        }

        // create assign self user
        $createAssign = MatchToUser::create([
            'user_id' => auth()->id(),
            'match_id' => $createMatch->id,
            'accepted' => 0,
        ]);

        // if fail create rollback create match
        if (!$createAssign) {
            DB::rollback();
            return response()->json(['error' => 'Ошибка.'], $this->errorStatus);
        }

        // if all good commit match and send event other users
        DB::commit();

        $match = Match::find($createMatch->id);

        $log = new MatchLog([
            'player_id' => auth()->id(),
            'is_service_message' => false,
            'text' => [
                'ru' => 'Игрок '. auth()->user()->nickname . ' создал матч.',
                'en' => 'Player ' . auth()->user()->nickname . ' has created match.',
            ]
        ]);

        $log->loggable()->associate($match)->save();

        event(new NewLog($match->uuid, $log));

        event(new NewMatch($match, $match->discipline, $match->type_game, $match->division));

        return response()->json(['type' => 'success', 'match' => $match]);
    }

    /**
     * Delete match
     *
     * @param $discipline
     * @param $division
     * @param $type
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteMatch($discipline, $division, $type, Request $request): JsonResponse
    {
        // validate
        $id = (int)$request->get('id');
        if (!$id || gettype($id) != 'integer') {
            return response()->json(['error' => 'Неверный ID матча.'], $this->notFoundStatus);
        }

        // find match
        $find = Match::find($request->get('id'));

        if (!$find) {
            return response()->json(['error' => 'Матч не найден.'], $this->notFoundStatus);
        }

        if ($find->needs_to_moderate && auth()->user()->hasRole(['moderator', 'admin'])) {
            $find->update(['status' => 'canceled']);

            event(new CancelMatch($id, $discipline, $type, $division));
            event(new CancelSingleMatch($find->uuid));

            return response()->json(['message' => 'Матч отменен']);
        }

        $find->update([
            'status' => 'canceled'
        ]);

        event(new CancelMatch($id, $discipline, $type, $division));
        event(new CancelSingleMatch($find->uuid));
        return response()->json(['type' => 'success', 'message' => 'Матч удален.']);
    }

    /**
     * Get player active match
     *
     * @return JsonResponse
     */
    public function getActiveMatch(): JsonResponse
    {
        $matches = Match::whereNotIn('status', $this->ignore_status)
            ->join('match_to_users', 'matches.id', 'match_to_users.match_id')
            ->where('match_to_users.user_id', '=', auth()->id())
            ->orderBy('started_at', 'desc')
            ->get();

        return response()->json([
            'matches' => $matches
        ]);
    }

    /**
     * Get single match
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getMatch(Request $request): JsonResponse
    {
        $uuid = $request->get('uuid');

        if (!$uuid) {
            return response()->json(['error' => 'Неверный ID матча.'], $this->errorStatus);
        }

        $match = Match::with('log')->whereUuid($uuid)->first();

        // check isset match
        if (!$match) {
            return response()->json(['error' => 'Матч не найден.'], $this->notFoundStatus);
        }

        // check user in match
        if($match->slots === 2) {
            $check = MatchToUser::where([
                ['user_id', '=', auth()->id()],
                ['match_id', '=', $match->id]
            ])
                ->exists();

            if (!$check) {
                return response()->json(['error' => 'Ошибка доступа.'], $this->accessDeniedStatus);
            }
        }

        // check status
        if ($match->status === 'completed') {
            return response()->json(['error' => 'Матч завершен.'], $this->errorStatus);
        }

        $responseData = ['type' => 'success', 'status' => $match->status, 'match' => $match, 'players' => $match
            ->players()
            ->with('user')
            ->orderBy('id', 'ASC')
            ->get()
        ];

        return response()->json($responseData);
    }

    /**
     * @param Request $request
     * @return JsonResponse
     */
    public function getResultMatch(Request $request): JsonResponse
    {
        $id = $request->get('id');

        if (!$id) {
            return response()->json(['error' => 'Неверный ID матча.'], $this->errorStatus);
        }

        $match = Match::whereUuid($id)->first();

        $results = MatchToUser::with('user')
            ->where('match_id', '=', $match->id)
            ->orderBy('points', 'desc')
            ->get();

        if (!$results) {
            return response()->json(['error' => 'Матч не найден.'], $this->notFoundStatus);
        }

        return response()->json(['results' => $results, 'match' => $match]);

    }

    /**
     * Get list of 15 last completed matches
     *
     * @param $discipline
     * @param $division
     * @param $type
     * @return JsonResponse
     */
    public function lastMatches($discipline, $division, $type): JsonResponse
    {
        $matches = Match::where([
                ['status', '=', 'completed'],
                ['discipline', '=', $discipline],
                ['division', '=', $division],
                ['type_game', '=', $type]
            ])
            ->with('win', 'lose')
            ->orderBy('closed_at', 'DESC')
            ->skip(0)
            ->take(15)
            ->get();

        return response()->json([
            'matches' => $matches
        ]);
    }
}
