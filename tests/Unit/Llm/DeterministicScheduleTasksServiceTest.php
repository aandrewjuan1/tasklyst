<?php

use App\Services\Llm\DeterministicScheduleTasksService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->service = app(DeterministicScheduleTasksService::class);
});

it('builds a multi-task schedule within requested window and cap', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-12 18:00:00', config('app.timezone')));

    $context = [
        'timezone' => 'Asia/Manila',
        'current_time' => '2026-03-12T18:00:00+08:00',
        'requested_window_start' => '2026-03-12T19:00:00+08:00',
        'requested_window_end' => '2026-03-12T23:00:00+08:00',
        'focused_work_cap_minutes' => 180,
        'availability' => [
            [
                'date' => '2026-03-12',
                'busy_windows' => [],
            ],
        ],
        'tasks' => [
            ['id' => 1, 'title' => 'Task A', 'duration' => 60, 'end_datetime' => '2026-03-13T23:59:00+08:00', 'priority' => 'high'],
            ['id' => 2, 'title' => 'Task B', 'duration' => 45, 'end_datetime' => '2026-03-13T20:00:00+08:00', 'priority' => 'medium'],
            ['id' => 3, 'title' => 'Task C', 'duration' => 240, 'end_datetime' => '2026-03-20T20:00:00+08:00', 'priority' => 'urgent'],
        ],
    ];

    $out = $this->service->buildStructured($context);

    expect($out)->toHaveKeys(['entity_type', 'recommended_action', 'reasoning', 'scheduled_tasks'])
        ->and($out['entity_type'])->toBe('task')
        ->and($out['scheduled_tasks'])->toBeArray()
        ->and($out['scheduled_tasks'])->not->toBeEmpty()
        ->and(count($out['scheduled_tasks']))->toBeGreaterThanOrEqual(2);

    $total = 0;
    foreach ($out['scheduled_tasks'] as $item) {
        $start = CarbonImmutable::parse($item['start_datetime'], 'Asia/Manila');
        expect($start->gte(CarbonImmutable::parse($context['requested_window_start'], 'Asia/Manila')))->toBeTrue()
            ->and($start->lte(CarbonImmutable::parse($context['requested_window_end'], 'Asia/Manila')))->toBeTrue();
        $total += (int) $item['duration'];
    }

    expect($total)->toBeLessThanOrEqual(180);
});
