<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;

class CheckApiToken
{
    public function handle($request, Closure $next): JsonResponse
    {
        if(!in_array($request->headers->get('accept'), ['application/json', 'Application/Json'])) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }
        return $next($request);
    }
}
