<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Users\User;
use Illuminate\Http\JsonResponse;

class FSLController extends Controller
{
    /**
     * Check if FSL user actually registered on Beyond platform by its Epic id
     * @param $id
     * @return JsonResponse
     */
    public function epic_id_check($id): JsonResponse
    {
        if ($id) {
            $user = User::where('epic_id', '=', $id)->first();

            if ($user) {
                return response()->json(true);
            }
            return response()->json(false, $this->notFoundStatus);
        }
        return response()->json(['message' => 'No Epic ID provided!'], $this->accessDeniedStatus);
    }
}
