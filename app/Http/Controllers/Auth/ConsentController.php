<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ConsentController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if ($user->consent_accepted_at !== null) {
            return redirect()->to($this->homeUrl($user));
        }

        return Inertia::render('auth/Consent');
    }

    public function accept(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->update([
            'consent_accepted_at' => now(),
        ]);

        return redirect()->to($this->homeUrl($user));
    }

    public function decline(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors([
            'email' => 'Harap setujui akses kamera dan lokasi GPS jika ingin absen',
        ]);
    }

    private function homeUrl(User $user): string
    {
        return match ($user->role) {
            UserRole::HR_ADMIN => '/admin/dashboard',
            UserRole::EMPLOYEE_SALES => '/attendance/sales',
            default => '/attendance/office',
        };
    }
}
