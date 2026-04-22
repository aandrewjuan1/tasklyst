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
            'apply_payload' => ['action' => 'update_task', 'arguments' => []],
        ]];

        $result = $service->applyOperations($proposals, [[
            'op' => 'shift_minutes',
            'proposal_index' => 0,
            'delta_minutes' => 30,
        ]], 'UTC');

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['applied_ops_count']);
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
                'apply_payload' => ['action' => 'update_task', 'arguments' => []],
            ],
            [
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => 2,
                'title' => 'B',
                'start_datetime' => '2026-03-30T14:30:00+00:00',
                'end_datetime' => '2026-03-30T15:30:00+00:00',
                'duration_minutes' => 60,
                'apply_payload' => ['action' => 'update_task', 'arguments' => []],
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
            'apply_payload' => ['action' => 'update_task', 'arguments' => []],
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
            'apply_payload' => ['action' => 'update_task', 'arguments' => []],
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

    public function test_move_to_position_reorders_proposals(): void
    {
        $service = new ScheduleDraftMutationService;
        $proposals = [
            [
                'proposal_id' => 'a',
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => 1,
                'title' => 'A',
                'start_datetime' => '2026-03-30T14:00:00+00:00',
                'end_datetime' => '2026-03-30T15:00:00+00:00',
                'duration_minutes' => 60,
            ],
            [
                'proposal_id' => 'b',
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => 2,
                'title' => 'B',
                'start_datetime' => '2026-03-30T15:00:00+00:00',
                'end_datetime' => '2026-03-30T16:00:00+00:00',
                'duration_minutes' => 60,
            ],
        ];

        $result = $service->applyOperations($proposals, [[
            'op' => 'move_to_position',
            'proposal_index' => 1,
            'target_index' => 0,
        ]], 'UTC');

        $this->assertTrue($result['ok']);
        $this->assertSame('b', $result['proposals'][0]['proposal_id']);
        $this->assertSame(1, $result['applied_ops_count']);
        $this->assertNotEmpty($result['changed_proposal_ids']);
    }

    public function test_shift_operation_can_target_proposal_uuid_after_reorder(): void
    {
        $service = new ScheduleDraftMutationService;
        $proposals = [
            [
                'proposal_id' => 'a',
                'proposal_uuid' => 'uuid-a',
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => 1,
                'title' => 'A',
                'start_datetime' => '2026-03-30T14:00:00+00:00',
                'end_datetime' => '2026-03-30T15:00:00+00:00',
                'duration_minutes' => 60,
            ],
            [
                'proposal_id' => 'b',
                'proposal_uuid' => 'uuid-b',
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => 2,
                'title' => 'B',
                'start_datetime' => '2026-03-30T15:30:00+00:00',
                'end_datetime' => '2026-03-30T16:00:00+00:00',
                'duration_minutes' => 30,
            ],
        ];

        $result = $service->applyOperations($proposals, [
            ['op' => 'move_to_position', 'proposal_uuid' => 'uuid-b', 'target_index' => 0],
            ['op' => 'shift_minutes', 'proposal_uuid' => 'uuid-a', 'delta_minutes' => 15],
        ], 'UTC');

        $this->assertTrue($result['ok']);
        $this->assertSame('uuid-b', $result['proposals'][0]['proposal_uuid']);
        $this->assertSame('2026-03-30T14:15:00+00:00', $result['proposals'][1]['start_datetime']);
    }

    public function test_sequential_apply_mimics_multi_edit_per_row(): void
    {
        $service = new ScheduleDraftMutationService;
        $proposals = [
            [
                'proposal_uuid' => 'uuid-a',
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => 1,
                'title' => 'A',
                'start_datetime' => '2026-04-03T13:00:00+00:00',
                'end_datetime' => '2026-04-03T14:00:00+00:00',
                'duration_minutes' => 60,
                'apply_payload' => ['action' => 'update_task', 'arguments' => []],
            ],
            [
                'proposal_uuid' => 'uuid-b',
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => 2,
                'title' => 'B',
                'start_datetime' => '2026-04-03T17:30:00+00:00',
                'end_datetime' => '2026-04-03T18:00:00+00:00',
                'duration_minutes' => 30,
                'apply_payload' => ['action' => 'update_task', 'arguments' => []],
            ],
        ];

        $first = $service->applyOperations($proposals, [
            ['op' => 'set_local_time_hhmm', 'proposal_index' => 1, 'proposal_uuid' => 'uuid-b', 'local_time_hhmm' => '20:00'],
        ], 'UTC');
        $this->assertTrue($first['ok']);

        $second = $service->applyOperations($first['proposals'], [
            ['op' => 'set_local_time_hhmm', 'proposal_index' => 0, 'proposal_uuid' => 'uuid-a', 'local_time_hhmm' => '08:00'],
        ], 'UTC');
        $this->assertTrue($second['ok']);
        $this->assertStringContainsString('T08:00:00', (string) $second['proposals'][0]['start_datetime']);
        $this->assertStringContainsString('T20:00:00', (string) $second['proposals'][1]['start_datetime']);
    }
}
