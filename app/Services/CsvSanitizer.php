<?php

namespace App\Services;

class CsvSanitizer
{
    public function sanitizeCell(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (in_array($trimmed[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$trimmed;
        }

        return $trimmed;
    }

    public function hasFormulaPrefix(string $value): bool
    {
        $trimmed = ltrim($value);

        return $trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true);
    }
}
