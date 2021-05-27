<?php

namespace App\Http\Controllers\API\Hubs;

use App\Events\Hubs\NewPrivateMessage;
use App\Events\Hubs\NewMessage;
use App\Http\Controllers\Controller;
use App\Models\Hubs\Fortnite\FortniteSoloMatch;
use App\Models\Hubs\Match;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Hubs\Message;
use Illuminate\Validation\ValidationException;

class ChatController extends Controller
{

    /**
     * Get limited messages
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getMessages(Request $request): JsonResponse
    {
        $match = null;

        // For match page chat
        if($request->get('match_uuid')) {
            if($request->get('type_game') === 'box-fights') {
                $match = FortniteSoloMatch::whereUuid($request->get('match_uuid'))
                    ->first();

                if($match) {
                    $messages = Message::with('user')
                        ->whereHasMorph(
                            'matchable',
                            FortniteSoloMatch::class
                        )
                        ->where('matchable_id', $match->id)
                        ->where('visible', true)
                        ->orderBy('id', 'desc')
                        ->limit(100)
                        ->get();
                } else {
                    $messages = [];
                }

            } else {
                $match = Match::whereUuid($request->get('match_uuid'))
                    ->first();

                if($match) {
                    $messages = Message::with('user')
                        ->whereHasMorph(
                            'matchable',
                            Match::class
                        )
                        ->where('matchable_id', $match->id)
                        ->where('visible', true)
                        ->orderBy('id', 'desc')
                        ->limit(100)
                        ->get();
                } else {
                    $messages = [];
                }
            }

            return response()->json(count($messages) ? $messages->reverse()->values() : []);
        }

        $page = ($request->get('page')) ?? null;

        // For hubs
        if($request->get('division') != 'null' && $request->get('type_game') != 'null') {
            $messages = Message::with('user')
                ->where([
                    ['discipline', '=', $request->get('discipline')],
                    ['division', '=', $request->get('division') ?? null],
                    ['type_game', '=', $request->get('type_game') ?? null],
                    ['visible', '=', true],
                ])
                ->orderBy('id', 'desc')
                ->limit(100)
                ->get();

            return response()->json($messages->reverse()->values());
        }

        // For other pages like missions
        if($request->get('page') != 'null') {
            $messages = Message::with('user')
                ->where([
                    ['messages.discipline', '=', $request->get('discipline')],
                    ['messages.page', '=', $page],
                    ['messages.visible', '=', true],
                ])
                ->orderBy('messages.id', 'desc')
                ->limit(100)
                ->get();

            return response()->json($messages->reverse()->values());
        }

        return response()->json([]);
    }

    /**
     * Send message to public chat
     *
     * @param Request $request
     * @return JsonResponse
     * @throws ValidationException
     */
    public function sendMessage(Request $request): JsonResponse
    {
        Validator::make($request->all(), [
            'message' => 'required|string',
        ])->validate();

        $muted = auth()->user()->limits()
            ->where('action', '=', 'mute')
            ->where('finished_at', '>=', Carbon::now())
            ->orderBy('finished_at', 'desc')
            ->first();

        if($muted) {
            return response()->json([
                'message' => 'Сообщение не было отправлено! Вы заблокированы в чате до '. Carbon::parse($muted->finished_at)->format('Y-m-d h:i:s') .'!'
            ], $this->accessDeniedStatus);
        }

        $match = null;

        if($request->get('type_game') === 'box-fights') {
            $match = FortniteSoloMatch::whereUuid($request->get('match_uuid'))->first();
        } else {
            $match = Match::whereUuid($request->get('match_uuid'))->first();
        }

        if($match) {
            $create = new Message([
                'user_id' => auth()->id(),
                'text' => $request->get('message'),
            ]);

            $create->matchable()->associate($match)->save();

            $message = Message::with('user')->find($create->id);

            event(new NewPrivateMessage($message, $match->uuid));

        } elseif ($request->get('page') != null){
            $create = Message::create([
                'user_id' => auth()->id(),
                'text' => $request->get('message'),
                'discipline' => $request->get('discipline'),
                'page' => $request->get('page'),
            ]);

            $message = Message::with('user')->find($create->id);

            event(new \App\Events\Missions\NewMessage($message, $request->get('discipline'), $request->get('page')));
        } else {
            $create = Message::create([
                'user_id' => auth()->id(),
                'text' => $request->get('message'),
                'discipline' => $request->get('discipline'),
                'division' => $request->get('division'),
                'type_game' => $request->get('type_game'),
            ]);

            $message = Message::with('user')->find($create->id);

            event(new NewMessage($message, $request->get('discipline'), $request->get('type_game'), $request->get('division')));
        }

        return response()->json($message);
    }

    /**
     * Delete message
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteMessage(Request $request): JsonResponse
    {
        $message = Message::find($request->messageID);

        if (!$message) {
            return response()->json([
                'error' => __('messages.message_not_found'),
                'success' => false
            ], $this->notFoundStatus);
        }

        $message->delete();

        return response()->json([
            'message' => __('messages.message_deleted'),
            'success' => true,
        ]);
    }
}
