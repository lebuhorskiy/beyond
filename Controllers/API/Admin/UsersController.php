<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\API\SeasonPass\SeasonPassController;
use App\Http\Controllers\Controller;
use App\Models\Hubs\Fortnite\FortniteSoloMatch;
use App\Models\Hubs\Message;
use App\Models\Users\Role;
use App\Models\Users\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Intervention\Image\Facades\Image;

class UsersController extends Controller
{

    /**
     * Get list of users
     *
     * @param $query
     * @return JsonResponse
     */
    public function list(string $query): ?JsonResponse
    {
        $response = User::with('country')
            ->where('nickname', 'ilike', "%$query%")
            ->orderByDesc('created_at')
            ->get();
        return response()->json($response);
    }

    /**
     * Get single user by uuid
     *
     * @param $uuid
     * @return JsonResponse
     */
    public function get($uuid): ?JsonResponse
    {
        $response = User::with('country', 'bonuses', 'seasonPass')->where('uuid', '=', $uuid)->first();
        return response()->json($response);
    }

    /**
     * Edit user info
     *
     * @param Request $request
     * @param $uuid
     * @return JsonResponse
     * @throws \JsonException
     */
    public function update(Request $request, $uuid): ?JsonResponse
    {
        $user = User::where('uuid', '=', $uuid)->first();

        $updates = [];

        foreach ($request->all() as $key => $val) {
            if ($key === 'roles') {

                foreach (Role::all() as $role_detach) {
                    $user->roles()->detach([$role_detach->id]);
                }

                $roles = json_decode($val, false, 512, JSON_THROW_ON_ERROR);
                foreach ($roles as $role) {
                    $user->roles()->attach([$role->id]);
                }
            } elseif ($key === 'avatar') {
                $updates[$key] = $this->updateAvatar($request, $user);
            } else {
                $updates[$key] = $val;
            }
        }

        $user->update($updates);

        return response()->json([
            'updates' => $updates,
            'user' => $user,
            'message' => 'Информация успешно обновлена'
        ]);
    }

    /**
     * Update nickname for user
     *
     * @param $request
     * @param $user
     * @return string
     */
    public function updateAvatar($request, $user): string
    {
        $image = $request->file('avatar');
        $name = $user->uuid . '_' . $image->getClientOriginalName();
        Image::make($image)->fit(120)->save(storage_path('app/public/uploads/user_avatars/') . $name);
        return $name;
    }

    /**
     * Get user bonuses by user id
     *
     * @param $id
     * @return JsonResponse
     */
    public function getUserBonuses($id)
    {
        return response()->json([
            'list' => User::find($id)->with('bonuses')
        ]);
    }

    /**
     * Delete user
     *
     * @param $id
     * @return JsonResponse
     */
    public function delete($id): ?JsonResponse
    {
        User::find($id)->delete();
        return response()->json(['message' => 'Пользователь успешно удален']);
    }

    /**
     * Set user role
     *
     * @param Request $request
     * @param $id
     * @return JsonResponse
     */
    public function linkRole(Request $request, $id): ?JsonResponse
    {
        $user = User::find($id);
        $user->attachRole($request->get('role_id'));
        return response()->json(['status' => 'Success']);
    }

    /**
     * Unset user role
     *
     * @param Request $request
     * @param $id
     * @return JsonResponse
     */
    public function unlinkRole(Request $request, $id): ?JsonResponse
    {
        $user = User::find($id);
        $user->detachRole($request->get('role_id'));
        return response()->json(['status' => 'Success']);
    }

    /**
     * Gift Season Pass to user
     *
     * @param $uuid
     * @return JsonResponse|null
     */
    public function giftSeasonPass($uuid): ?JsonResponse
    {
        $user = User::where('uuid', '=', $uuid)->first();

        $SPController = new SeasonPassController();

        $SPController->purchase($user->id, true, null);

        return response()->json([
            'message' => 'Сезонный пропуск успешно выдан!',
            'season_pass' => User::find($user->id)->with('seasonPass')
        ]);
    }

    /**
     * List of Box-Fights played
     *
     * @param $uuid
     * @return JsonResponse|null
     */
    public function getBoxFightsPlayed($uuid): ?JsonResponse
    {
        $user = User::where('uuid', '=', $uuid)->first();

        $matches = FortniteSoloMatch::with('first_player', 'second_player')
            ->whereIn('status', ['completed', 'canceled', 'archived', 'under_moderation', 'revoked'])
            ->where('first_player', '=', $user->id)
            ->orWhere('second_player', '=', $user->id)
            ->whereNotNull('second_player')
            ->orderByDesc('played_at')
            ->get();

        return response()->json([
            'matches' => $matches
        ]);
    }

    /**
     * Mute user for a time
     *
     * @param Request $request
     * @param $uuid
     * @return JsonResponse
     */
    public function mute(Request $request, $uuid): JsonResponse
    {
        $user = User::whereUuid($uuid)->first();

        if (!auth()->user()->hasRole(['admin', 'moderator'])) {
            return response()->json([
                'error' => __('messages.no_permissions'),
                'success' => false
            ], $this->accessDeniedStatus);
        }

        if (!$user) {
            return response()->json([
                'error' => __('messages.player_not_found'),
                'success' => false
            ], $this->notFoundStatus);
        }

        $user->limits()->create([
            'action' => 'mute',
            'comment' => $request->post('comment'),
            'finished_at' => $request->post('finished_at'),
        ]);

        Message::where('user_id', '=', $user->id)->update([
            'visible' => false
        ]);

        return response()->json([
            'message' => __('user.muted'),
            'success' => true,
        ]);
    }

    /**
     * Ban user for a time
     *
     * @param Request $request
     * @param $uuid
     * @return JsonResponse
     */
    public function ban(Request $request, $uuid): JsonResponse
    {
        $user = User::whereUuid($uuid)->first();

        if (!auth()->user()->hasRole(['admin', 'moderator'])) {
            return response()->json([
                'error' => __('messages.no_permissions'),
                'success' => false
            ], $this->accessDeniedStatus);
        }

        if (!$user) {
            return response()->json([
                'error' => __('messages.player_not_found'),
                'success' => false
            ], $this->notFoundStatus);
        }

        $user->limits()->create([
            'action' => 'ban',
            'sections' => $request->post('sections', null),
            'comment' => $request->post('comment'),
            'finished_at' => $request->post('finished_at'),
        ]);

        return response()->json([
            'message' => __('user.banned'),
            'success' => true,
        ]);
    }
}
