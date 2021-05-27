<?php

namespace App\Http\Controllers\API\Store;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;


class GoodsCatalogController extends Controller
{
    public $successStatus = 200;


    /**
     * Get one from goods api
     *
     * @return JsonResponse
     */
    public function makeGoodCatalog()
    {
        DB::table('good_to_catalogs')
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
    public function updateGoodCatalog($id)
    {
        DB::table('good_to_catalogs')
            ->where('catalog_id', '=', $id)
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
    public function deleteGoodCatalog($id)
    {
        DB::table('good_to_catalogs')
            ->where('catalog_id', '=', $id)
            ->update(request()->all());
        return response()->json([
            'success' => true
        ], $this->successStatus);
    }


}
