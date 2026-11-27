<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use App\Services\LoginThrottleService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            LoginResponse::class,
            \App\Http\Responses\LoginResponse::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));

        Fortify::authenticateUsing(function (Request $request) {
            $login = trim((string) $request->input('email'));
            $password = (string) $request->input('password');
            $ip = (string) $request->ip();

            $throttle = app(LoginThrottleService::class);
            $normalized = mb_strtolower($login);
            $user = User::query()
                ->where(function ($query) use ($normalized): void {
                    $query->whereRaw('LOWER(email) = ?', [$normalized])
                        ->orWhereRaw('LOWER(nik) = ?', [$normalized]);
                })
                ->first();

            if ($throttle->isLocked($login, $ip, $user?->id)) {
                throw ValidationException::withMessages([
                    'email' => ['Terlalu banyak percobaan login gagal. Coba lagi dalam 15 menit atau hubungi HR Admin untuk buka kunci.'],
                ]);
            }

            if ($user === null || ! $user->is_active || ! Hash::check($password,
                $user->password)) {
                $throttle->recordFailure($login, $ip, $user?->id);

                return null;
            }

            $throttle->clearAccountFailures($login, $ip, $user->id);

            return $user;
        });
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', fn (Request $request): Limit => Limit::none());
    }
}
