<?php

namespace App\Services;

class GeoLocationService
{
    private const float EARTH_RADIUS_METERS = 6371000.0;

    public function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * (sin($dLng / 2) ** 2);

        $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));

        return round(self::EARTH_RADIUS_METERS * $c, 2);
    }

    /**
     * Evaluate distance vs GPS accuracy according to FR-2.
     *
     * @return array{blocked: bool, block_message: string|null, flags: list<string>, requires_notes: bool}
     */
    public function evaluate(
        float $distanceM,
        float $accuracyM,
        int $radiusM,
        bool $isEntryPhase,
        bool $bypassRadius = false,
        bool $isVisitOut = false,
    ): array {
        $flags = [];
        $requiresNotes = false;

        if (! $bypassRadius) {
            $hardBlockLimit = max(200, $radiusM);

            if ($isEntryPhase && $distanceM > $hardBlockLimit) {
                return [
                    'blocked' => true,
                    'block_message' => sprintf(
                        'Jarak Anda %.0fm dari lokasi (batas maksimal %dm). Silakan mendekat ke lokasi.',
                        $distanceM,
                        $hardBlockLimit
                    ),
                    'flags' => [],
                    'requires_notes' => false,
                ];
            }

            if ($distanceM > $radiusM) {
                $flags[] = $isVisitOut ? 'OUT_OF_RADIUS_VISIT_OUT' : 'OUT_OF_RADIUS';
                $requiresNotes = true;
            } elseif ($accuracyM > $radiusM) {
                $flags[] = 'UNCERTAIN_ACCURACY';
                $requiresNotes = true;
            } elseif ($accuracyM > 100.0) {
                $flags[] = 'LOW_ACCURACY';
                $requiresNotes = true;
            }
        } elseif ($accuracyM > 100.0) {
            $flags[] = 'LOW_ACCURACY';
            $requiresNotes = true;
        }

        return [
            'blocked' => false,
            'block_message' => null,
            'flags' => $flags,
            'requires_notes' => $requiresNotes,
        ];
    }
}
