<?php

namespace App\Services;

use App\Enums\AttendanceSource;
use App\Enums\VerificationStatus;
use App\Enums\VisitStatus;
use App\Models\AttendanceLog;
use App\Models\SalesVisit;
use Carbon\CarbonImmutable;

class AntiFraudService
{
    public function __construct(private readonly GeoLocationService $geo) {}

    /**
     * Run FR-3 (anti-fake GPS) and FR-4 (shared device) checks.
     *
     * @return array{flags: list<string>, shared_device: bool}
     */
    public function evaluate(
        int $userId,
        string $localDate,
        float $lat,
        float $lng,
        float $accuracyM,
        CarbonImmutable $nowUtc,
        string $deviceId,
        bool $isInsideRadius,
        bool $isStaleTimestamp,
        ?int $currentOutletId = null,
    ): array {
        $flags = [];

        if ($isStaleTimestamp) {
            $flags[] = 'STALE_TIMESTAMP';
        }

        if ($this->detectImpossibleTravel($userId, $localDate, $lat, $lng, $accuracyM, $nowUtc)) {
            $flags[] = 'IMPOSSIBLE_TRAVEL';
        }

        $repeatedCoords = $this->detectRepeatedCoords($userId, $lat, $lng, $nowUtc, $currentOutletId);
        if ($repeatedCoords) {
            $flags[] = 'REPEATED_COORDS';
        }

        if ($this->detectConstantAccuracy($userId, $accuracyM, $nowUtc, $isInsideRadius, $repeatedCoords)) {
            $flags[] = 'CONSTANT_ACCURACY';
        }

        $sharedDevice = $this->detectAndMarkSharedDevice($userId, $deviceId, $localDate);
        if ($sharedDevice) {
            $flags[] = 'SHARED_DEVICE';
        }

        return [
            'flags' => array_values(array_unique($flags)),
            'shared_device' => $sharedDevice,
        ];
    }

    public function detectImpossibleTravel(
        int $userId,
        string $localDate,
        float $lat,
        float $lng,
        float $accuracyM,
        CarbonImmutable $nowUtc,
    ): bool {
        /** @var list<array{at: int, lat: float, lng: float, acc: float}> $points */
        $points = [];

        // Points with accuracy worse than 500 m are noise for travel speed (PRD v8 FR-3).
        $isUsableAccuracy = static fn (?string $acc): bool => $acc === null || (float) $acc <= 500.0;

        $logs = AttendanceLog::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', $localDate)
            ->where('source', AttendanceSource::APP)
            ->get();

        foreach ($logs as $log) {
            if ($log->clock_in_at !== null && $log->clock_in_lat !== null && $log->clock_in_lng !== null
                && $isUsableAccuracy($log->clock_in_accuracy_m)) {
                $points[] = [
                    'at' => (int) $log->clock_in_at->getTimestamp(),
                    'lat' => (float) $log->clock_in_lat,
                    'lng' => (float) $log->clock_in_lng,
                    'acc' => (float) ($log->clock_in_accuracy_m ?? 0),
                ];
            }
            if ($log->clock_out_at !== null && $log->clock_out_lat !== null && $log->clock_out_lng !== null
                && $isUsableAccuracy($log->clock_out_accuracy_m)) {
                $points[] = [
                    'at' => (int) $log->clock_out_at->getTimestamp(),
                    'lat' => (float) $log->clock_out_lat,
                    'lng' => (float) $log->clock_out_lng,
                    'acc' => (float) ($log->clock_out_accuracy_m ?? 0),
                ];
            }
        }

        $visits = SalesVisit::query()
            ->where('user_id', $userId)
            ->whereDate('visit_date', $localDate)
            ->where('source', AttendanceSource::APP)
            ->where('visit_status', '!=', VisitStatus::CANCELLED)
            ->get();

        foreach ($visits as $visit) {
            if ($visit->visit_in_at !== null && $visit->visit_in_lat !== null && $visit->visit_in_lng !== null
                && $isUsableAccuracy($visit->visit_in_accuracy_m)) {
                $points[] = [
                    'at' => (int) $visit->visit_in_at->getTimestamp(),
                    'lat' => (float) $visit->visit_in_lat,
                    'lng' => (float) $visit->visit_in_lng,
                    'acc' => (float) ($visit->visit_in_accuracy_m ?? 0),
                ];
            }
            if ($visit->visit_out_at !== null && $visit->visit_out_lat !== null && $visit->visit_out_lng !== null
                && $isUsableAccuracy($visit->visit_out_accuracy_m)) {
                $points[] = [
                    'at' => (int) $visit->visit_out_at->getTimestamp(),
                    'lat' => (float) $visit->visit_out_lat,
                    'lng' => (float) $visit->visit_out_lng,
                    'acc' => (float) ($visit->visit_out_accuracy_m ?? 0),
                ];
            }
        }

        if ($points === []) {
            return false;
        }

        usort($points, fn (array $a, array $b): int => $b['at'] <=> $a['at']);
        $latest = $points[0];

        $rawDistanceKm = $this->geo->haversineMeters($latest['lat'], $latest['lng'], $lat, $lng) / 1000.0;
        $netDistanceKm = $rawDistanceKm - (($latest['acc'] + $accuracyM) / 1000.0);
        $seconds = max(1, abs((int) $nowUtc->getTimestamp() - $latest['at']));
        $speedKmh = max(0.0, $netDistanceKm) / ($seconds / 3600.0);

        return $netDistanceKm > 5.0 && $speedKmh > 120.0;
    }

    public function detectRepeatedCoords(
        int $userId,
        float $lat,
        float $lng,
        CarbonImmutable $nowUtc,
        ?int $currentOutletId = null,
    ): bool {
        $since = $nowUtc->subDays(7);
        $targetLat = round($lat, 6);
        $targetLng = round($lng, 6);

        $matchCount = 0;
        /** @var array<int, true> $matchedOutlets */
        $matchedOutlets = [];
        if ($currentOutletId !== null) {
            $matchedOutlets[$currentOutletId] = true;
        }

        $logs = AttendanceLog::query()
            ->where('user_id', $userId)
            ->where('source', AttendanceSource::APP)
            ->where('created_at', '>=', $since)
            ->get();

        foreach ($logs as $log) {
            if ($log->clock_in_lat !== null && $log->clock_in_lng !== null
                && round((float) $log->clock_in_lat, 6) === $targetLat
                && round((float) $log->clock_in_lng, 6) === $targetLng) {
                $matchCount++;
            }
            if ($log->clock_out_lat !== null && $log->clock_out_lng !== null
                && round((float) $log->clock_out_lat, 6) === $targetLat
                && round((float) $log->clock_out_lng, 6) === $targetLng) {
                $matchCount++;
            }
        }

        $visits = SalesVisit::query()
            ->where('user_id', $userId)
            ->where('source', AttendanceSource::APP)
            ->where('created_at', '>=', $since)
            ->get();

        foreach ($visits as $visit) {
            $matchedThisVisit = false;

            if ($visit->visit_in_lat !== null && $visit->visit_in_lng !== null
                && round((float) $visit->visit_in_lat, 6) === $targetLat
                && round((float) $visit->visit_in_lng, 6) === $targetLng) {
                $matchCount++;
                $matchedThisVisit = true;
            }
            if ($visit->visit_out_lat !== null && $visit->visit_out_lng !== null
                && round((float) $visit->visit_out_lat, 6) === $targetLat
                && round((float) $visit->visit_out_lng, 6) === $targetLng) {
                $matchCount++;
                $matchedThisVisit = true;
            }

            if ($matchedThisVisit) {
                $matchedOutlets[$visit->outlet_location_id] = true;
            }
        }

        if ($currentOutletId !== null && count($matchedOutlets) >= 2) {
            return true;
        }

        return ($matchCount + 1) >= 5;
    }

    public function detectConstantAccuracy(
        int $userId,
        float $accuracyM,
        CarbonImmutable $nowUtc,
        bool $isInsideRadius,
        bool $hasRepeatedCoords,
    ): bool {
        if ($isInsideRadius && ! $hasRepeatedCoords) {
            return false;
        }

        $since = $nowUtc->subDays(7);
        $targetAcc = round($accuracyM, 2);

        /** @var list<array{at: int, acc: float}> $history */
        $history = [];

        $logs = AttendanceLog::query()
            ->where('user_id', $userId)
            ->where('source', AttendanceSource::APP)
            ->where('created_at', '>=', $since)
            ->get();

        foreach ($logs as $log) {
            if ($log->clock_in_at !== null && $log->clock_in_accuracy_m !== null) {
                $history[] = ['at' => (int) $log->clock_in_at->getTimestamp(), 'acc' => round((float) $log->clock_in_accuracy_m, 2)];
            }
            if ($log->clock_out_at !== null && $log->clock_out_accuracy_m !== null) {
                $history[] = ['at' => (int) $log->clock_out_at->getTimestamp(), 'acc' => round((float) $log->clock_out_accuracy_m, 2)];
            }
        }

        $visits = SalesVisit::query()
            ->where('user_id', $userId)
            ->where('source', AttendanceSource::APP)
            ->where('created_at', '>=', $since)
            ->get();

        foreach ($visits as $visit) {
            if ($visit->visit_in_at !== null && $visit->visit_in_accuracy_m !== null) {
                $history[] = ['at' => (int) $visit->visit_in_at->getTimestamp(), 'acc' => round((float) $visit->visit_in_accuracy_m, 2)];
            }
            if ($visit->visit_out_at !== null && $visit->visit_out_accuracy_m !== null) {
                $history[] = ['at' => (int) $visit->visit_out_at->getTimestamp(), 'acc' => round((float) $visit->visit_out_accuracy_m, 2)];
            }
        }

        if (count($history) < 4) {
            return false;
        }

        usort($history, fn (array $a, array $b): int => $b['at'] <=> $a['at']);
        $lastFour = array_slice($history, 0, 4);

        foreach ($lastFour as $item) {
            if ($item['acc'] !== $targetAcc) {
                return false;
            }
        }

        return true;
    }

    public function detectAndMarkSharedDevice(int $userId, string $deviceId, string $localDate): bool
    {
        if ($deviceId === '') {
            return false;
        }

        $foundOtherUser = false;

        $otherLogs = AttendanceLog::query()
            ->where('user_id', '!=', $userId)
            ->where('source', AttendanceSource::APP)
            ->whereDate('work_date', $localDate)
            ->where(function ($query) use ($deviceId): void {
                $query->where('clock_in_device_id', $deviceId)
                    ->orWhere('clock_out_device_id', $deviceId);
            })
            ->get();

        foreach ($otherLogs as $log) {
            $foundOtherUser = true;
            $updates = [];

            if ($log->clock_in_device_id === $deviceId) {
                $flags = $log->clock_in_anomaly_flags ?? [];
                if (! in_array('SHARED_DEVICE', $flags, true)) {
                    $flags[] = 'SHARED_DEVICE';
                    $updates['clock_in_anomaly_flags'] = $flags;
                }
                if ($log->clock_in_status === VerificationStatus::AUTO_APPROVED) {
                    $updates['clock_in_status'] = VerificationStatus::PENDING_REVIEW;
                }
            }

            if ($log->clock_out_device_id === $deviceId) {
                $flags = $log->clock_out_anomaly_flags ?? [];
                if (! in_array('SHARED_DEVICE', $flags, true)) {
                    $flags[] = 'SHARED_DEVICE';
                    $updates['clock_out_anomaly_flags'] = $flags;
                }
                if ($log->clock_out_status === VerificationStatus::AUTO_APPROVED) {
                    $updates['clock_out_status'] = VerificationStatus::PENDING_REVIEW;
                }
            }

            if ($updates !== []) {
                $log->update($updates);
            }
        }

        $otherVisits = SalesVisit::query()
            ->where('user_id', '!=', $userId)
            ->where('source', AttendanceSource::APP)
            ->whereDate('visit_date', $localDate)
            ->where(function ($query) use ($deviceId): void {
                $query->where('visit_in_device_id', $deviceId)
                    ->orWhere('visit_out_device_id', $deviceId);
            })
            ->get();

        foreach ($otherVisits as $visit) {
            $foundOtherUser = true;
            $updates = [];
            $flags = $visit->anomaly_flags ?? [];

            if (! in_array('SHARED_DEVICE', $flags, true)) {
                $flags[] = 'SHARED_DEVICE';
                $updates['anomaly_flags'] = $flags;
            }

            if ($visit->verification_status === VerificationStatus::AUTO_APPROVED) {
                $updates['verification_status'] = VerificationStatus::PENDING_REVIEW;
            }

            if ($updates !== []) {
                $visit->update($updates);
            }
        }

        return $foundOtherUser;
    }
}
