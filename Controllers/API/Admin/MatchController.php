<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tournaments\TournamentMatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MatchController extends Controller
{

    /**
     * Create tournament
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function create(Request $request): JsonResponse
    {
        $model = TournamentMatch::create($request->all());
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
        $model = TournamentMatch::whereId($id)->update($request->all());
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
        $model = TournamentMatch::whereId($id)->delete();
        return response()->json(['data' =>$model]);
    }



}
