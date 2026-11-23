<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->must_change_password && ! $request->routeIs('security.edit',
            'user-password.update', 'logout')) {
            return redirect()->route('security.edit')
                ->with('status', 'Wajib ganti password baru sebelum melanjutkan.');
        }

        return $next($request);
    }
}
