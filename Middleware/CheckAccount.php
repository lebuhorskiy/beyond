<?php

namespace App\Http\Middleware;

use Closure;

class CheckAccount
{
    public function handle($request, Closure $next)
    {
        return $next($request);
    }
}
