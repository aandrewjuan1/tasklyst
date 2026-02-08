<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class DateHelper
{
    /**
     * Parse an optional datetime value. Returns null for null or empty input.
     */
    public static function parseOptional(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable $e) {
            Log::error('Failed to parse datetime', [
                'input' => $value,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
