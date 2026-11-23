<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureConsentAccepted
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->consent_accepted_at === null && ! $request->routeIs('consent.*', 'security.edit',
            'user-password.update', 'logout')) {
            return redirect()->route('consent.show');
        }

        return $next($request);
    }
}
