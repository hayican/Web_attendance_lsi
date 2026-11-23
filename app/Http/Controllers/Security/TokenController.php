<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TokenGuardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TokenController extends Controller
{
    public function __construct(private readonly TokenGuardService $tokenGuard) {}

    public function issueGps(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(
            $this->tokenGuard->issueGpsSessionToken($user->id)
        );
    }

    public function issueCamera(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(
            $this->tokenGuard->issueCameraToken($user->id)
        );
    }
}
