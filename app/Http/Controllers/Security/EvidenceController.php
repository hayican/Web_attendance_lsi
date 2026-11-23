<?php

namespace App\Http\Controllers\Security;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EvidenceController extends Controller
{
    public function show(Request $request): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $path = (string) $request->query('path', '');

        if ($path === '' || str_contains($path, '..') || ! str_starts_with($path, 'evidence/')) {
            abort(404);
        }

        $segments = explode('/', $path);
        $ownerId = isset($segments[1]) && is_numeric($segments[1]) ? (int) $segments[1] : null;

        if ($ownerId === null) {
            abort(404);
        }

        if ($user->role !== UserRole::HR_ADMIN && $user->id !== $ownerId) {
            abort(403, 'Anda tidak berwenang mengakses berkas bukti ini.');
        }

        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return Storage::disk('local')->response($path);
    }
}
