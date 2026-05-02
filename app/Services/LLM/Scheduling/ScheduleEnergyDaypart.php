<?php

namespace App\Services\LLM\Scheduling;

/**
 * Shared local-hour bands for learned energy bias (focus history) and placement scoring.
 *
 * Bands use half-open ranges [start_hour, end_hour) on the session/start clock hour (0–23).
 */
final class ScheduleEnergyDaypart
{
    /**
     * @return array{morning: array{start_hour:int,end_hour:int}, afternoon: array{start_hour:int,end_hour:int}, evening: array{start_hour:int,end_hour:int}}
     */
    public static function bandsFromConfig(): array
    {
        $defaults = [
            'morning' => ['start_hour' => 8, 'end_hour' => 12],
            'afternoon' => ['start_hour' => 13, 'end_hour' => 18],
            'evening' => ['start_hour' => 18, 'end_hour' => 22],
        ];

        $raw = config('task-assistant.schedule.energy_dayparts', []);
        if (! is_array($raw)) {
            return $defaults;
        }

        $merge = function (string $key, array $fallback) use ($raw): array {
            $slice = is_array($raw[$key] ?? null) ? $raw[$key] : [];

            return [
                'start_hour' => self::clampHour((int) ($slice['start_hour'] ?? $fallback['start_hour'])),
                'end_hour' => self::clampHour((int) ($slice['end_hour'] ?? $fallback['end_hour'])),
            ];
        };

        $bands = [
            'morning' => $merge('morning', $defaults['morning']),
            'afternoon' => $merge('afternoon', $defaults['afternoon']),
            'evening' => $merge('evening', $defaults['evening']),
        ];

        foreach (array_keys($bands) as $label) {
            if ($bands[$label]['end_hour'] <= $bands[$label]['start_hour']) {
                $bands[$label] = $defaults[$label];
            }
        }

        return $bands;
    }

    /**
     * Which energy bucket a focus session start falls into, if any.
     */
    public static function bucketForStartHour(int $hour): ?string
    {
        $hour = self::clampHour($hour);
        $bands = self::bandsFromConfig();

        foreach (['morning', 'afternoon', 'evening'] as $label) {
            $start = $bands[$label]['start_hour'];
            $end = $bands[$label]['end_hour'];
            if ($hour >= $start && $hour < $end) {
                return $label;
            }
        }

        return null;
    }

    /**
     * Whether a proposal/start clock hour receives the energy_bias placement bonus.
     */
    public static function startHourFitsBias(int $hour, string $bias): bool
    {
        $bias = strtolower(trim($bias));
        if ($bias === 'balanced' || $bias === '') {
            return false;
        }

        $bucket = self::bucketForStartHour($hour);

        return $bucket !== null && $bucket === $bias;
    }

    private static function clampHour(int $hour): int
    {
        return max(0, min(23, $hour));
    }
}
