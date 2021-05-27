<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tournaments\Tournament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TournamentController extends Controller
{

    /**
     * Create tournament
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function create(Request $request): JsonResponse
    {
        $model = Tournament::create($request->all());
        return response()->json(['data' =>$model]);
    }

    /**
     * Update tournament by id
     *
     * @param Request $request
     * @param $id
     * @return JsonResponse
     */
    public function update(Request $request, $id): JsonResponse
    {
        $model = Tournament::whereId($id)->update($request->all());
        return response()->json(['data' =>$model]);
    }

    /**
     * Delete tournament by id
     *
     * @param $id
     * @return JsonResponse
     */
    public function delete($id): JsonResponse
    {
        $model = Tournament::whereId($id)->delete();
        return response()->json(['data' =>$model]);
    }



}
