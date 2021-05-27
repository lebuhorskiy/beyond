<?php

namespace App\Http\Controllers\API\Killrace;

use App\Http\Controllers\Controller;
use App\Models\KillRace\KillRace;
use App\Models\KillRace\KillRaceToUser;
use App\Models\KillRace\KillRaceToUserStat;
use App\Models\Users\User;
use Illuminate\Http\JsonResponse;

class KillRaceController extends Controller
{

    /**
     * Get list of kill race
     *
     * @return JsonResponse
     */
    public function list(): JsonResponse
    {
        $data = KillRace::with(['stages', 'prizes'])->paginate();
        return response()->json($data);
    }

    /**
     * Get one of kill race
     *
     * @param $id
     * @return JsonResponse
     */
    public function getById($id): JsonResponse
    {
        $data = KillRace::whereId($id)->with(['stages', 'prizes'])->first();
        return response()->json($data);
    }

    /**
     * Get kill race stats
     *
     * @param $id
     * @return JsonResponse
     */
    public function getTableById($id): JsonResponse
    {
        $data = KillRaceToUserStat::where('kill_race_id', '=', $id)->with('user:users.id,name')
            ->orderBy('winners', 'DESC')
            ->get();
        return response()->json($data);
    }

    /**
     * Kill race join
     *
     * @param $id
     * @return JsonResponse
     */
    public function join($id): JsonResponse
    {

        $current = KillRaceToUser::query()
            ->where('user_id', '=', auth()->id())
            ->where('kill_race_id', '=', $id)
            ->first();

        if ($current) {
            return response()->json([
                'message' => __('messages.kill_race_join_error'),
            ]);
        }

        $max = KillRace::where('id', '=', $id)
            ->first();

        if ($max->max_gamers == $max->participants) {
            return response()->json([
                'message' => __('messages.kill_race_join_max_users_error'),
            ]);
        }

        // Добавить 50 B-points за участие

        User::whereId(auth()->id())->update([
            'balance_points' => auth('api')->user()->balance_points + 50
        ]);

        KillRaceToUser::create([
            'user_id' => auth()->id(),
            'kill_race_id' => $id
        ]);
        return response()->json([
            'message' => __('messages.kill_race_join'),
        ]);
    }

    /**
     * Kill race quit
     *
     * @param $id
     * @return JsonResponse
     */
    public function quit($id): JsonResponse
    {
        KillRaceToUser::where('user_id', '=', auth()->id())
            ->where('kill_race_id', '=', $id)
            ->delete();

        KillRaceToUserStat::where('user_id', '=', auth()->id())
            ->where('kill_race_id', '=', $id)->delete();

        // Отменить бонус 50 B-points за участие
        User::whereId(auth()->id())->update([
            'balance_points' => auth('api')->user()->balance_points - 50
        ]);
        return response()->json([
            'message' => __('messages.kill_race_quit'),
        ]);
    }

    /**
     * Get kill race result
     *
     * @param $id
     * @param $stage
     * @return JsonResponse
     */
    public function result($id, $stage): JsonResponse
    {
        $data = KillRaceToUser::where('kill_race_id', '=', $id)->with('participants', 'participants.kill_race_stats')->paginate();
        return response()->json($data);
    }


    /**
     * Kill race create
     *
     * @return JsonResponse
     */
    public function create(): JsonResponse
    {
        KillRace::create(request()->all());
        return response()->json([
            'message' => 'Успешно',
        ]);
    }

    /**
     * Kill race update
     *
     * @param $id
     * @return JsonResponse
     */
    public function update($id): JsonResponse
    {
        KillRace::where('id', '=', $id)
            ->update(request()->all());
        return response()->json([
            'message' => 'Успешно',
        ]);
    }

    /**
     * Kill race delete
     *
     * @param $id
     * @return JsonResponse
     */
    public function delete($id): JsonResponse
    {
        KillRaceToUser::where('kill_race_id', '=', $id)
            ->delete();
        KillRace::where('id', '=', $id)
            ->delete();

        KillRaceToUserStat::where('kill_race_id', '=', $id)->delete();

        return response()->json([
            'message' => 'Kill race удалён',
        ]);
    }

}
