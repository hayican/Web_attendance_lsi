<?php

namespace App\Http\Middleware;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();
        $office = $user !== null
            ? $user->officeLocation()->first(['name', 'timezone'])
            : null;

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            'office' => $office !== null
                ? ['name' => $office->name, 'timezone' => $office->timezone]
                : null,
            'serverTimeIso' => CarbonImmutable::now('UTC')->toIso8601String(),
            'notifications' => $user !== null
                ? $user->notifications()->latest()->limit(5)->get()->map(fn ($notification): array => [
                    'id' => $notification->id,
                    'title' => (string) ($notification->data['title'] ?? 'Notifikasi'),
                    'message' => (string) ($notification->data['message'] ?? ''),
                    'url' => isset($notification->data['url']) ? (string) $notification->data['url'] : null,
                    'read' => $notification->read_at !== null,
                    'created_at' => $notification->created_at?->toIso8601String(),
                ])->values()
                : [],
            'unreadNotifications' => $user !== null ? (int) $user->unreadNotifications()->count() : 0,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
