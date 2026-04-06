<?php

namespace App\Data\Analytics;

use Carbon\CarbonImmutable;

readonly class DashboardAnalyticsOverview
{
    /**
     * @param  array<string, array{current: int|float, previous: int|float, delta: int|float, delta_percentage: int|float|null}>  $cards
     * @param  array{labels: array<int, string>, tasks_completed: array<int, int>, focus_work_seconds: array<int, int>}  $trends
     * @param  array{
     *   status: array<int, array{key: string, label: string, value: int}>,
     *   priority: array<int, array{key: string, label: string, value: int}>,
     *   complexity: array<int, array{key: string, label: string, value: int}>,
     *   project: array<int, array{key: string, label: string, value: int}>
     * }  $breakdowns
     */
    public function __construct(
        public string $preset,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public CarbonImmutable $previousPeriodStart,
        public CarbonImmutable $previousPeriodEnd,
        public array $cards,
        public array $trends,
        public array $breakdowns,
    ) {}
}
