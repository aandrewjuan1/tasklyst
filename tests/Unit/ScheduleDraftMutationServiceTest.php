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

    public function test_set_local_date_updates_task_date_and_preserves_local_time(): void
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
            'op' => 'set_local_date_ymd',
            'proposal_index' => 0,
            'local_date_ymd' => '2026-04-02',
        ]], 'UTC');

        $this->assertTrue($result['ok']);
        $this->assertSame('2026-04-02T14:00:00+00:00', $result['proposals'][0]['start_datetime']);
        $this->assertSame('2026-04-02T15:00:00+00:00', $result['proposals'][0]['end_datetime']);
    }

    public function test_set_local_time_hhmm_preserves_timezone_offset(): void
    {
        $service = new ScheduleDraftMutationService;
        $proposals = [[
            'status' => 'pending',
            'entity_type' => 'task',
            'entity_id' => 1,
            'title' => 'A',
            'start_datetime' => '2026-03-31T21:00:00+08:00',
            'end_datetime' => '2026-03-31T21:40:00+08:00',
            'duration_minutes' => 40,
            'apply_payload' => ['tool' => 'update_task', 'arguments' => []],
        ]];

        $result = $service->applyOperations($proposals, [[
            'op' => 'set_local_time_hhmm',
            'proposal_index' => 0,
            'local_time_hhmm' => '21:30',
        ]], 'Asia/Singapore');

        $this->assertTrue($result['ok']);
        $this->assertSame('2026-03-31T21:30:00+08:00', $result['proposals'][0]['start_datetime']);
        $this->assertSame('2026-03-31T22:10:00+08:00', $result['proposals'][0]['end_datetime']);
    }
}
