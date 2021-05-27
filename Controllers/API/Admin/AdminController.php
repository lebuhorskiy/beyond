<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Users\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{

    /**
     * Get user roles
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getRolesList(Request $request): ?JsonResponse
    {
        if($request->limit >= config('api.max_per_page')){
            $request->limit = config('api.max_per_page');
        }
        $roles = Role::query()->paginate($request->limit, ['*'], 'page', $request->page);
        return \response()->json($roles);
    }
}
