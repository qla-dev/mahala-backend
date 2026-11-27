<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = $request->header('X-Agent-Secret') ?: $request->input('secret');
        $hash = config('services.agent.secret_hash');

        if (! is_string($secret) || $secret === '' || ! $hash || ! Hash::check($secret, $hash)) {
            return response()->json(['message' => 'Neispravan ili nedostaje agent secret.'], 403);
        }

        return $next($request);
    }
}
