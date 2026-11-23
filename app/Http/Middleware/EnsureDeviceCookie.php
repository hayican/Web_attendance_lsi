<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureDeviceCookie
{
    public const string COOKIE_NAME = 'lsi_device_id';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $deviceId = $request->cookie(self::COOKIE_NAME);

        if (! is_string($deviceId) || ! Str::isUuid($deviceId)) {
            $deviceId = (string) Str::uuid();
            $request->attributes->set(self::COOKIE_NAME, $deviceId);

            $response = $next($request);
            $response->headers->setCookie(cookie(
                name: self::COOKIE_NAME,
                value: $deviceId,
                minutes: 60 * 24 * 365,
                path: '/',
                domain: null,
                secure: $request->isSecure(),
                httpOnly: true,
                raw: false,
                sameSite: 'lax',
            ));

            return $response;
        }

        $request->attributes->set(self::COOKIE_NAME, $deviceId);

        return $next($request);
    }
}
