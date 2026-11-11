<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedactInvitationToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->route('token');

        if (is_string($token)) {
            $request->attributes->set('invitation_token', $token);
            $request->route()->setParameter('token', '[REDACTED]');

            $requestUri = $request->server->get('REQUEST_URI');
            if (is_string($requestUri)) {
                $request->server->set(
                    'REQUEST_URI',
                    preg_replace(
                        '#(/invitations/)[a-f0-9]{64}(/(?:accept|decline))(?:\?.*)?$#',
                        '$1[REDACTED]$2',
                        $requestUri
                    )
                );
            }
        }

        return $next($request);
    }
}
