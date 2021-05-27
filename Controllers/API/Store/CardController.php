<?php

namespace App\Http\Controllers\API\Store;

use App\Http\Controllers\Controller;
use App\Models\Store\Card;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;


class CardController extends Controller
{
    public $successStatus = 200;

    /**
     * Get list of all card api
     *
     * @return JsonResponse
     */
    public function getCards(): JsonResponse
    {
        $cards = DB::table('cards')
            ->where('user_id', '=', auth()->id())
            ->get();
        return response()->json([
            'cards' => $cards,
            'success' => true
        ], $this->successStatus);
    }

    /**
     * Get one from card api
     *
     * @return JsonResponse
     */
    public function getCard($id): JsonResponse
    {
        $card = DB::table('cards')
            ->where('user_id', '=', auth()->id())
            ->where('id', '=', $id)
            ->first();
        return response()->json([
            'card' => $card,
            'success' => true
        ], $this->successStatus);
    }

    /**
     * Get one from card api
     *
     * @return JsonResponse
     */
    public function makeCard(): JsonResponse
    {
        DB::table('cards')
            ->insert([
               'user_id'=>auth()->id()
            ]);
        return response()->json([
            'success' => true
        ], $this->successStatus);
    }

    /**
     * Update one from card api
     *
     * @return JsonResponse
     */
    public function updateCard($id): JsonResponse
    {
        DB::table('cards')
            ->where('user_id', '=', auth()->id())
            ->where('id', '=', $id)
            ->update(request()->all());
        return response()->json([
            'success' => true
        ], $this->successStatus);
    }

    /**
     * Delete one from card api
     *
     * @return JsonResponse
     */
    public function deleteCard(): JsonResponse
    {
        DB::table('cards')
            ->where('user_id', '=', auth()->id())
            ->update(request()->all());
        return response()->json([
            'success' => true
        ], $this->successStatus);
    }

    /**
     * Restore one from card api
     *
     * @return JsonResponse
     */
    public function restoreCard(): JsonResponse
    {
        DB::table('cards')
            ->where('user_id', '=', auth()->id())
            ->update(request()->all());
        return response()->json([
            'success' => true
        ], $this->successStatus);
    }


}
