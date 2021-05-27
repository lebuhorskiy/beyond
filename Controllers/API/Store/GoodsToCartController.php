<?php

namespace App\Http\Controllers\API\Store;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;


class GoodsToCartController extends Controller
{
    public $successStatus = 200;

    /**
     * Get list of all goods api
     *
     * @return JsonResponse
     */
    public function getGoodsToUser()
    {
        $goods = \Cart::getContent();
        return response()->json([
            'goods' => $goods,
            'success' => true
        ], $this->successStatus);
    }

    /**
     * Get list of goods by catalog id api
     *
     * @return JsonResponse
     */
    public function getGoodsToUserByGoods($id)
    {
        $goods = DB::table('good_to_cart')
            ->where('good_id', '=', $id)
            ->where('user_id','=',auth()->id())
            ->get();
        return response()->json([
            'goods' => $goods,
            'success' => true
        ], $this->successStatus);
    }



    /**
     * Get one from goods api
     *
     * @return JsonResponse
     */
    public function makeGoodToUser()
    {
        DB::table('good_to_users')
            ->insert([
                'user_id'=>auth()->id(),
                'good_id'=>request()->post('good_id'),
                'available'=>request()->post('available'),
            ]);
        return response()->json([
            'success' => true
        ], $this->successStatus);
    }

    /**
     * Update one from goods api
     *
     * @return JsonResponse
     */
    public function updateGoodToUser($id)
    {
        DB::table('good_to_users')
            ->where('good_id', '=', $id)
            ->where('user_id', '=', auth()->id())
            ->update(
                [
                    'user_id'=>auth()->id(),
                    'good_id'=>request()->post('good_id'),
                    'available'=>request()->post('available'),
                ]
            );
        return response()->json([
            'success' => true
        ], $this->successStatus);
    }

    /**
     * Delete one from goods api
     *
     * @return JsonResponse
     */
    public function deleteGoodToUser($id)
    {
        DB::table('good_to_users')
            ->where('good_id', '=', $id)
            ->where('user_id', '=', auth()->id())
            ->delete();
        return response()->json([
            'success' => true
        ], $this->successStatus);
    }


}
