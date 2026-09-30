<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CookieToBearerToken
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->headers->has('Authorization')) {
            $token = $request->cookie('openscore_auth_token') ?? ($_COOKIE['openscore_auth_token'] ?? null);
            if ($token) {
                $token = urldecode($token);
                $request->headers->set('Authorization', 'Bearer ' . $token);
                $request->headers->set('authorization', 'Bearer ' . $token);
                $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
            }
        }

        return $next($request);
    }
}
