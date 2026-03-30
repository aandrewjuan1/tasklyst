<?php

namespace Tests\Unit;

use App\Services\LLM\Scheduling\ScheduleDraftMutationService;
use Tests\TestCase;

class ScheduleDraftMutationServiceTest extends TestCase
{
    public function test_shift_minutes_updates_task_start_and_end(): void
    {
        $service = new ScheduleDraftMutationService;
        $proposals = [[
            'status' => 'pending',
            'entity_type' => 'task',
            'entity_id' => 1,
            'title' => 'A',
            'start_datetime' => '2026-03-30T14:00:00+00:00',
            'end_datetime' => '2026-03-30T15:00:00+00:00',
            'duration_minutes' => 60,
            'apply_payload' => ['tool' => 'update_task', 'arguments' => []],
        ]];

        $result = $service->applyOperations($proposals, [[
            'op' => 'shift_minutes',
            'proposal_index' => 0,
            'delta_minutes' => 30,
        ]], 'UTC');

        $this->assertTrue($result['ok']);
        $this->assertSame('2026-03-30T14:30:00+00:00', $result['proposals'][0]['start_datetime']);
        $this->assertSame('2026-03-30T15:30:00+00:00', $result['proposals'][0]['end_datetime']);
    }

    public function test_overlapping_tasks_fail_validation(): void
    {
        $service = new ScheduleDraftMutationService;
        $proposals = [
            [
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => 1,
                'title' => 'A',
                'start_datetime' => '2026-03-30T14:00:00+00:00',
                'end_datetime' => '2026-03-30T15:00:00+00:00',
                'duration_minutes' => 60,
                'apply_payload' => ['tool' => 'update_task', 'arguments' => []],
            ],
            [
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => 2,
                'title' => 'B',
                'start_datetime' => '2026-03-30T14:30:00+00:00',
                'end_datetime' => '2026-03-30T15:30:00+00:00',
                'duration_minutes' => 60,
                'apply_payload' => ['tool' => 'update_task', 'arguments' => []],
            ],
        ];

        $result = $service->applyOperations($proposals, [], 'UTC');

        $this->assertFalse($result['ok']);
    }
}
