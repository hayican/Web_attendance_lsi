<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class LoginThrottleService
{
    public const int MAX_ACCOUNT_ATTEMPTS = 5;

    public const int MAX_IP_ATTEMPTS = 30;

    public const int LOCK_SECONDS = 900; // 15 menit

    public function lockVersion(?int $userId): int
    {
        if ($userId === null) {
            return 0;
        }

        /** @var int|numeric-string $version */
        $version = Cache::get("user_lock_version:{$userId}", 0);

        return (int) $version;
    }

    public function isLocked(string $login, string $ip, ?int $userId = null): bool
    {
        return $this->ipAttempts($ip) >= self::MAX_IP_ATTEMPTS
            || $this->accountAttempts($login, $ip, $userId) >= self::MAX_ACCOUNT_ATTEMPTS;
    }

    public function recordFailure(string $login, string $ip, ?int $userId = null): void
    {
        $ipKey = $this->ipKey($ip);
        Cache::add($ipKey, 0, now()->addSeconds(self::LOCK_SECONDS));
        Cache::increment($ipKey);

        $accountKey = $this->accountKey($login, $ip, $userId);
        Cache::add($accountKey, 0, now()->addSeconds(self::LOCK_SECONDS));
        Cache::increment($accountKey);
    }

    public function clearAccountFailures(string $login, string $ip, int $userId): void
    {
        Cache::forget($this->accountKey($login, $ip, $userId));
    }

    public function unlockUser(int $userId): int
    {
        $versionKey = "user_lock_version:{$userId}";
        Cache::add($versionKey, 0);

        return (int) Cache::increment($versionKey);
    }

    public function accountAttempts(string $login, string $ip, ?int $userId = null): int
    {
        /** @var int|numeric-string $attempts */
        $attempts = Cache::get($this->accountKey($login, $ip, $userId), 0);

        return (int) $attempts;
    }

    public function ipAttempts(string $ip): int
    {
        /** @var int|numeric-string $attempts */
        $attempts = Cache::get($this->ipKey($ip), 0);

        return (int) $attempts;
    }

    private function accountKey(string $login, string $ip, ?int $userId): string
    {
        if ($userId !== null) {
            return "login_fail:user:{$userId}:{$ip}:v{$this->lockVersion($userId)}";
        }

        return 'login_fail:unknown:'.mb_strtolower(trim($login)).":{$ip}";
    }

    private function ipKey(string $ip): string
    {
        return "login_ip:{$ip}";
    }
}
