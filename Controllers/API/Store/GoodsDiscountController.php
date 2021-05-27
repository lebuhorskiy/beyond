<?php

namespace App\Http\Controllers\API\Store;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;


class GoodsDiscountController extends Controller
{
    public $successStatus = 200;



    /**
     * Get one from goods api
     *
     * @return JsonResponse
     */
    public function makeGoodDiscount()
    {
        DB::table('goods_discounts')
            ->insert(request()->all());
        return response()->json([
            'success' => true
        ], $this->successStatus);
    }

    /**
     * Update one from goods api
     *
     * @return JsonResponse
     */
    public function updateGoodDiscount($id)
    {
        DB::table('goods_discounts')
            ->where('id', '=', $id)
            ->update(request()->all());
        return response()->json([
            'success' => true
        ], $this->successStatus);
    }

    /**
     * Delete one from goods api
     *
     * @return JsonResponse
     */
    public function deleteGoodDiscount($id)
    {
        DB::table('goods_discounts')
            ->where('id', '=', $id)
            ->update(request()->all());
        return response()->json([
            'success' => true
        ], $this->successStatus);
    }


}
