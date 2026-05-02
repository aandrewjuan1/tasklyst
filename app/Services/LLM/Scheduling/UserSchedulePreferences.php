<?php

namespace App\Services\LLM\Scheduling;

use App\Models\User;

class UserSchedulePreferences
{
    /**
     * @return array{
     *   schema_version:int,
     *   energy_bias:'morning'|'afternoon'|'balanced'|'evening',
     *   day_bounds:array{start:string,end:string},
     *   lunch_block:array{enabled:bool,start:string,end:string}
     * }
     */
    public static function normalizedForUser(User $user): array
    {
        return self::normalize($user->schedule_preferences);
    }

    public static function timezoneForUser(User $user): string
    {
        $timezone = is_string($user->timezone) ? trim($user->timezone) : '';

        return $timezone !== '' ? $timezone : (string) config('app.timezone', 'UTC');
    }

    /**
     * @return array{
     *   schema_version:int,
     *   energy_bias:'morning'|'afternoon'|'balanced'|'evening',
     *   day_bounds:array{start:string,end:string},
     *   lunch_block:array{enabled:bool,start:string,end:string}
     * }
     */
    public static function normalize(mixed $raw): array
    {
        $preferences = is_array($raw) ? $raw : [];
        $dayBounds = is_array($preferences['day_bounds'] ?? null) ? $preferences['day_bounds'] : [];
        $lunchBlock = is_array($preferences['lunch_block'] ?? null) ? $preferences['lunch_block'] : [];
        $energyBias = (string) ($preferences['energy_bias'] ?? 'balanced');

        if (! in_array($energyBias, ['morning', 'afternoon', 'balanced', 'evening'], true)) {
            $energyBias = 'balanced';
        }

        return [
            'schema_version' => (int) ($preferences['schema_version'] ?? 1),
            'energy_bias' => $energyBias,
            'day_bounds' => [
                'start' => self::normalizeTimeValue($dayBounds['start'] ?? null, '08:00'),
                'end' => self::normalizeTimeValue($dayBounds['end'] ?? null, '22:00'),
            ],
            'lunch_block' => [
                'enabled' => (bool) ($lunchBlock['enabled'] ?? true),
                'start' => self::normalizeTimeValue($lunchBlock['start'] ?? null, '12:00'),
                'end' => self::normalizeTimeValue($lunchBlock['end'] ?? null, '13:00'),
            ],
        ];
    }

    private static function normalizeTimeValue(mixed $value, string $default): string
    {
        if (! is_string($value)) {
            return $default;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : $default;
    }
}
