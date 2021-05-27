<?php


namespace App\Http\Controllers\API\SeasonPass;

use App\Http\Controllers\API\UserController;
use App\Http\Controllers\Controller;
use App\Models\SeasonPass\SeasonPass;
use App\Models\SeasonPass\SeasonPassInviteCode;
use App\Models\SeasonPass\SeasonPassPrize;
use App\Models\SeasonPass\SeasonPassPrizeToUser;
use App\Models\SeasonPass\SeasonPassToUser;
use App\Models\Users\User;
use App\Services\UserSeasonPassNotifications;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class SeasonPassController extends Controller
{

    /**
     *
     * @param null $seasonPassId
     * @return JsonResponse
     */
    public function getUserPrizes($seasonPassId = null) : JsonResponse
    {
        return response()->json([
            'prizes' => auth()->user()
                ->seasonPassPrizes($seasonPassId ?? $this->getCurrentSeasonPass()->id)
                ->get(),
            'last_unlocked' => auth()->user()
                ->seasonPassLastUnlockedPrize($seasonPassId ?? $this->getCurrentSeasonPass()->id)
                ->season_pass_prize_id ?? 0
        ]);
    }

    /**
     * Get current season pass object
     *
     * @return mixed
     */
    public function getCurrentSeasonPass()
    {
        return SeasonPass::where('date_start', '<=', Carbon::now())
            ->orderByDesc('date_end')
            ->first();
    }

    /**
     * Get current season pass object
     *
     * @return mixed
     */
    public function getSeasonPassPrice()
    {
        return response()->json([
            'price' => $this->getCurrentSeasonPass()->price
        ]);
    }

    /**
     * Get current season pass object
     *
     * @return mixed
     */
    public function getSeasonPassLevels()
    {
        $levels = SeasonPassPrize::where('season_pass_id', '=', $this->getCurrentSeasonPass()->id)
            ->orderBy('level')
            ->get();

        return response()->json([
            'levels' => $levels
        ]);
    }

    /**
     * Calculate new season pass level for user
     *
     * @param int $userId
     */
    public function calculateUserLevel(int $userId)
    {
        $user = User::find($userId);

        // Define current Season Pass
        $seasonPass = $this->getCurrentSeasonPass();

        $seasonPassToUser = SeasonPassToUser::where('user_id', '=', $user->id)
            ->where('season_pass_id', '=', $seasonPass->id)
            ->first();

        $seasonPassLevels = $seasonPass->prizes;
        $levels = [];
        $levels[0] = [
            'prize_id' => null,
            'level' => 0,
            'points' => 0
        ];

        foreach ($seasonPassLevels as $sp_level) {
            $levels[$sp_level->level ?? $sp_level['level']] = [
                'prize_id' => $sp_level->id,
                'level' => $sp_level->level ?? $sp_level['level'],
                'points' => $sp_level->points
            ];
        }

        $pointsToUpdate = [
            'level' => 0,
            'next_level_points' => 0,
            'current_level_points' => 0
        ];

        foreach ($levels as $level) {
            if($seasonPassToUser->total_points >= $level['points']) {
                // If not last level of Season Pass
                if($level['level'] < count($levels) - 1) {
                    // Update level and points
                    $pointsToUpdate = [
                        'level' => $level['level'],
                        'next_level_points' => $levels[$level['level'] + 1]['points'] - $seasonPassToUser->total_points,
                        'current_level_points' => $seasonPassToUser->total_points - $level['points']
                    ];
                    // If Season Pass level is going to update, then give a prize to level unlocked
                    if($level['level'] > 0) {
                        $this->givePrizeToUser($user->id, $seasonPass->id, $level['prize_id']);
                    }
                } else {
                    $pointsToUpdate = [
                        'level' => $level['level'],
                        'next_level_points' => 0,
                        'current_level_points' => $seasonPassToUser->total_points - $level['points']
                    ];
                }
            }
        }

        $seasonPassToUser->update([
            'level' => $pointsToUpdate['level'],
            'next_level_points' => $pointsToUpdate['next_level_points'],
            'current_level_points' => $pointsToUpdate['current_level_points']
        ]);
    }

    /**
     * Update Season Pass total points
     *
     * @param int $userId
     * @param int|null $points
     */
    public function updateUserPoints(int $userId, int $points = null): void
    {
        $user = User::find($userId);

        // Define current Season Pass
        $seasonPass = $this->getCurrentSeasonPass();

        $seasonPassToUser = SeasonPassToUser::where('user_id', '=', $user->id)
            ->where('season_pass_id', '=', $seasonPass->id)
            ->first();

        if(!$seasonPassToUser) {
            // Assign Season Pass stats for user
            $seasonPassToUser = $user->seasonPass()->save(new SeasonPassToUser([
                    'season_pass_id' => $seasonPass->id
                ])
            );

            // Assign Season Pass prizes for user
            foreach ($seasonPass->prizes as $prize) {
                $user->seasonPassPrizes($seasonPass->id)
                    ->save(new SeasonPassPrizeToUser([
                            'season_pass_id' => $seasonPass->id,
                            'season_pass_prize_id' => $prize->id
                        ])
                    );
            }
        }

        $seasonPassToUser->update([
            'total_points' => $seasonPassToUser->total_points + $points
        ]);

        $this->calculateUserLevel($user->id);
    }

    /**
     * Assign a prize based on new level unlocked, season pass id
     *
     * @param int $userId
     * @param int $seasonPassId
     * @param int $level
     */
    public function givePrizeToUser(int $userId, int $seasonPassId, int $level): void
    {
        // Find a prize related to level unlocked
        $prize = SeasonPassPrizeToUser::with('prize')
            ->where('user_id', $userId)
            ->where('season_pass_id', $seasonPassId)
            ->where('season_pass_prize_id', $level)
            ->first();

        // Parse prize action and assign it to user
        $this->parsePrizeInfo($prize->prize->action, $userId, $seasonPassId, $prize);
    }

    /**
     * Parse info from action
     *
     * @param $action
     * @param int $userId
     * @param int $seasonPassId
     * @param $prize
     */
    public function parsePrizeInfo($action, int $userId, int $seasonPassId, $prize): void
    {
        $user = User::find($userId);
        $seasonPassToUser = SeasonPassToUser::where('user_id', '=', $user->id)
            ->where('season_pass_id', '=', $seasonPassId)
            ->first();

        // Define can prize be received
        $accessibility = $seasonPassToUser->paid || $prize->prize->free;

        if(!$prize->unlocked_at) {
            // Mark a prize as unlocked with current timestamp
            $prize->update([
                'unlocked_at' => Carbon::now()
            ]);
        }

        // If prize is free or user has paid a season pass we do exit
        if(!$accessibility || $prize->received_at) {
            return;
        }

        // Otherwise update received date and assign a prize to user
        // 0 = action ; 1 = amount (time/days etc...)
        $data = explode(':', $action);

        switch ($data[0]) {
            // Missions boost with date end and multiplier (update if exists)
            case 'missions-boost':
                $dataExploded = explode(';', $data[1]);
                $data = [
                    'date_end' => Carbon::now()->addDays($dataExploded[0]),
                    'multiplier' => $dataExploded[1]
                ];
                if($user->bonuses) {
                    $user->bonuses()->update([
                        'data->missions_boost' => $data
                    ]);
                } else {
                    $user->bonuses()->create([
                        'data->missions_boost' => $data
                    ]);
                }
                break;
            // Add Beyond Points to user's account
            case 'bp':
                $user->update(['balance_points' => $user->balance_points + $data[1]]);
                break;
            // Create an invite code
            case 'invite-code':
                $this->generateInviteCode($userId, $data[1]);
                break;
            // Highlight a nickname
            case 'highlight_nickname':
                $userController = new UserController();
                $userController->actionFeature($userId, 'highlight_nickname', $data[1]);
                break;
            // Show icon in user's profile
            case 'icon':
                $seasonPassToUser->update([
                    'prizes->icon' => true
                ]);
                break;
            // For private missions
            case 'private-mission':
                if($user->bonuses) {
                    $user->bonuses()->update([
                        'data->private_mission' => isset($user->bonuses->data['private_mission']) ? $user->bonuses->data['private_mission'] + 1 : $data[1]
                    ]);
                } else {
                    $user->bonuses()->create([
                        'data->private_mission' => $data[1]
                    ]);
                }
                break;
            // For legendary missions
            case 'legendary-mission':
                if($user->bonuses) {
                    $user->bonuses->update([
                        'data->legendary_mission' => isset($user->bonuses->data['legendary_mission']) ? $user->bonuses->data['legendary_mission'] + 1 : $data[1]
                    ]);
                } else {
                    $user->bonuses()->create([
                        'data->legendary_mission' => $data[1]
                    ]);
                }
                break;
            // In other cases simply store a data into object like prize => amount
            default:
                $seasonPassToUser->update([
                    "prizes->{$data[0]}" => isset($seasonPassToUser->prizes[$data[0]]) ? $seasonPassToUser->prizes[$data[0]] + $data[1] : $data[1]
                ]);
                break;
        }

        $prize->update([
            'received_at' => Carbon::now()
        ]);

        // Create notification on received prize to user
        (new UserSeasonPassNotifications())->notifyPrizeReceived($user, $prize->prize);
    }

    /**
     * Generates code and if already existed then recursive
     * @param int $userId
     * @param int $amount
     * @return string
     */
    public function generateInviteCode(int $userId, int $amount): string
    {
        $code = Str::random(8);

        if(SeasonPassInviteCode::where('code', '=', $code)->exists()) {
            return $this->generateInviteCode($userId, $amount);
        }

        return SeasonPassInviteCode::create([
            'code' => $code,
            'user_id' => $userId,
            'amount' => $amount
        ]);
    }

    /**
     * Make a purchase
     * @param int|null $userId
     * @param bool $gift
     * @param string $payment_type
     * @return JsonResponse|bool
     */
    public function purchase(int $userId = null, bool $gift = false, $payment_type = 'bp') : JsonResponse
    {
        $user = User::find($userId ?? auth()->id());

        // Define current Season Pass
        $seasonPass = $this->getCurrentSeasonPass();

        $seasonPassToUser = SeasonPassToUser::where('user_id', '=', $user->id)
            ->where('season_pass_id', '=', $seasonPass->id)
            ->first();

        if($gift) {
            // Mark user's Season Pass as paid
            $seasonPassToUser->update(['paid' => true]);
            // Calculates everything
            $this->updateUserPoints($user->id);

            // Create notification to user
            (new UserSeasonPassNotifications())->notifyGift($user);

            return response()->json([
                'message' => __('season_pass.successfully_gifted')
            ]);
        }

        if($payment_type === 'bp') {
            if((int)$user->balance_points < (int)$seasonPass->price) {
                return response()->json([
                    'message' => __('season_pass.error_no_points')
                ], $this->errorStatus);
            }

            // Subtract Season Pass price from user's balance points
            $user->update([
                'balance_points' => $user->balance_points - $seasonPass->price
            ]);

            // Mark user's Season Pass as paid
            $seasonPassToUser->update(['paid' => true]);
            // Calculates everything
            $this->updateUserPoints($user->id);

            // Create notification to user
            (new UserSeasonPassNotifications())->notifyPurchased($user);

            return response()->json([
                'message' => __('season_pass.successfully_purchased')
            ]);
        }

        return true;
    }

    /**
     * Refresh user's Season Pass statistics in case of troubles
     * @param int $id
     * @return JsonResponse|bool
     */
    public function refresh(int $id) : JsonResponse
    {
        $user = User::find($id);

        // Calculates everything
        $this->updateUserPoints($user->id);

        return response()->json([
            'message' => 'Сезонный пропуск пересчитан успешно!'
        ]);

    }
}
