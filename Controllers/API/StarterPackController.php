<?php

namespace App\Http\Controllers\API;

use App\Helpers\ApplyStarterPackHelper;
use App\Http\Controllers\Controller;
use App\Models\StarterPacks\StarterPack;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class StarterPackController extends Controller
{

    /**
     * Create starter pack request api
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function create(Request $request): JsonResponse
    {
        StarterPack::create($request->all());
        return response()->json([
            'message' => 'success',
        ]);
    }

    /**
     * Update starter pack request api
     *
     * @param Request $request
     * @param $id
     * @return JsonResponse
     */
    public function update(Request $request, $id): JsonResponse
    {
        StarterPack::whereId($id)->update($request->all());
        return response()->json([
            'message' => 'success',
        ]);
    }

    /**
     * Delete starter pack request api
     *
     * @param Request $request
     * @param $id
     * @return JsonResponse
     */
    public function delete(Request $request, $id): JsonResponse
    {
        StarterPack::whereId($id)->delete();
        return response()->json([
            'message' => 'success',
        ]);
    }


    /**
     * Apply starter pack for user request api
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function apply(Request $request): JsonResponse
    {
        //ID for starter pack
        $id = $request->post('id');
        //Type for starter pack (mission, wheel, b_points)
        $type = $request->post('type');
        $message = ApplyStarterPackHelper::apply(auth()->id(), $id, $type);
        return response()->json([
            'message' => $message,
        ]);
    }

    /**
     * Get starter packs request api
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function list(Request $request): JsonResponse
    {
        $data = DB::table('starter_packs')->get();
        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * Get starter pack request api
     *
     * @param Request $request
     * @param $id
     * @return JsonResponse
     */
    public function get(Request $request, $id): JsonResponse
    {
        $data = DB::table('starter_packs')
            ->whereId($id)
            ->first();
        return response()->json([
            'data' => $data,
        ]);
    }


}
