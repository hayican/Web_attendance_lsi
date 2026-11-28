<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TokenGuardService
{
    public const int TTL_SECONDS = 300;

    public const int STALE_AFTER_SECONDS = 120;

    /**
     * @return array{token: string, issued_at: int, expires_at: int}
     */
    public function issueGpsSessionToken(int $userId): array
    {
        return $this->issueToken('gps_token', $userId);
    }

    /**
     * @return array{token: string, issued_at: int, expires_at: int}
     */
    public function issueCameraToken(int $userId): array
    {
        return $this->issueToken('camera_token', $userId);
    }

    /**
     * @return array{stale: bool, age_seconds: int}
     *
     * @throws ValidationException
     */
    public function validateGpsSessionToken(string $token, int $userId): array
    {
        return $this->validateToken('gps_token', $token, $userId, 'gps_session_token', 'Sesi GPS tidak valid atau sudah kedaluwarsa. Silakan muat ulang GPS.');
    }

    /**
     * @return array{stale: bool, age_seconds: int}
     *
     * @throws ValidationException
     */
    public function validateCameraToken(string $token, int $userId): array
    {
        return $this->validateToken('camera_token', $token, $userId, 'camera_token', 'Sesi kamera tidak valid atau sudah kedaluwarsa. Silakan buka ulang kamera.');
    }

    /**
     * @return array{stale: bool, age_seconds: int}
     *
     * @throws ValidationException
     */
    public function consumeGpsSessionToken(string $token, int $userId): array
    {
        return $this->consumeToken('gps_token', $token, $userId, 'gps_session_token', 'Sesi GPS tidak valid atau sudah kedaluwarsa. Silakan muat ulang GPS.');
    }

    /**
     * @return array{stale: bool, age_seconds: int}
     *
     * @throws ValidationException
     */
    public function consumeCameraToken(string $token, int $userId): array
    {
        return $this->consumeToken('camera_token', $token, $userId, 'camera_token', 'Sesi kamera tidak valid atau sudah kedaluwarsa. Silakan buka ulang kamera.');
    }

    /**
     * @return array{token: string, issued_at: int, expires_at: int}
     */
    private function issueToken(string $prefix, int $userId): array
    {
        $token = (string) Str::uuid();
        $issuedAt = (int) now()->getTimestamp();

        Cache::put("{$prefix}:{$token}", [
            'user_id' => $userId,
            'issued_at' => $issuedAt,
        ], now()->addSeconds(self::TTL_SECONDS));

        return [
            'token' => $token,
            'issued_at' => $issuedAt,
            'expires_at' => $issuedAt + self::TTL_SECONDS,
        ];
    }

    /**
     * @return array{stale: bool, age_seconds: int}
     *
     * @throws ValidationException
     */
    private function validateToken(string $prefix, string $token, int $userId, string $field, string $errorMessage): array
    {
        /** @var array{user_id: int, issued_at: int}|null $payload */
        $payload = Cache::get("{$prefix}:{$token}");

        if ($payload === null || (int) $payload['user_id'] !== $userId) {
            throw ValidationException::withMessages([$field => [$errorMessage]]);
        }

        $ageSeconds = max(0, (int) now()->getTimestamp() - (int) $payload['issued_at']);

        if ($ageSeconds > self::TTL_SECONDS) {
            throw ValidationException::withMessages([$field => [$errorMessage]]);
        }

        return [
            'stale' => $ageSeconds > self::STALE_AFTER_SECONDS,
            'age_seconds' => $ageSeconds,
        ];
    }

    /**
     * @return array{stale: bool, age_seconds: int}
     *
     * @throws ValidationException
     */
    private function consumeToken(string $prefix, string $token, int $userId, string $field, string $errorMessage): array
    {
        /** @var array{user_id: int, issued_at: int}|null $payload */
        $payload = Cache::pull("{$prefix}:{$token}");

        if ($payload === null || (int) $payload['user_id'] !== $userId) {
            throw ValidationException::withMessages([$field => [$errorMessage]]);
        }

        $ageSeconds = max(0, (int) now()->getTimestamp() - (int) $payload['issued_at']);

        if ($ageSeconds > self::TTL_SECONDS) {
            throw ValidationException::withMessages([$field => [$errorMessage]]);
        }

        return [
            'stale' => $ageSeconds > self::STALE_AFTER_SECONDS,
            'age_seconds' => $ageSeconds,
        ];
    }
}
