<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class RequirePersonalAccessToken
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            $request->user()?->currentAccessToken() instanceof PersonalAccessToken,
            401
        );

        return $next($request);
    }
}
