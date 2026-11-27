<?php

namespace App\Http\Responses;

use App\Enums\UserRole;
use App\Http\Middleware\EnsureSingleSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user !== null) {
            $this->recordActiveSession($user);
        }

        if ($request->wantsJson()) {
            return new JsonResponse(['two_factor' => false], 200);
        }

        $target = match ($user?->role) {
            UserRole::HR_ADMIN => '/admin/dashboard',
            UserRole::EMPLOYEE_SALES => '/attendance/sales',
            default => '/attendance/office',
        };

        return new RedirectResponse($target);
    }

    /** PRD v8 single active session: the id must be stored before the client fires the redirect. */
    private function recordActiveSession(User $user): void
    {
        $sessionId = session()->getId();

        Cache::put(
            EnsureSingleSession::key((int) $user->getAuthIdentifier()),
            $sessionId,
            now()->addMinutes((int) config('session.lifetime')),
        );

        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->getAuthIdentifier())
                ->where('id', '!=', $sessionId)
                ->delete();
        }
    }
}
