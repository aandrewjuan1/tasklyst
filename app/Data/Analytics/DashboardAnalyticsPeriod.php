<?php

namespace App\Data\Analytics;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

readonly class DashboardAnalyticsPeriod
{
    public function __construct(
        public string $preset,
        public CarbonImmutable $currentStart,
        public CarbonImmutable $currentEnd,
        public CarbonImmutable $previousStart,
        public CarbonImmutable $previousEnd,
    ) {}

    public static function fromPreset(string $preset, ?CarbonInterface $anchor = null): self
    {
        $timezone = (string) config('app.timezone');
        $anchorDate = $anchor !== null
            ? CarbonImmutable::parse($anchor)->timezone($timezone)
            : CarbonImmutable::now($timezone);

        $normalizedPreset = strtolower(trim($preset));

        return match ($normalizedPreset) {
            'daily' => self::slidingWindow($normalizedPreset, $anchorDate, 7),
            'weekly' => self::slidingWindow($normalizedPreset, $anchorDate, 30),
            'monthly' => self::slidingWindow($normalizedPreset, $anchorDate, 90),
            '7d' => self::slidingWindow($normalizedPreset, $anchorDate, 7),
            '30d' => self::slidingWindow($normalizedPreset, $anchorDate, 30),
            '90d' => self::slidingWindow($normalizedPreset, $anchorDate, 90),
            'this_month' => self::thisMonth($normalizedPreset, $anchorDate),
            default => throw new InvalidArgumentException('Unsupported analytics preset.'),
        };
    }

    private static function slidingWindow(string $preset, CarbonImmutable $anchorDate, int $days): self
    {
        $currentEnd = $anchorDate->endOfDay();
        $currentStart = $currentEnd->subDays($days - 1)->startOfDay();

        $previousEnd = $currentStart->subDay()->endOfDay();
        $previousStart = $previousEnd->subDays($days - 1)->startOfDay();

        return new self(
            preset: $preset,
            currentStart: $currentStart,
            currentEnd: $currentEnd,
            previousStart: $previousStart,
            previousEnd: $previousEnd,
        );
    }

    private static function thisMonth(string $preset, CarbonImmutable $anchorDate): self
    {
        $currentStart = $anchorDate->startOfMonth()->startOfDay();
        $currentEnd = $anchorDate->endOfMonth()->endOfDay();

        $previousStart = $currentStart->subMonthNoOverflow()->startOfMonth()->startOfDay();
        $previousEnd = $previousStart->endOfMonth()->endOfDay();

        return new self(
            preset: $preset,
            currentStart: $currentStart,
            currentEnd: $currentEnd,
            previousStart: $previousStart,
            previousEnd: $previousEnd,
        );
    }
}
