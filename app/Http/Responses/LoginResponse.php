<?php

namespace App\Http\Responses;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): Response
    {
        /** @var User|null $user */
        $user = $request->user();

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
}
