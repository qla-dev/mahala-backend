<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()?->suspended_at) {
            return response()->json(['message' => 'Račun je suspendovan zbog kršenja pravila zajednice.'], 403);
        }

        return $next($request);
    }
}
