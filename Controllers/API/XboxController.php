<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class XboxController extends Controller
{


    protected $client;

    protected $domain = 'https://xbl.io/api/v2/';

    public function __construct()
    {
        $this->client = new Client([
            'cookies' => new CookieJar(false),
            'headers' => [
                'X-Authorization' => env('XBOX_API_KEY'),
               // 'X-Contract' => 100, // if use app
            ],
            'http_errors' => true,
        ]);
    }

    /**
     * Xbox auth api
     *
     * @param null $xuid
     * @return JsonResponse
     */
    public function account($xuid = null): JsonResponse
    {
        try {
            if ($xuid !== null) {
                $response = $this->client->get($this->domain . '/account/' . $xuid);
            } else {
                $response = $this->client->get($this->domain . '/account');
            }
            $data = json_decode($response->getBody()->getContents(), false, 512, JSON_THROW_ON_ERROR);
            $profile = collect($data->profileUsers)->first();
            $xbl_nickname = collect($profile->settings)->firstWhere('id', '=', 'Gamertag')->value;
            if (auth()->check()) {
                DB::table('users')
                    ->where('id', '=', auth()->id())
                    ->update([
                        'xbl_id' => $profile->id,
                        'xbl_nickname' => $xbl_nickname,
                    ]);
                return response()->json([
                    ['message' => 'success']
                ]);
            }
            return response()->json([
                ['message' => 'error']
            ], $this->errorStatus);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
            return response()->json([
                ['message' => 'error']
            ], $this->errorStatus);
        }
    }

    public function friends($xuid = null): JsonResponse
    {
        try {
            if ($xuid !== null) {
                $response = $this->client->get($this->domain . '/friends/?xuid=' . $xuid);
            } else {
                $response = $this->client->get($this->domain . '/friends');
            }
            $data = json_decode($response->getBody()->getContents(), false, 512, JSON_THROW_ON_ERROR);
            dd($data);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
            return response()->json([
                ['message' => 'error']
            ], $this->errorStatus);
        }
    }

    public function screenshots(): JsonResponse
    {
        try {
            $response = $this->client->get($this->domain . 'dvr/screenshots');
            $data = json_decode($response->getBody()->getContents(), false, 512, JSON_THROW_ON_ERROR);
            dd($data);
        } catch (\Exception $exception) {
            Log::error($exception->getMessage());
            return response()->json([
                ['message' => 'error']
            ], $this->errorStatus);
        }
    }

    public function redirect(): string
    {
        return 'success';
    }


}
