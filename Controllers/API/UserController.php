<?php
/*@todo Включить после проверки и поменять сравнение на strict ===
 * */

//declare(strict_types = 1);

namespace App\Http\Controllers\API;

use App\Helpers\MailSendHelper;
use App\Http\Controllers\API\SeasonPass\SeasonPassController;
use App\Http\Controllers\Controller;
use App\Models\Education\Coach;
use App\Models\Education\EducationPayment;
use App\Models\Education\Review;
use App\Models\SeasonPass\SeasonPassPrizeToUser;
use App\Models\SeasonPass\SeasonPassToUser;
use App\Models\Users\EmailVerification;
use App\Models\Users\PasswordResetCode;
use App\Models\Users\User;
use App\Models\Users\UserLoggingHistory;
use App\Models\Users\UserNotification;
use App\Models\Users\UserSocialAccount;
use App\Models\Users\Referral;
use App\Models\Users\Withdrawal;
use App\Services\UserFriendNotifications;
use App\Services\UserWithdrawalNotifications;
use Carbon\Carbon;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use Laravel\Socialite\Facades\Socialite;

class UserController extends Controller
{
    private $COLORS = [
        0 => '#e62959',
        1 => 'green',
        2 => 'blue',
        3 => 'purple',
        4 => 'orange',
        5 => 'yellow',
        6 => 'dark',
        7 => 'gray',
        8 => 'white',
        9 => '#f5f5f5',
    ];

    /**
     * Create a new guzzle client instance.
     *
     * @return void
     */
    /*public function __construct()
    {
        parent::__construct();
        $this->client = new Client([
            'headers' => ['Content-Type' => 'application/json'],
            'http_errors' => false,
        ]);
    }*/

    /**
     * Detach (remove) requested provider from user's relations.
     *
     * @param $provider
     * @return JsonResponse
     */
    public function detachProvider($provider): JsonResponse
    {
        // Lets find requested provider associated to authenticated user
        $social = UserSocialAccount::where([
            'user_id' => auth()->id(),
            'provider' => $provider
        ])->first();

        // If something happened and we cant find this relation, then return an error
        if (!$social) {
            return response()->json([
                'message' => __('messages.social_provider_something_wrong'),
                'success' => false
            ], 401);
        }

        if ($provider === 'steam') {
            auth()->user()->update([
                'steam_nickname' => null,
                'steam_id_64' => null,
                'steam_id_32' => null
            ]);
        }

        // Delete record
        $social->delete();

        // Return success message
        return response()->json([
            'message' => __('messages.social_provider_removed'),
            'integrations' => auth()->user()->integrations,
            'success' => true
        ]);
    }

    /**
     * Generate redirect URI for requested provider.
     *
     * @param $provider
     * @return JsonResponse
     */
    public function redirectToProvider($provider): JsonResponse
    {
        // Create empty array with custom scopes per requested provider
        $scopes = [];

        // Get requested provider name and set custom scopes
        switch ($provider) {
            case 'discord':
                $scopes = ['identify'];
                break;
        }

        // Return generated URL to requested provider
        return response()->json([
            'url' => Socialite::driver($provider)->stateless()->setScopes($scopes)->redirect()->getTargetUrl()
        ]);
    }

    /**
     * Obtain the user information from provider.
     *
     * @param $provider
     * @param Request $request
     * @return JsonResponse
     */
    public function handleProviderCallback($provider, Request $request): JsonResponse
    {
        // Store provider's callback with user info
        $socialiteUser = Socialite::driver($provider)->stateless()->user();

        // Find user by UUID
        $user = User::whereUuid($request->get('uuid'))->first();

        $info = [];

        // Get requested provider name and set its title and URL
        switch ($provider) {
            case 'discord':
                $info = ['title' => 'Discord', 'url' => null];
                break;
            case 'twitch':
                $info = ['title' => 'Twitch', 'url' => 'https://twitch.tv/' . $socialiteUser->nickname];
                break;
            case 'vkontakte':
                $info = ['title' => 'VKontakte', 'url' => 'https://vk.com/' . $socialiteUser->nickname];
                break;
            case 'facebook':
                $info = ['title' => 'Facebook', 'url' => 'https://facebook.com/' . $socialiteUser->id];
                break;
            case 'battlenet':
                $info = ['title' => 'Battlenet', 'url' => null];
                break;
            case 'steam':
                $info = ['title' => 'Steam', 'url' => $socialiteUser->user['profileurl']];

                $user->update([
                    'steam_nickname' => $socialiteUser->user['personaname'],
                    'steam_id_64' => $socialiteUser->user['steamid'],
                    'steam_id_32' => ($socialiteUser->user['steamid'] - 76561197960265728),
                    'balance_points' => $user->balance_points + 50, // Add 50 BP for integrating Steam
                ]);

                break;
        }

        // Create row for authorized provider and assign to user
        $user->integrations()->updateOrCreate(
            [
                'provider' => $provider,
            ],
            [
                'token' => $socialiteUser->token ?? '1234567890',
                'provider_id' => $socialiteUser->id,
                'provider' => $provider,
                'nickname' => $socialiteUser->nickname ?? $socialiteUser->name,
                'title' => $info['title'],
                'url' => $info['url'],
                'enabled' => true
            ]
        );

        try {
            MailSendHelper::send('socialite_connect', $user->email);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        }

        // Return response to close opened window on front-end
        return response()->json([
            'message' => __('messages.social_provider_connected', ['title' => $info['title']]),
            'integrations' => $user->integrations,
            'provider' => $provider,
            'nickname' => $socialiteUser->nickname ?? $socialiteUser->name,
            'success' => true
        ]);
    }

    /**
     * login user api
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function login(Request $request): JsonResponse
    {


        // Setting up laravel locale to user's browser language
        App::setLocale($request->get('language'));

        // Logging type nickname || email
        $loggingType = preg_match('/@.+\./', $request->get('name')) ? 'email' : 'nickname';
        $userByNickname = null;

        // Check if user typed an email or a nickname in login form.
        // If string matches an email address then validate email and password
        if ($loggingType === 'email') {
            $validator = Validator::make($request->all(), [
                'name' => 'required|email|exists:users,email',
                'password' => 'required',
            ]);
        } else {
            // Validate name and password and find user object by requested nickname
            $validator = Validator::make($request->all(), [
                'name' => 'required|exists:users,nickname',
                'password' => 'required',
            ]);
        }

        // Return errors while fields or data are wrong
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], $this->errorStatus);
        }

        // If logging type is nickname then we must find a user model by requested nickname
        // and fill credentials with this model's email and password provided
        if ($loggingType === 'nickname') {
            $userByNickname = User::where('nickname', $request->get('name'))->first();
            $credentials = ['email' => $userByNickname->email, 'password' => $request->get('password')];
        } else {
            $credentials = ['email' => $request->get('name'), 'password' => $request->get('password')];
        }

        // Logout from other devices
        //Auth::logoutOtherDevices($request->get('password'));

        if (auth()->attempt($credentials, true)) {

            if ($request->get('otp_code') !== null) {
                $timestamp = $this->twoFactorCheckCode($request->get('otp_code'));

                if (!$timestamp) {
                    return response()->json([
                        'errors' => [
                            'otp_code' => [
                                0 => __('messages.otp_invalid_code'),
                            ]
                        ]
                    ], $this->accessDeniedStatus);
                }
            }

            // Update user's chat color if no exists
            auth()->user()->update(['chat_color' => $this->COLORS[substr(auth()->id(), -1)]]);

            // Record login history for logged user
            UserLoggingHistory::create([
                'user_id' => auth()->id(),
                'ip' => $request->get('ip'),
                'useragent' => $request->userAgent(),
                'country_iso' => $request->get('country_iso'),
                'country_name' => $request->get('country_name'),
            ]);

//            try {
//                MailSendHelper::send('login', auth()->user()->email);
//            } catch (\Exception $e) {
//                Log::error($e->getMessage());
//            }

            // Store logging info for current user
            return response()->json([
                'user' => User::with('country', 'discord', 'withdrawals', 'seasonPass', 'bonuses')->find(auth()->id()),
                'token' => auth()->user()->createToken('M5PL')->accessToken
            ]);
        }

        return response()->json([
            'errors' => [
                'password' => [
                    0 => __('validation.password')
                ]
            ]
        ], $this->accessDeniedStatus);
    }

    /**
     * Register user api
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function register(Request $request): JsonResponse
    {
        // Setting up laravel locale to user's browser language
        App::setLocale($request->get('language'));

        $validator = Validator::make($request->all(), [
            'nickname' => 'required|regex:/[a-z0-9\._-]/i|unique:users',
            'email' => 'required|email|unique:users',
            'password' => 'required',
            'confirm_password' => 'required|same:password',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], $this->errorStatus);
        }

        // Find country by iso_3166_2 as country_code
        if ($request->get('country_iso')) {
            $country = DB::table('countries')->where('iso_3166_2', $request->get('country_iso'))->first();
        }

        // Create user
        User::create([
            'email' => $request->get('email'),
            'password' => bcrypt($request->get('password')),
            'nickname' => $request->get('nickname'),
            'language' => $request->get('language'),
            'timezone' => $request->get('timezone'),
            'country_id' => isset($country) ? $country->id : null,
            'balance_points' => 50, // Add 50 BP for registering
            'referral_code' => Str::slug($request->get('nickname')),
            'account_level' => 1,
            'current_level_points' => 0,
            'next_level_points' => 200,
        ]);

        $credentials = $request->only('email', 'password');

        // Then authenticate him
        if (auth()->attempt($credentials, true)) {
            $user = auth()->user();

            // If referral isset
            if ($request->get('referral')) {
                $referral = User::where('referral_code', '=', $request->get('referral'))->first();
                if ($referral) {
                    Referral::create([
                        'user_id' => $user->id,
                        'referral_id' => $referral->id
                    ]);
                }
            }

            // Assign "player" role to user
            $user->roles()->attach([3]);

            $token = $user->createToken('M5PL')->accessToken;

            // Assign Season Pass and levels to user
            $SPController = new SeasonPassController();
            $seasonPass = $SPController->getCurrentSeasonPass();

            // Assign Season Pass stats for user
            $user->seasonPass()->save(new SeasonPassToUser([
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

            // Update user's chat color if no exists
            $user->update(['chat_color' => $this->COLORS[substr($user->id, -1)]]);

            // Record login history for authed user
            UserLoggingHistory::create([
                'user_id' => auth()->id(),
                'ip' => $request->get('ip') ?? null,
                'useragent' => $request->userAgent(),
                'country_iso' => $request->get('country_iso'),
                'country_name' => $request->get('country_name'),
            ]);

            // Generate email verification code
            $this->generateEmailVerificationCode($user->id);

            try {
                MailSendHelper::send('register', $request->get('email'));
            } catch (\Exception $e) {
                Log::error($e->getMessage());
            }

            return response()->json([
                'referral' => $request->get('referral'),
                'user' => User::with('country', 'discord', 'withdrawals', 'seasonPass', 'bonuses')->where('id', '=', auth()->id())->first(),
                'token' => $token
            ]);
        }

        return response()->json(['error' => __('messages.general_error')], $this->accessDeniedStatus);
    }

    /**
     * Authenticated user object api
     *
     * @return JsonResponse
     */
    public function current(): JsonResponse
    {
        $user = User::with('country', 'discord', 'withdrawals', 'seasonPass', 'bonuses', 'limits')->where('id', '=', auth()->id())->first();

        // Setting up laravel locale to user's browser language
        App::setLocale($user->language);

        return response()->json([
            'token' => $user->createToken('M5PL')->accessToken,
            'user' => $user
        ]);
    }

    /**
     * Logout user api
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->token()->revoke();

        return response()->json(['success' => true]);
    }

    /**
     * Generate new Google two-factor qr code image api
     *
     * @return JsonResponse
     */
    public function twoFactorGenerateNewCodeImage(): JsonResponse
    {
        // Initialize google2fa class
        $google2fa = app('pragmarx.google2fa');

        // If user 'google2fa_secret' is already set
        if (auth()->user()->google2fa_secret) {
            // Generate QR code url string
            $qrCodeUrl = $google2fa->getQRCodeInline(
                "Beyond/" . auth()->user()->nickname,
                auth()->user()->email,
                auth()->user()->google2fa_secret
            );

            // Return generated QR code image
            return response()->json([
                'image' => $qrCodeUrl,
                'success' => true
            ]);
        }

        // Generate google2fa secret key
        $prefix = str_pad(auth()->id(), 10, 'X');
        $secret = $google2fa->generateSecretKey(16, $prefix);

        // Update 'google2fa_secret' column
        auth()->user()->update(['google2fa_secret' => $secret]);

        // Generate QR code url string
        $qrCodeUrl = $google2fa->getQRCodeInline(
            "Beyond/" . auth()->user()->nickname,
            auth()->user()->email,
            $secret
        );

        // If something bad happened throw an error
        if (!$qrCodeUrl) {
            return response()->json([
                'message' => __('messages.otp_generation_error'),
                'success' => false
            ], $this->errorStatus);
        }

        // Otherwise return generated image
        return response()->json([
            'image' => $qrCodeUrl,
            'success' => true
        ]);
    }

    /**
     * Check Google two-factor 6-digit code api
     *
     * @param $code
     * @param null $user
     * @return Boolean
     */
    public function twoFactorCheckCode($code, $user = null): bool
    {
        // Initialize google2fa class
        $google2fa = app('pragmarx.google2fa');

        // Verify code
        $user = auth()->user() ?? $user;

        return $google2fa->verifyKeyNewer(auth()->user() ? auth()->user()->google2fa_secret : $user->google2fa_secret, $code, auth()->user() ? auth()->user()->google2fa_ts : $user->google2fa_ts);
    }

    /**
     * Epic Games retrieve user information api
     *
     * @param Request $request
     * @return JsonResponse
     * @throws GuzzleException
     * @throws \JsonException
     */
    public function epicGamesConnect(Request $request): JsonResponse
    {
        // Setting up API call with requested code
        $endpoint = config('external-api.url') . '/code/' . $request->code;
        $client = new \GuzzleHttp\Client();

        $response = $client->request('GET', $endpoint);

        // Decode requested info into json object
        $content = json_decode($response->getBody(), false, 512, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        if (!$content) {
            // Return error
            return response()->json([
                'message' => __('messages.epic_account_connect_error'),
                'success' => false
            ], $this->notFoundStatus);
        }

        if (!User::where('epic_id', '=', $content->fid)->count()) {
            // Update user's epic information
            auth()->user()->update([
                'epic_id' => $content->fid,
                'epic_nickname' => $content->nick,
                'balance_points' => auth()->user()->balance_points + 50, // Add 50 BP for integrating Epic Games
            ]);

            // Make delete code API call
            $client->request('DELETE', $endpoint);

            if (auth()->user()->email) {
                try {
                    MailSendHelper::send('epic_connect', auth()->user()->email);
                } catch (\Exception $e) {
                    Log::error($e->getMessage());
                }
            }

            // Return epic id/nickname
            return response()->json([
                'user' => User::with('country')->where('id', auth()->id())->first(),
                'message' => __('messages.epic_account_connect'),
                'success' => true
            ]);
        }
        // Return epic id/nickname
        return response()->json([
            'message' => __('messages.epic_account_exists')
        ], $this->existStatus);
    }

    /**
     * Detach Epic Games from user api
     *
     * @return JsonResponse
     */
    public function epicGamesDetach(): JsonResponse
    {
        // Update user's epic information
        auth()->user()->update([
            'epic_id' => null,
            'epic_nickname' => null,
        ]);

        // Return epic id/nickname
        return response()->json([
            'user' => User::with('country')->where('id', auth()->id())->first(),
            'message' => __('messages.epic_account_detach'),
            'success' => true
        ]);
    }

    /**
     * Enable two-factor security api
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function twoFactorEnable(Request $request): JsonResponse
    {
        // Validate requested code
        $timestamp = $this->twoFactorCheckCode($request->get('code'));

        if (!$timestamp) {
            return response()->json([
                'message' => __('messages.otp_invalid_code'),
                'success' => false
            ], $this->accessDeniedStatus);
        }

        // Set user's otp to true and update entering code timestamp
        auth()->user()->update([
            'otp' => true,
            'google2fa_ts' => $timestamp
        ]);

        try {
            MailSendHelper::send('otp_enabled', auth()->user()->email);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        }

        return response()->json([
            'user' => auth()->user(),
            'message' => __('messages.otp_enabled'),
            'success' => true
        ]);
    }

    /**
     * Disable two-factor security api
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function twoFactorDisable(Request $request): JsonResponse
    {
        $timestamp = $this->twoFactorCheckCode($request->get('code'));

        if (!$timestamp) {
            return response()->json([
                'message' => __('messages.otp_invalid_code'),
                'success' => false
            ], $this->accessDeniedStatus);
        }

        // Set user's otp to false and update entering code timestamp
        auth()->user()->update([
            'otp' => false,
            'google2fa_ts' => $timestamp
        ]);

        return response()->json([
            'user' => auth()->user(),
            'message' => __('messages.otp_disabled'),
            'success' => true
        ]);
    }

    /**
     * User email verification api
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function verificationCheck(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), ['code' => 'required']);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], $this->errorStatus);
        }

        // Find requested code
        $checkCode = EmailVerification::where('code', $request->get('code'))->first();

        // If requested code found and referrer email is equals to user's email
        if ($checkCode && $checkCode->email === auth()->user()->email) {
            if ($checkCode->expires_at < Carbon::now()) {
                return response()->json([
                    'message' => __('messages.email_verification_code_expired'),
                    'success' => false
                ], $this->accessDeniedStatus);
            }

            // Set email verified with current timestamp
            $user = User::find(auth()->id());
            $user->update(['email_verified_at' => Carbon::now()]);

            return response()->json([
                'user' => $user,
                'message' => __('messages.email_verified_successfully'),
                'success' => true
            ]);
        }

        return response()->json([
            'message' => __('messages.email_verification_code_invalid'),
            'success' => false
        ], $this->accessDeniedStatus);
    }

    /**
     * User generate verification code api
     *
     * @return JsonResponse
     */
    public function verificationGenerate(): JsonResponse
    {
        $this->generateEmailVerificationCode(auth()->id());

        return response()->json([
            'message' => __('messages.email_verification_code_created'),
            'success' => true
        ]);
    }

    /**
     * Update user property api
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        foreach ($request->user()->toArray() as $k => $v) {
            if ($k === $request->get('name')) {
                // If provided data by user same is in database then return error
                if ($request->user()->$k == $request->get('value')) {

                    // As we updating field in DB by $request's name property
                    // we need to return related info for current user below (eg. country data)
                    // and inject them to every response returned back to front
                    $user = User::find($request->user()->id);
                    $user->country;

                    return response()->json([
                        'message' => __('messages.same_info'),
                        'success' => false,
                        'user' => $user
                    ], $this->accessDeniedStatus);
                }
                // Then if data is unique and passes validation then update column
                if ($request->get('name') === 'country_id') {

                    $country = DB::table('countries')->where('name', 'like', [trim($request->get('value'))])->first();
                    $request->user()->update(['country_id' => $country->id]);

                } else {
                    if ($request->get('name') === 'nickname') {
                        $request->user()->update(['can_change_nickname' => false]);
                    }
                    $request->user()->update([$request->get('name') => trim($request->get('value'))]);
                }

                $user = User::find($request->user()->id);
                $user->country;

                // If user changing an email than we generate new verification code and also ask him to verify it
                if ($request->get('name') === 'email') {
                    $user->update(['email_verified_at' => null]);
                    $this->generateEmailVerificationCode($user->id);
                }

                // If user changing a nickname then log previous nickname to user's nickname history
                if ($request->get('name') === 'nickname') {
                    // Record nickname changing history for authed user
                    auth()->user()->nicknames()->create([
                        'last_nickname' => auth()->user()->nickname
                    ]);
                }

//                if ($request->get('name') !== 'balance_points') {
//                    try {
//                        MailSendHelper::send('profile_update', auth()->user()->email);
//                    } catch (\Exception $e) {
//                        Log::error($e->getMessage());
//                    }
//                }

                return response()->json([
                    'message' => __('messages.information_changed_successfully'),
                    'success' => true,
                    'user' => $user
                ]);
            }
        }

        $user = User::find($request->user()->id)->with('country');

        return response()->json([
            'message' => __('messages.validation_failed'),
            'success' => false,
            'user' => $user
        ], $this->accessDeniedStatus);
    }

    /**
     * Update user avatar property api
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateAvatar(Request $request): JsonResponse
    {
        // Validator checks for avatar field that its a base64 image
        $validator = Validator::make($request->all(), [
            'avatar' => 'required|regex:/^data:image\/(\w+);base64,/',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors(),
                'success' => false
            ], $this->accessDeniedStatus);
        }

        // Check if there is an image in request
        if ($request->get('avatar')) {
            $image = $request->get('avatar');
            // Defining name with uuid _ time and mime
            $name = $request->user()->uuid . '_' . time() . '.' . explode('/', explode(':', substr($image, 0, strpos($image, ';')))[1])[1];
            $store = Image::make($request->get('avatar'))->fit(120)->save(storage_path('app/public/uploads/user_avatars/') . $name);
            // If image was stored successfully then update the avatar field
            if ($store) {
                $request->user()->update(['avatar' => $name]);

                return response()->json([
                    'avatar' => $request->user()->avatar,
                    'message' => __('messages.avatar_changed_successfully'),
                    'success' => true
                ]);
            }

        }
        // Return an error
        return response()->json([
            'avatar' => $request->user()->avatar,
            'message' => __('messages.upload_error'),
            'success' => false
        ], $this->accessDeniedStatus);
    }

    /**
     * Change user password api
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'old_password' => 'required',
            'new_password' => 'required',
            'new_password_confirm' => 'required|same:new_password'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], $this->errorStatus);
        }

        // If user has turned Google two-factor security then he must provide 6-digit code
        // Otherwise in case its disabled then OTP check wont go
        if ($request->get('otp_code') !== null) {
            $otp_check = $this->twoFactorCheckCode($request->get('otp_code'));
            // If entered code in invalid then return an error
            if (!$otp_check) {
                return response()->json([
                    'errors' => [
                        'otp_code' => __('messages.otp_invalid_code')
                    ],
                    'success' => false,
                ], $this->accessDeniedStatus);
            }
        }

        $user = User::find(auth()->id());

        // Check old password to match actual password
        if (Hash::check($request->get('old_password'), $user->password)) {
            // If true then update it
            $user->update(['password' => bcrypt($request->get('new_password'))]);

            MailSendHelper::send('password_change', auth()->user()->email);

            return response()->json([
                'message' => __('messages.password_changed_successfully'),
                'success' => true,
            ]);

        }

        // Else return an error says that requested old password is wrong
        return response()->json([
            'errors' => [
                'old_password' => __('validation.password')
            ],
            'success' => false,
        ], $this->accessDeniedStatus);
    }

    /**
     * Get friends list for user
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getFriends(Request $request): JsonResponse
    {
        $user = User::whereUuid($request->uuid)->first();

        $followers = $user->followers()->get()->toArray();
        $followings = $user->following()->get()->toArray();

        $friends = [];

        foreach ($followers as $follower) {
            $friends[] = $follower;
        }
        foreach ($followings as $following) {
            $friends[] = $following;
        }

        return response()->json([
            'success' => true,
            'friends' => $friends,
        ]);
    }

    /**
     * Send a friend request api
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendFriendRequest(Request $request): JsonResponse
    {
        $recipient = User::whereUuid($request->uuid)->first();

        // Return if recipient already has a request with pending request
        if ($request->user()->hasFollowRequested($recipient)) {
            return response()->json([
                'message' => __('messages.friend_request_pending')
            ], $this->accessDeniedStatus);
        }

        $exist = DB::table('followers')->whereIn('followable_id', [$recipient->id, $request->user()->id])
            ->whereIn('follower_id', [$recipient->id, $request->user()->id])
            ->first();

        if ($exist) {
            $this->acceptFriendRequest($request);
        }

        try {
            $request->user()->followRequest($recipient);

            (new UserFriendNotifications())->notifyFriendRequest($request->user(), $recipient);

            return response()->json([
                'message' => __('messages.friend_request_sent')
            ]);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
            return response()->json([
                'message' => __('messages.general_error')
            ], $this->accessDeniedStatus);
        }
    }

    public function isFriend(Request $request): JsonResponse
    {
        $friend = User::whereUuid($request->uuid)->first();

        return response()->json([
            'status' => true,
            'friend' => $request->user()->follows($friend) || $friend->follows($request->user()),
        ]);
    }

    /**
     * Accept a friend request api
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function acceptFriendRequest(Request $request): JsonResponse
    {
        try {
            $sender = User::whereUuid($request->get('uuid'))->first();

            $request->user()->acceptFollowRequest($sender);
            $sender->follow($request->user());

            (new UserFriendNotifications())->notifyAddFriend($request->user(), $sender);

            return response()->json([
                'message' => __('messages.friend_request_sent')
            ]);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
            return response()->json([
                'message' => __('messages.general_error')
            ], $this->accessDeniedStatus);
        }
    }

    /**
     * Deny a friend request api
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function denyFriendRequest(Request $request): JsonResponse
    {
        try {
            $sender = User::whereUuid($request->get('uuid'))->first();

            $request->user()->declineFollowRequest($sender);

            return response()->json([
                'message' => __('messages.friend_request_denied')
            ]);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
            return response()->json([
                'message' => __('messages.general_error')
            ], $this->accessDeniedStatus);
        }
    }

    /**
     * Unfriend api
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function unFriend(Request $request): JsonResponse
    {
        try {
            $friend = User::whereUuid($request->get('uuid'))->first();

            DB::table('followers')->whereIn('followable_id', [$friend->id, $request->user()->id])
                ->whereIn('follower_id', [$friend->id, $request->user()->id])
                ->delete();

            return response()->json([
                'message' => __('messages.friend_unfriend')
            ]);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
            return response()->json([
                'message' => __('messages.general_error')
            ], $this->accessDeniedStatus);
        }
    }

    /**
     * Get user's friend requests
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function friendRequests(Request $request): JsonResponse
    {
        $friendRequests = $request->user()->followerRequests()->get();

        return response()->json([
            'status' => true,
            'friendRequests' => $friendRequests,
        ]);
    }

    /**
     * Get user's friend requests
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function friendsListByIds(Request $request): JsonResponse
    {
        $list = DB::table('followers')
            ->where('followable_id', auth()->id)
            ->orWhere('follower_id', auth()->id)
            ->select('followable_id as user_id')
            ->get();

        return response()->json([
            'list' => $list,
        ]);
    }

    /**
     * Generate email verification code method
     *
     * @param $user_id
     * @return void
     */
    public function generateEmailVerificationCode($user_id): void
    {
        $user = User::find($user_id);
        // Generate new verification code
        $create = EmailVerification::create([
            'user_id' => $user->id,
            'code' => bcrypt(time() . '-' . $user->email), // Generate code with current timestamp with '-' and requested email address
            'email' => $user->email,
            'expires_at' => Carbon::now()->addDays(7), // Setup code expiring for 7 days from current dateTime
        ]);

        try {
            MailSendHelper::send('verification', $user->email, $create->code);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        }
    }

    /**
     * Get all unread notification for user
     *
     * @return JsonResponse
     */
    public function getNotifications(): JsonResponse
    {
        $notifications = UserNotification::where('user_id', '=', auth()->id())
            ->where('read', false)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['notifications' => $notifications->groupBy('category'), 'total' => $notifications->count()]);
    }

    /**
     * Delete notification by id
     *
     * @param $id
     * @return JsonResponse
     */
    public function deleteNotification($id): JsonResponse
    {
        $notification = UserNotification::find($id);

        if ($notification) {
            $notification->delete();
        }

        return response()->json(['success' => true]);
    }

    /**
     * Delete all notifications
     *
     * @param $id
     * @return JsonResponse
     */
    public function deleteNotificationsAll(): JsonResponse
    {
        UserNotification::where('user_id', '=', auth()->id())->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Get user's referrals by user id
     *
     * @param $id
     * @return JsonResponse
     */
    public function getUserReferrals($id): JsonResponse
    {
        $response = Referral::where('referral_id', '=', $id)->get();

        return response()->json($response);
    }

    /**
     * Reset password and send an email
     *
     * @param $name // email or nickname
     * @return JsonResponse
     */
    public function resetPasswordRequest($name): JsonResponse
    {
        $user = User::where('email', '=', $name)
            ->orWhere('nickname', '=', $name)
            ->first();

        if (!$user) {
            return response()->json([
                'message' => __('auth.not_found'),
                'success' => false
            ], $this->notFoundStatus);
        }

        try {
            // Generate reset code
            $code = Str::random(16);
            PasswordResetCode::create([
                'code' => $code,
                'email' => $user->email
            ]);

            //Send an email
            MailSendHelper::send('password_reset', $user->email, [
                'locale' => App::getLocale(),
                'code' => $code
            ]);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        }

        return response()->json([
            'message' => __('auth.password_reset'),
            'success' => true
        ]);
    }

    /**
     * Check code and change password
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function changePasswordRequest(Request $request): JsonResponse
    {
        $user = User::where('email', '=', $request->get('email'))->first();

        if ($request->get('otp_code') !== null) {
            $otp_check = $this->twoFactorCheckCode($request->get('otp_code'), $user);
            if (!$otp_check) {
                return response()->json([
                    'message' => __('messages.otp_invalid_code')
                ], $this->accessDeniedStatus);
            }
        }

        if ($user->otp && $request->get('otp_code') === null) {
            return response()->json(['success' => 'otp']);
        }

        $user->update(['password' => bcrypt($request->get('new_password'))]);

        MailSendHelper::send('password_change', $user->email);

        PasswordResetCode::where('code', '=', $request->get('code'))->delete();

        return response()->json([
            'message' => __('messages.password_changed_successfully'),
            'success' => true,
        ]);
    }

    /**
     * Validate password reset code
     *
     * @param $code
     * @return JsonResponse
     */
    public function checkPasswordResetCode($code): JsonResponse
    {
        $code = PasswordResetCode::where('code', '=', $code)->first();

        if (!$code) {
            return response()->json([
                'message' => 'Code expired'
            ], $this->accessDeniedStatus);
        }

        if (Carbon::now() > Carbon::parse($code->created_at)->addDay()) {
            return response()->json([
                'message' => 'Code expired'
            ], $this->accessDeniedStatus);
        }

        return response()->json(['email' => $code->email]);
    }

    /**
     * Get user's actual balance points
     *
     * @return JsonResponse
     */
    public function getUserBalancePoints(): JsonResponse
    {
        return response()->json(['value' => auth()->user()->balance_points]);
    }

    /**
     * Rebuild user's account level
     *
     * @param null $userId
     */
    public function rebuildAccountLevel($userId = null): void
    {
        $user = User::find($userId ?? auth()->id());
        $data = self::getLevel($user->account_points);
        $user->update([
            'account_level' => $data->level,
            'current_level_points' => $user->account_points - $data->points_start,
            'next_level_points' => $data->points_end - $user->account_points + 1,
        ]);
    }

    private static function getLevel($points)
    {
        if ($points === 0) {
            return 1;
        }
        $response = DB::table('level_to_users')
            ->where('points_start', '<=', $points)
            ->where('points_end', '>=', $points)
            ->first();

        if (!isset($response)) {
            return 1;
        }
        return $response;
    }

    /**
     * Shop actions feature
     *
     * @param int $user_id
     * @param string $action
     * @param int $amount
     * @return bool
     */
    public function actionFeature(int $user_id, string $action, int $amount): bool
    {
        $user = User::find($user_id);

        // Highlight nickname for $amount days
        if ($action === 'highlight_nickname') {
            $hasValidDate = $user->highlight_nickname ? Carbon::parse($user->highlight_nickname) >= Carbon::now() : false;
            $user->update([
                'highlight_nickname' => $hasValidDate ? Carbon::parse($user->highlight_nickname)->addDays($amount) : Carbon::now()->addDays($amount)
            ]);
        }

        // Highlight avatar for $amount days
        if ($action === 'highlight_avatar') {
            $hasValidDate = $user->highlight_avatar ? Carbon::parse($user->highlight_avatar) >= Carbon::now() : false;
            $user->update([
                'highlight_avatar' => $hasValidDate ? Carbon::parse($user->highlight_avatar)->addDays($amount) : Carbon::now()->addDays($amount)
            ]);
        }
        // Highlight avatar for $amount days
        if ($action === 'highlight_nickname_avatar') {
            $hasValidDateAvatar = $user->highlight_avatar ? Carbon::parse($user->highlight_avatar) >= Carbon::now() : false;
            $hasValidDateNickname = $user->highlight_nickname ? Carbon::parse($user->highlight_nickname) >= Carbon::now() : false;
            $user->update([
                'highlight_avatar' => $hasValidDateAvatar ? Carbon::parse($user->highlight_avatar)->addDays($amount) : Carbon::now()->addDays($amount),
                'highlight_nickname' => $hasValidDateNickname ? Carbon::parse($user->highlight_nickname)->addDays($amount) : Carbon::now()->addDays($amount)
            ]);
        }

        // Let user to change a nickname
        if ($action === 'nickname_change') {
            $user->update([
                'can_change_nickname' => true
            ]);
        }

        return true;
    }

    /**
     * Store a withdrawal request from user
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function storeWithdrawalRequest(Request $request): JsonResponse
    {
        $user = User::find(auth()->id());

        try {
            if ($user->balance_points >= $request->sum) {
                $store = new Withdrawal($request->all());

                $user->withdrawals()->save($store);

                $user->update(['balance_points' => $user->balance_points - $request->get('sum')]);

                (new UserWithdrawalNotifications())->notifyWithdrawalRequest($request->user(), $request->get('sum'));

                return response()->json([
                    'balance_points' => $user->balance_points,
                    'message' => __('messages.withdrawal_request_sent')
                ]);
            }

            return response()->json([
                'message' => __('messages.withdrawal_request_not_enough_balance')
            ], $this->errorStatus);

        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
            return response()->json([
                'message' => __('messages.withdrawal_request_failed')
            ], $this->errorStatus);
        }
    }

    /**
     * Get user's education history
     *
     * @return JsonResponse
     */
    public function getEducationPayments(): JsonResponse
    {
        return response()->json([
            'programs' => EducationPayment::with('coach', 'program', 'review')
                ->where('user_id', '=', auth()->id())
                ->where('paid', '=', true)
                ->get()
        ]);
    }

    /**
     * Store user's review for education
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendEducationReview(Request $request): JsonResponse
    {
        try {
            $coach = Coach::where('id', '=', $request->post('coach_id'))->first();

            $data = new Review([
                'user_id' => auth()->id(),
                'body' => $request->post('body'),
                'rating' => (int)$request->post('rating'),
                'program_id' => $request->post('program_id'),
                'pay_id' => $request->post('pay_id'),
            ]);

            $review = $coach->reviews()->save($data);

            return response()->json([
                'message' => 'Ваш отзыв успешно сохранен!',
                'review' => $review
            ]);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());

            return response()->json([
                'message' => 'Что-то пошло не так, пожалуйста, повторите еще раз!'
            ], $this->errorStatus);
        }
    }

    /**
     * Store user's discord for payment
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendEducationDiscord(Request $request): JsonResponse
    {
        try {
            EducationPayment::find($request->post('payment_id'))->update([
                'discord' => $request->post('discord')
            ]);

            return response()->json([
                'message' => 'Ваш отзыв успешно сохранен!'
            ]);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());

            return response()->json([
                'message' => 'Что-то пошло не так, пожалуйста, повторите еще раз!'
            ], $this->errorStatus);
        }
    }
}
