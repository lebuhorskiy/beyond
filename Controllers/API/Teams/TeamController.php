<?php

namespace App\Http\Controllers\API\Teams;

use App\Events\Teams\NewPrivateMessage;
use App\Http\Controllers\Controller;
use App\Models\Teams\Team;
use App\Models\Teams\TeamToUser;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TeamController extends Controller
{

    /**
     * Teams's list api
     *
     * @return JsonResponse
     */
    public function list(): JsonResponse
    {
        $teams = Team::with('participants', 'owner')->paginate();

        if (!$teams) {
            return response()->json([
                'error' => __('messages.teams_not_found'),
                'success' => false
            ], $this->notFoundStatus);
        }

        return response()->json([
            'data' => $teams,
            'success' => true
        ]);
    }

    /**
     * Team get by id api
     *
     * @param $id
     * @return JsonResponse
     */
    public function getById($id): JsonResponse
    {
        $team = Team::with('participants', 'owner')->whereId($id)->first();

        if (!$team) {
            return response()->json([
                'error' => __('messages.team_not_found'),
                'success' => false
            ], $this->notFoundStatus);
        }

        return response()->json([
            'data' => $team,
            'success' => true
        ]);
    }

    /**
     * Team delete api
     *
     * @param $id
     * @return JsonResponse
     */
    public function deleteById($id): JsonResponse
    {
        $team = Team::whereId($id)->where('user_id', '=', auth()->id())->delete();

        if (!$team) {
            return response()->json([
                'error' => __('messages.team_not_found'),
                'success' => false
            ], $this->notFoundStatus);
        }

        return response()->json([
            'success' => true
        ]);
    }

    /**
     * Team create api
     *
     * @param Request $request
     * @return JsonResponse
     * @throws ValidationException
     */
    public function create(Request $request): JsonResponse
    {
        Validator::make($request->all(), [
            'name' => 'required|min:2',
            'game' => 'required',
        ])->validate();

        Team::create(
            [
                'user_id' => auth()->id(),
                'name' => $request->post('name'),
                'description' => $request->post('description', null),
                'logo' => $request->post('logo', null),
                'game' => $request->post('game', null),
            ]
        );
        return response()->json([
            'success' => true
        ]);
    }

    /**
     * Team update api
     *
     * @param Request $request
     * @param $id
     * @return JsonResponse
     */
    public function update(Request $request, $id): JsonResponse
    {
        Team::whereId($id)
            ->where('user_id', '=', auth()->id())
            ->update(
                [
                    'name' => $request->post('name'),
                    'description' => $request->post('description', null),
                    'logo' => $request->post('logo', null),
                    'game' => $request->post('game', null),
                ]
            );
        return response()->json([
            'success' => true
        ]);
    }

    /**
     * Team send api
     *
     * @param $id
     * @param $joined_id
     * @return JsonResponse
     */
    public function send($id, $joined_id): JsonResponse
    {
        event(new NewPrivateMessage('', $joined_id));
        return response()->json([
            'success' => true
        ]);
    }

    /**
     * Team join api
     *
     * @param $id
     * @return JsonResponse
     */
    public function join($id): JsonResponse
    {
        $user_id = Team::where('id', '=', $id)->value('user_id');

        // Если владелец команды пытается вступить в свою команду
        if ($user_id == auth()->id()) {
            return response()->json([
                'success' => false
            ], $this->errorStatus);
        }

        TeamToUser::updateOrCreate([
            'user_id' => $user_id,
            'team_id' => $id,
            'joined_id' => auth()->id(),
        ], [
            'join_at' => Carbon::now(),
            'status' => 1,
            'user_id' => $user_id,
            'team_id' => $id,
            'joined_id' => auth()->id(),
        ]);
        //event(new NewPrivateMessage('', $user_id));
        return response()->json([
            'success' => true
        ]);
    }

    /**
     * Team quit api
     *
     * @param $id
     * @return JsonResponse
     */
    public function quit($id): JsonResponse
    {
        TeamToUser::where('team_id', '=', $id)->where('joined_id', '=', auth()->id())->delete();
        //event(new NewPrivateMessage('', auth()->id()));
        return response()->json([
            'success' => true
        ]);
    }

}
