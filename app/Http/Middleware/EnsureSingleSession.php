<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the PRD v8 single active session rule: a new login invalidates every
 * older session of the same account, even when sessions live in Redis.
 */
class EnsureSingleSession
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $activeSessionId = Cache::get(self::key((int) $user->getAuthIdentifier()));

        if ($activeSessionId !== null && $activeSessionId !== $request->session()->getId()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->with('status', 'Sesi Anda diakhiri karena akun login di perangkat lain.');
        }

        return $next($request);
    }

    public static function key(int $userId): string
    {
        return "active_session:{$userId}";
    }
}
