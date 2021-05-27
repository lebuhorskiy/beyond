<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Pusher\Pusher;
use Pusher\PusherException;

class PusherController extends Controller
{

    public $pusher;

    public function __construct()
    {
        if (env('APP_URL') === 'http://localhost') {
            try {
                $this->pusher = new Pusher(env('PUSHER_APP_KEY_DEVELOPMENT'), env('PUSHER_APP_SECRET_DEVELOPMENT'), env('PUSHER_APP_ID_DEVELOPMENT'));
            } catch (PusherException $e) {
                Log::error($e->getTraceAsString());
            }
        } else {
            try {
                $this->pusher = new Pusher(env('PUSHER_APP_KEY_PRODUCTION'), env('PUSHER_APP_SECRET_PRODUCTION'), env('PUSHER_APP_ID_PRODUCTION'));
            } catch (PusherException $e) {
                Log::error($e->getTraceAsString());
            }
        }
    }


    public function auth(Request $request)
    {
        try {
            $auth = $this->pusher->socket_auth($request->channel_name, $request->socket_id);
            if ($auth) {
                return $auth;
            }
        } catch (PusherException $e) {
            Log::error($e->getTraceAsString());
        }
        return response()->json([], $this->accessDeniedStatus);
    }

}
