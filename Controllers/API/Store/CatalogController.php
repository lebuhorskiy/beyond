<?php

namespace App\Http\Controllers\API\Store;

use App\Http\Controllers\Controller;
use App\Models\Store\Catalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CatalogController extends Controller
{
    public $successStatus = 200;

    /**
     * Get list of all catalogs api
     *
     * @return JsonResponse
     */
    public function getCatalogs(): JsonResponse
    {
        $catalogs = Catalog::with('childrens')
            ->get();
        return response()->json([
            'catalogs' => $catalogs,
            'success' => true
        ], $this->successStatus);
    }

    /**
     * Get one from catalog api
     *
     * @return JsonResponse
     */
    public function getCatalog($id): JsonResponse
    {
        $catalog = Catalog::where('id', '=', $id)
            ->with('goods')
            ->first();
        return response()->json([
            'catalog' => $catalog,
            'success' => true
        ], $this->successStatus);
    }

    /**
     * Get one from catalog api
     *
     * @return JsonResponse
     */
    public function makeCatalog(): JsonResponse
    {
        DB::table('catalogs')
            ->insert(request()->all());
        return response()->json([
            'success' => true
        ], $this->successStatus);
    }

    /**
     * Update one from catalog api
     *
     * @return JsonResponse
     */
    public function updateCatalog($id): JsonResponse
    {
        DB::table('catalogs')
            ->where('id', '=', $id)
            ->update(request()->all());
        return response()->json([
            'success' => true
        ], $this->successStatus);
    }

    /**
     * Delete one from catalog api
     *
     * @return JsonResponse
     */
    public function deleteCatalog($id): JsonResponse
    {
        DB::table('catalogs')
            ->where('id', '=', $id)
            ->update(request()->all());
        return response()->json([
            'success' => true
        ], $this->successStatus);
    }


}
