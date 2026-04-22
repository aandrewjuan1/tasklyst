<?php

namespace Tests\Unit;

use App\Services\LLM\TaskAssistant\TaskAssistantResponseProcessor;
use Tests\TestCase;

class TaskAssistantResponseProcessorTest extends TestCase
{
    public function test_prioritize_validation_fails_when_reasoning_duplicates_framing(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);
        $same = 'Start with what is due soon so you can make real progress.';

        $result = $processor->processResponse('prioritize', [
            'items' => [
                [
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'title' => 'A task',
                    'priority' => 'high',
                    'due_phrase' => 'due today',
                    'due_on' => 'Mar 22, 2026',
                    'complexity_label' => 'Simple',
                ],
            ],
            'limit_used' => 1,
            'focus' => [
                'main_task' => 'A task',
                'secondary_tasks' => [],
            ],
            'framing' => $same,
            'reasoning' => $same,
            'next_options' => 'If you want, I can schedule these steps for later.',
            'next_options_chip_texts' => [
                'Schedule these for later',
            ],
        ], []);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_prioritize_validation_passes_for_well_formed_payload(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);

        $result = $processor->processResponse('prioritize', [
            'items' => [
                [
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'title' => 'A task',
                    'priority' => 'high',
                    'due_phrase' => 'due today',
                    'due_on' => 'Mar 22, 2026',
                    'complexity_label' => 'Simple',
                ],
            ],
            'limit_used' => 1,
            'focus' => [
                'main_task' => 'A task',
                'secondary_tasks' => [],
            ],
            'framing' => 'Start with what is due soon so you can make real progress.',
            'reasoning' => 'This ordering matches what you asked for.',
            'next_options' => 'If you want, I can schedule these steps for later.',
            'next_options_chip_texts' => [
                'Schedule these for later',
            ],
        ], []);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }

    public function test_prioritize_validation_fails_when_framing_present_with_doing_progress_coach(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);

        $result = $processor->processResponse('prioritize', [
            'items' => [
                [
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'title' => 'A task',
                    'priority' => 'high',
                    'due_phrase' => 'due today',
                    'due_on' => 'Mar 22, 2026',
                    'complexity_label' => 'Simple',
                ],
            ],
            'limit_used' => 1,
            'focus' => [
                'main_task' => 'A task',
                'secondary_tasks' => [],
            ],
            'framing' => 'Start with what is due soon so you can make real progress.',
            'reasoning' => 'This ordering matches what you asked for.',
            'next_options' => 'If you want, I can schedule these steps for later.',
            'next_options_chip_texts' => [
                'Schedule these for later',
            ],
            'doing_progress_coach' => 'Some doing coach text.',
        ], []);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_prioritize_validation_fails_when_framing_missing_and_doing_progress_coach_empty(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);

        $result = $processor->processResponse('prioritize', [
            'items' => [
                [
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'title' => 'A task',
                    'priority' => 'high',
                    'due_phrase' => 'due today',
                    'due_on' => 'Mar 22, 2026',
                    'complexity_label' => 'Simple',
                ],
            ],
            'limit_used' => 1,
            'focus' => [
                'main_task' => 'A task',
                'secondary_tasks' => [],
            ],
            'framing' => '',
            'reasoning' => 'This ordering matches what you asked for.',
            'next_options' => 'If you want, I can schedule these steps for later.',
            'next_options_chip_texts' => [
                'Schedule these for later',
            ],
            'doing_progress_coach' => '',
        ], []);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_prioritize_validation_passes_with_doing_progress_coach(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);

        $result = $processor->processResponse('prioritize', [
            'items' => [
                [
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'title' => 'A task',
                    'priority' => 'high',
                    'due_phrase' => 'due today',
                    'due_on' => 'Mar 22, 2026',
                    'complexity_label' => 'Simple',
                ],
            ],
            'limit_used' => 1,
            'focus' => [
                'main_task' => 'A task',
                'secondary_tasks' => [],
            ],
            'framing' => null,
            'reasoning' => 'This ordering matches what you asked for.',
            'next_options' => 'If you want, I can schedule these steps for later.',
            'next_options_chip_texts' => [
                'Schedule these for later',
            ],
            'doing_progress_coach' => 'Finishing Other task before adding something new usually means less mental switching.',
        ], []);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
        $this->assertStringContainsString('Other task', $result['formatted_content']);
    }

    public function test_prioritize_validation_passes_with_optional_narrative_and_variant_fields(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);

        $result = $processor->processResponse('prioritize', [
            'items' => [
                [
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'title' => 'A task',
                    'priority' => 'high',
                    'due_phrase' => 'due today',
                    'due_on' => 'Mar 22, 2026',
                    'complexity_label' => 'Simple',
                ],
            ],
            'limit_used' => 1,
            'focus' => [
                'main_task' => 'A task',
                'secondary_tasks' => [],
            ],
            'framing' => 'Start with what is due soon so you can make real progress.',
            'reasoning' => 'This ordering matches what you asked for.',
            'next_options' => 'If you want, I can schedule these steps for later.',
            'next_options_chip_texts' => [
                'Schedule these for later',
            ],
            'filter_interpretation' => 'This slice follows your today filter.',
            'count_mismatch_explanation' => 'You asked for 2, and I found 1 strong match for this focus.',
            'assumptions' => ['Treating today as your local calendar date.'],
            'prioritize_variant' => 'rank',
        ], []);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }

    public function test_prioritize_validation_fails_when_count_mismatch_explanation_is_not_a_string(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);

        $result = $processor->processResponse('prioritize', [
            'items' => [
                [
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'title' => 'A task',
                    'priority' => 'high',
                    'due_phrase' => 'due today',
                    'due_on' => 'Mar 22, 2026',
                    'complexity_label' => 'Simple',
                ],
            ],
            'limit_used' => 1,
            'focus' => [
                'main_task' => 'A task',
                'secondary_tasks' => [],
            ],
            'framing' => 'Start with what is due soon so you can make real progress.',
            'reasoning' => 'This ordering matches what you asked for.',
            'next_options' => 'If you want, I can schedule these steps for later.',
            'next_options_chip_texts' => [
                'Schedule these for later',
            ],
            'count_mismatch_explanation' => ['bad type'],
        ], []);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_prioritize_validation_passes_with_empty_items_slice(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);

        $result = $processor->processResponse('prioritize', [
            'items' => [],
            'limit_used' => 0,
            'focus' => [
                'main_task' => 'Add your first task',
                'secondary_tasks' => [],
            ],
            'framing' => 'Nothing is on your list yet—add something to unlock prioritization.',
            'reasoning' => 'One concrete task is enough so I can sort urgency and suggest time blocks next.',
            'next_options' => 'Add a task, then ask what to do first or when to work on it.',
            'next_options_chip_texts' => [],
        ], []);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }

    public function test_prioritize_validation_fails_when_framing_is_too_short(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);

        $result = $processor->processResponse('prioritize', [
            'items' => [
                [
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'title' => 'A task',
                    'priority' => 'high',
                    'due_phrase' => 'due today',
                    'due_on' => 'Mar 22, 2026',
                ],
            ],
            'limit_used' => 1,
            'focus' => [
                'main_task' => 'A task',
                'secondary_tasks' => [],
            ],
            'framing' => 'No',
            'reasoning' => 'This ordering matches what you asked for.',
            'next_options' => 'If you want, I can schedule these steps for later.',
            'next_options_chip_texts' => [
                'Schedule these for later',
            ],
        ], []);

        $this->assertFalse($result['valid']);
    }

    public function test_prioritize_validation_fails_when_next_options_is_too_short(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);

        $result = $processor->processResponse('prioritize', [
            'items' => [
                [
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'title' => 'A task',
                    'priority' => 'high',
                    'due_phrase' => 'due today',
                    'due_on' => 'Mar 22, 2026',
                ],
            ],
            'limit_used' => 1,
            'focus' => [
                'main_task' => 'A task',
                'secondary_tasks' => [],
            ],
            'framing' => 'Start with what is due soon so you can make real progress.',
            'reasoning' => 'This ordering matches what you asked for.',
            'next_options' => 'Ok',
            'next_options_chip_texts' => [
                'Schedule these for later',
            ],
        ], []);

        $this->assertFalse($result['valid']);
    }

    public function test_general_guidance_validation_passes_for_well_formed_payload(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);

        $result = $processor->processResponse('general_guidance', [
            'intent' => 'task',
            'acknowledgement' => 'Thanks for reaching out.',
            'message' => 'Here is some helpful guidance for your week.',
            'suggested_next_actions' => [
                'Prioritize my tasks.',
                'Schedule time blocks for my tasks.',
            ],
            'next_options' => 'If you want, I can help you prioritize what to tackle first or block time on your calendar.',
            'next_options_chip_texts' => [
                'What should I do first',
                'Schedule my most important task',
            ],
        ], []);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }

    public function test_general_guidance_validation_fails_when_next_options_missing_schedule_theme(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);

        $result = $processor->processResponse('general_guidance', [
            'intent' => 'task',
            'acknowledgement' => 'Thanks for reaching out.',
            'message' => 'Here is some helpful guidance for your week.',
            'suggested_next_actions' => [
                'Prioritize my tasks.',
                'Schedule time blocks for my tasks.',
            ],
            'next_options' => 'If you want, I can help you decide what to tackle first.',
            'next_options_chip_texts' => [
                'What should I do first',
                'Schedule my most important task',
            ],
        ], []);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_general_guidance_validation_accepts_scheduling_word_in_next_options(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);

        $result = $processor->processResponse('general_guidance', [
            'intent' => 'task',
            'acknowledgement' => 'Thanks for checking in.',
            'message' => 'I can help you narrow this down quickly.',
            'suggested_next_actions' => [
                'Prioritize my tasks.',
                'Schedule time blocks for my tasks.',
            ],
            'next_options' => 'If you want, I can help with prioritizing the list first, then scheduling focused study times.',
            'next_options_chip_texts' => [
                'What should I do first',
                'Schedule my most important task',
            ],
        ], []);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }

    public function test_daily_schedule_validation_fails_when_reasoning_duplicates_framing(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);
        $same = 'Start with what fits your calendar best.';

        $result = $processor->processResponse('daily_schedule', [
            'proposals' => [[
                'proposal_id' => 'p1',
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => 1,
                'title' => 'Task A',
                'start_datetime' => '2026-03-29T09:00:00+00:00',
                'end_datetime' => '2026-03-29T09:30:00+00:00',
                'duration_minutes' => 30,
                'apply_payload' => [
                    'action' => 'update_task',
                    'arguments' => ['taskId' => 1, 'updates' => []],
                ],
            ]],
            'items' => [[
                'title' => 'Task A',
                'entity_type' => 'task',
                'entity_id' => 1,
                'start_datetime' => '2026-03-29T09:00:00+00:00',
                'end_datetime' => '2026-03-29T09:30:00+00:00',
                'duration_minutes' => 30,
            ]],
            'blocks' => [[
                'start_time' => '09:00',
                'end_time' => '09:30',
                'label' => 'Task A',
                'task_id' => 1,
                'event_id' => null,
                'note' => null,
            ]],
            'schedule_variant' => 'daily',
            'framing' => $same,
            'reasoning' => $same,
            'confirmation' => 'Does this slot work, or should we pick another time?',
        ], [
            'tasks' => [['id' => 1]],
            'events' => [],
            'projects' => [],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_daily_schedule_validation_passes_for_well_formed_payload(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);

        $result = $processor->processResponse('daily_schedule', [
            'proposals' => [[
                'proposal_id' => 'p1',
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => 1,
                'title' => 'Task A',
                'start_datetime' => '2026-03-29T09:00:00+00:00',
                'end_datetime' => '2026-03-29T09:30:00+00:00',
                'duration_minutes' => 30,
                'apply_payload' => [
                    'action' => 'update_task',
                    'arguments' => ['taskId' => 1, 'updates' => []],
                ],
            ]],
            'items' => [[
                'title' => 'Task A',
                'entity_type' => 'task',
                'entity_id' => 1,
                'start_datetime' => '2026-03-29T09:00:00+00:00',
                'end_datetime' => '2026-03-29T09:30:00+00:00',
                'duration_minutes' => 30,
            ]],
            'blocks' => [[
                'start_time' => '09:00',
                'end_time' => '09:30',
                'label' => 'Task A',
                'task_id' => 1,
                'event_id' => null,
                'note' => null,
            ]],
            'schedule_variant' => 'daily',
            'framing' => 'Here is a focused plan for this slice.',
            'reasoning' => 'During your window, you can stay focused on one item at a time.',
            'confirmation' => 'If you want, I can help you prioritize what is left or adjust this block.',
        ], [
            'tasks' => [['id' => 1]],
            'events' => [],
            'projects' => [],
        ]);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }

    public function test_daily_schedule_process_response_applies_soft_narrative_corrections_to_structured_data(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);

        $result = $processor->processResponse('daily_schedule', [
            'proposals' => [
                [
                    'proposal_id' => 'p1',
                    'status' => 'pending',
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'title' => 'Task A',
                    'start_datetime' => '2026-03-29T09:00:00+00:00',
                    'end_datetime' => '2026-03-29T09:30:00+00:00',
                    'duration_minutes' => 30,
                    'apply_payload' => [
                        'action' => 'update_task',
                        'arguments' => ['taskId' => 1, 'updates' => []],
                    ],
                ],
                [
                    'proposal_id' => 'p2',
                    'status' => 'pending',
                    'entity_type' => 'task',
                    'entity_id' => 2,
                    'title' => 'Task B',
                    'start_datetime' => '2026-03-30T15:00:00+00:00',
                    'end_datetime' => '2026-03-30T15:30:00+00:00',
                    'duration_minutes' => 30,
                    'apply_payload' => [
                        'action' => 'update_task',
                        'arguments' => ['taskId' => 2, 'updates' => []],
                    ],
                ],
            ],
            'items' => [
                [
                    'title' => 'Task A',
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'start_datetime' => '2026-03-29T09:00:00+00:00',
                    'end_datetime' => '2026-03-29T09:30:00+00:00',
                    'duration_minutes' => 30,
                ],
                [
                    'title' => 'Task B',
                    'entity_type' => 'task',
                    'entity_id' => 2,
                    'start_datetime' => '2026-03-30T15:00:00+00:00',
                    'end_datetime' => '2026-03-30T15:30:00+00:00',
                    'duration_minutes' => 30,
                ],
            ],
            'blocks' => [
                [
                    'start_time' => '09:00',
                    'end_time' => '09:30',
                    'label' => 'Task A',
                    'task_id' => 1,
                    'event_id' => null,
                    'note' => null,
                ],
                [
                    'start_time' => '15:00',
                    'end_time' => '15:30',
                    'label' => 'Task B',
                    'task_id' => 2,
                    'event_id' => null,
                    'note' => null,
                ],
            ],
            'schedule_variant' => 'range',
            'framing' => 'Here is your schedule today.',
            'reasoning' => 'Today is a good time for this.',
            'confirmation' => 'Do these evening blocks work?',
        ], [
            'tasks' => [['id' => 1], ['id' => 2]],
            'events' => [],
            'projects' => [],
        ]);

        $this->assertTrue($result['valid']);
        $this->assertStringNotContainsString('today', mb_strtolower((string) $result['structured_data']['framing']));
        $this->assertStringNotContainsString('evening', mb_strtolower((string) $result['structured_data']['confirmation']));
    }

    public function test_daily_schedule_validation_allows_empty_schedule_when_nothing_can_be_placed(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);

        $result = $processor->processResponse('daily_schedule', [
            'proposals' => [],
            'items' => [],
            'blocks' => [],
            'schedule_variant' => 'range',
            'schedule_empty_placement' => true,
            'placement_digest' => [
                'placement_dates' => ['2026-04-02', '2026-04-03'],
                'days_used' => [],
                'unplaced_units' => [[
                    'entity_type' => 'task',
                    'entity_id' => 31,
                    'title' => 'Impossible 5h study block before quiz',
                    'minutes' => 240,
                    'reason' => 'horizon_exhausted',
                ]],
            ],
            'blocking_reasons' => [[
                'title' => 'Impossible 5h study block before quiz',
                'blocked_window' => '2026-04-02 to 2026-04-03',
                'reason' => 'No free slot was available inside the requested schedule window.',
            ]],
            'framing' => 'Nothing in this slice could be placed cleanly in open time.',
            'reasoning' => 'Getting one concrete item on your list is enough to start.',
            'confirmation' => 'Want to widen the window or try a different time?',
        ], [
            'tasks' => [['id' => 31]],
            'events' => [],
            'projects' => [],
        ]);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
        $this->assertStringNotContainsString('No schedulable items found —', $result['formatted_content']);
    }

    public function test_daily_schedule_validation_fails_when_project_id_not_in_snapshot(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);

        $result = $processor->processResponse('daily_schedule', [
            'proposals' => [[
                'proposal_id' => 'p1',
                'status' => 'pending',
                'entity_type' => 'project',
                'entity_id' => 99,
                'title' => 'Project X',
                'start_datetime' => '2026-03-29T09:00:00+00:00',
                'end_datetime' => '2026-03-29T09:30:00+00:00',
                'duration_minutes' => 30,
                'apply_payload' => [
                    'action' => 'update_project',
                    'arguments' => ['projectId' => 99, 'updates' => []],
                ],
            ]],
            'items' => [[
                'title' => 'Project X',
                'entity_type' => 'project',
                'entity_id' => 99,
                'start_datetime' => '2026-03-29T09:00:00+00:00',
                'end_datetime' => '2026-03-29T09:30:00+00:00',
                'duration_minutes' => 30,
            ]],
            'blocks' => [[
                'start_time' => '09:00',
                'end_time' => '09:30',
                'label' => 'Project X',
                'task_id' => null,
                'event_id' => null,
                'note' => null,
            ]],
            'schedule_variant' => 'daily',
            'framing' => 'Here is a focused plan.',
            'reasoning' => 'Project work gets a dedicated block.',
            'confirmation' => 'If you want, I can help you prioritize tasks or schedule another window.',
        ], [
            'tasks' => [],
            'events' => [],
            'projects' => [['id' => 1]],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_daily_schedule_validation_fails_when_proposal_ids_are_duplicated(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);

        $result = $processor->processResponse('daily_schedule', [
            'proposals' => [
                [
                    'proposal_id' => 'dup-1',
                    'status' => 'pending',
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'title' => 'Task A',
                    'start_datetime' => '2026-03-29T09:00:00+00:00',
                    'end_datetime' => '2026-03-29T09:30:00+00:00',
                    'duration_minutes' => 30,
                    'apply_payload' => ['action' => 'update_task', 'arguments' => ['taskId' => 1, 'updates' => []]],
                ],
                [
                    'proposal_id' => 'dup-1',
                    'status' => 'pending',
                    'entity_type' => 'task',
                    'entity_id' => 2,
                    'title' => 'Task B',
                    'start_datetime' => '2026-03-29T10:00:00+00:00',
                    'end_datetime' => '2026-03-29T10:30:00+00:00',
                    'duration_minutes' => 30,
                    'apply_payload' => ['action' => 'update_task', 'arguments' => ['taskId' => 2, 'updates' => []]],
                ],
            ],
            'items' => [
                [
                    'title' => 'Task A',
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'start_datetime' => '2026-03-29T09:00:00+00:00',
                    'end_datetime' => '2026-03-29T09:30:00+00:00',
                    'duration_minutes' => 30,
                ],
                [
                    'title' => 'Task B',
                    'entity_type' => 'task',
                    'entity_id' => 2,
                    'start_datetime' => '2026-03-29T10:00:00+00:00',
                    'end_datetime' => '2026-03-29T10:30:00+00:00',
                    'duration_minutes' => 30,
                ],
            ],
            'blocks' => [
                ['start_time' => '09:00', 'end_time' => '09:30', 'label' => 'Task A', 'task_id' => 1, 'event_id' => null, 'note' => null],
                ['start_time' => '10:00', 'end_time' => '10:30', 'label' => 'Task B', 'task_id' => 2, 'event_id' => null, 'note' => null],
            ],
            'schedule_variant' => 'daily',
            'schedule_empty_placement' => false,
            'framing' => 'Here is a focused plan.',
            'reasoning' => 'This keeps your schedule realistic.',
            'confirmation' => 'Do these times work?',
        ], [
            'tasks' => [['id' => 1], ['id' => 2]],
            'events' => [],
            'projects' => [],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_daily_schedule_validation_fails_when_empty_flag_conflicts_with_schedulable_proposals(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);

        $result = $processor->processResponse('daily_schedule', [
            'proposals' => [[
                'proposal_id' => 'p1',
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => 1,
                'title' => 'Task A',
                'start_datetime' => '2026-03-29T09:00:00+00:00',
                'end_datetime' => '2026-03-29T09:30:00+00:00',
                'duration_minutes' => 30,
                'apply_payload' => [
                    'action' => 'update_task',
                    'arguments' => ['taskId' => 1, 'updates' => []],
                ],
            ]],
            'items' => [[
                'title' => 'Task A',
                'entity_type' => 'task',
                'entity_id' => 1,
                'start_datetime' => '2026-03-29T09:00:00+00:00',
                'end_datetime' => '2026-03-29T09:30:00+00:00',
                'duration_minutes' => 30,
            ]],
            'blocks' => [[
                'start_time' => '09:00',
                'end_time' => '09:30',
                'label' => 'Task A',
                'task_id' => 1,
                'event_id' => null,
                'note' => null,
            ]],
            'schedule_variant' => 'daily',
            'schedule_empty_placement' => true,
            'framing' => 'Here is a focused plan.',
            'reasoning' => 'Task A is ready to place.',
            'confirmation' => 'Do these times work?',
        ], [
            'tasks' => [['id' => 1]],
            'events' => [],
            'projects' => [],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_daily_schedule_confirmation_validation_fails_when_system_default_claims_explicit_top_n(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);

        $result = $processor->processResponse('daily_schedule', [
            'proposals' => [[
                'proposal_id' => 'p1',
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => 1,
                'title' => 'Task A',
                'start_datetime' => '2026-03-29T09:00:00+00:00',
                'end_datetime' => '2026-03-29T09:30:00+00:00',
                'duration_minutes' => 30,
                'apply_payload' => [
                    'action' => 'update_task',
                    'arguments' => ['taskId' => 1, 'updates' => []],
                ],
            ]],
            'items' => [[
                'title' => 'Task A',
                'entity_type' => 'task',
                'entity_id' => 1,
                'start_datetime' => '2026-03-29T09:00:00+00:00',
                'end_datetime' => '2026-03-29T09:30:00+00:00',
                'duration_minutes' => 30,
            ]],
            'blocks' => [[
                'start_time' => '09:00',
                'end_time' => '09:30',
                'label' => 'Task A',
                'task_id' => 1,
                'event_id' => null,
                'note' => null,
            ]],
            'schedule_variant' => 'daily',
            'confirmation_required' => true,
            'awaiting_user_decision' => true,
            'confirmation_context' => [
                'reason_code' => 'top_n_shortfall',
                'requested_count' => 3,
                'placed_count' => 1,
                'requested_count_source' => 'system_default',
                'reason_message' => 'You asked for top 3, but only 1 fit.',
                'prompt' => 'Keep this draft or adjust your window?',
                'options' => [
                    'Keep this current draft',
                    'Pick another time window',
                    'Cancel scheduling for now',
                ],
            ],
            'framing' => 'I preserved your top 3 request and prepared a draft.',
            'reasoning' => 'You asked for top 3 and only one fit.',
            'confirmation' => 'Keep this draft or adjust your window?',
        ], [
            'tasks' => [['id' => 1]],
            'events' => [],
            'projects' => [],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_daily_schedule_confirmation_validation_fails_when_reason_code_options_missing_required_choice(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);

        $result = $processor->processResponse('daily_schedule', [
            'proposals' => [[
                'proposal_id' => 'p1',
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => 1,
                'title' => 'Task A',
                'start_datetime' => '2026-03-29T09:00:00+00:00',
                'end_datetime' => '2026-03-29T09:30:00+00:00',
                'duration_minutes' => 30,
                'apply_payload' => [
                    'action' => 'update_task',
                    'arguments' => ['taskId' => 1, 'updates' => []],
                ],
            ]],
            'items' => [[
                'title' => 'Task A',
                'entity_type' => 'task',
                'entity_id' => 1,
                'start_datetime' => '2026-03-29T09:00:00+00:00',
                'end_datetime' => '2026-03-29T09:30:00+00:00',
                'duration_minutes' => 30,
            ]],
            'blocks' => [[
                'start_time' => '09:00',
                'end_time' => '09:30',
                'label' => 'Task A',
                'task_id' => 1,
                'event_id' => null,
                'note' => null,
            ]],
            'schedule_variant' => 'daily',
            'confirmation_required' => true,
            'awaiting_user_decision' => true,
            'confirmation_context' => [
                'reason_code' => 'explicit_day_not_feasible',
                'requested_count' => 3,
                'placed_count' => 1,
                'requested_count_source' => 'explicit_user',
                'reason_message' => 'I could not keep everything on Apr 20, 2026.',
                'prompt' => 'Keep Apr 20 only, or widen to nearby days?',
                'options' => [
                    'Keep Apr 20 only',
                    'Cancel scheduling for now',
                ],
            ],
            'framing' => 'I paused to confirm before widening beyond Apr 20.',
            'reasoning' => 'Nothing is final until you choose.',
            'confirmation' => 'Keep Apr 20 only, or widen to nearby days?',
        ], [
            'tasks' => [['id' => 1]],
            'events' => [],
            'projects' => [],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_daily_schedule_confirmation_validation_fails_when_option_actions_do_not_match_labels(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);

        $result = $processor->processResponse('daily_schedule', [
            'proposals' => [[
                'proposal_id' => 'p1',
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => 1,
                'title' => 'Task A',
                'start_datetime' => '2026-03-29T09:00:00+00:00',
            ]],
            'items' => [[
                'title' => 'Task A',
                'entity_type' => 'task',
                'entity_id' => 1,
                'start_datetime' => '2026-03-29T09:00:00+00:00',
            ]],
            'blocks' => [[
                'start_time' => '09:00',
                'end_time' => '09:30',
            ]],
            'schedule_variant' => 'daily',
            'confirmation_required' => true,
            'awaiting_user_decision' => true,
            'confirmation_context' => [
                'reason_code' => 'top_n_shortfall',
                'requested_count' => 3,
                'placed_count' => 1,
                'requested_count_source' => 'explicit_user',
                'reason_message' => 'Only one task fit in this window.',
                'prompt' => 'Keep this draft or adjust your window?',
                'options' => [
                    'Keep this current draft',
                    'Pick another time window',
                    'Cancel scheduling for now',
                ],
                'option_actions' => [
                    ['id' => 'pick_another_time_window', 'label' => 'Use this draft'],
                    ['id' => 'pick_another_time_window', 'label' => 'Pick another time window'],
                    ['id' => 'cancel_scheduling', 'label' => 'Cancel scheduling for now'],
                ],
            ],
            'framing' => 'I paused before finalizing this draft.',
            'reasoning' => 'I need your decision before applying anything.',
            'confirmation' => 'Keep this draft or adjust your window?',
        ], [
            'tasks' => [['id' => 1]],
            'events' => [],
            'projects' => [],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_listing_followup_validation_passes_for_well_formed_payload(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);

        $result = $processor->processResponse('listing_followup', [
            'verdict' => 'partial',
            'compared_items' => [
                ['entity_type' => 'task', 'entity_id' => 1, 'title' => 'Task A'],
            ],
            'more_urgent_alternatives' => [
                ['entity_type' => 'task', 'entity_id' => 2, 'title' => 'Task B', 'reason_short' => 'Ranked ahead in this snapshot.'],
            ],
            'framing' => 'Here is a direct read on what you asked about those items.',
            'rationale' => 'I compared them to the same ordering your assistant uses when it surfaces top work.',
            'caveats' => 'Tasks and events are weighted a little differently.',
            'next_options' => 'Say if you want a new schedule sketch or a refreshed ranked slice.',
            'next_options_chip_texts' => [
                'Show my top tasks',
                'Plan my day tomorrow',
            ],
        ], []);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }

    public function test_listing_followup_validation_passes_with_empty_alternatives_and_null_caveats(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);

        $result = $processor->processResponse('listing_followup', [
            'verdict' => 'yes',
            'compared_items' => [
                ['entity_type' => 'task', 'entity_id' => 1, 'title' => 'Task A'],
            ],
            'more_urgent_alternatives' => [],
            'framing' => 'For this snapshot, those items line up with the top urgency band.',
            'rationale' => 'Their order matches how things are ranked for prioritize and schedule flows.',
            'caveats' => null,
            'next_options' => 'Tell me if you want to tweak times or see a different slice.',
            'next_options_chip_texts' => [
                'Adjust the plan',
                'Prioritize again',
            ],
        ], []);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }

    public function test_daily_schedule_validation_requires_blocking_reasons_when_unplaced_units_exist(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);

        $result = $processor->processResponse('daily_schedule', [
            'proposals' => [],
            'items' => [],
            'blocks' => [],
            'schedule_variant' => 'daily',
            'schedule_empty_placement' => true,
            'placement_digest' => [
                'unplaced_units' => [[
                    'entity_type' => 'task',
                    'entity_id' => 31,
                    'title' => 'Physics review',
                    'minutes' => 90,
                    'reason' => 'horizon_exhausted',
                ]],
            ],
            'window_selection_explanation' => 'No available slot remained in the requested window.',
            'ordering_rationale' => [],
            'blocking_reasons' => [],
            'fallback_choice_explanation' => null,
            'framing' => 'I could not place this cleanly right now.',
            'reasoning' => 'Your requested window is currently full.',
            'confirmation' => 'Want me to try another time window?',
        ], [
            'tasks' => [['id' => 31]],
            'events' => [],
            'projects' => [],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_prioritize_quality_normalization_rewrites_reasoning_when_it_duplicates_ordering_rationale(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);

        $duplicateReasoning = '#1 A task: This task stands out because it is high priority and due today.';

        $result = $processor->processResponse('prioritize', [
            'items' => [[
                'entity_type' => 'task',
                'entity_id' => 1,
                'title' => 'A task',
                'priority' => 'high',
                'due_phrase' => 'due today',
                'due_on' => 'Mar 22, 2026',
                'complexity_label' => 'Simple',
                'rank_reason' => 'This task stands out because it is high priority and due today.',
            ]],
            'limit_used' => 1,
            'focus' => [
                'main_task' => 'A task',
                'secondary_tasks' => [],
            ],
            'framing' => 'Here is your top task.',
            'ordering_rationale' => [$duplicateReasoning],
            'reasoning' => $duplicateReasoning,
            'next_options' => 'If you want, I can schedule this for later today.',
            'next_options_chip_texts' => [
                'Schedule this for later',
            ],
        ], []);

        $this->assertTrue($result['valid']);
        $this->assertNotSame($duplicateReasoning, $result['structured_data']['reasoning']);
    }

    public function test_daily_schedule_quality_normalization_rewrites_mixed_daypart_claims_in_narrative(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);

        $result = $processor->processResponse('daily_schedule', [
            'proposals' => [[
                'proposal_id' => 'p1',
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => 1,
                'title' => 'Task A',
                'start_datetime' => '2026-03-29T09:00:00+00:00',
                'end_datetime' => '2026-03-29T09:30:00+00:00',
                'duration_minutes' => 30,
                'apply_payload' => [
                    'action' => 'update_task',
                    'arguments' => ['taskId' => 1, 'updates' => []],
                ],
            ]],
            'items' => [[
                'title' => 'Task A',
                'entity_type' => 'task',
                'entity_id' => 1,
                'start_datetime' => '2026-03-29T09:00:00+00:00',
                'end_datetime' => '2026-03-29T09:30:00+00:00',
                'duration_minutes' => 30,
            ]],
            'blocks' => [[
                'start_time' => '09:00',
                'end_time' => '09:30',
                'label' => 'Task A',
                'task_id' => 1,
                'event_id' => null,
                'note' => null,
            ]],
            'schedule_variant' => 'daily',
            'framing' => 'I planned a morning and evening mix for this one block.',
            'reasoning' => 'Morning and evening anchors both work here.',
            'confirmation' => 'Do these times work, or should we adjust?',
        ], [
            'tasks' => [['id' => 1]],
            'events' => [],
            'projects' => [],
        ]);

        $this->assertTrue($result['valid']);
        $this->assertStringNotContainsString('morning and evening', mb_strtolower((string) $result['structured_data']['framing']));
    }

    public function test_daily_schedule_validation_accepts_structured_explainability_contract_fields(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);

        $result = $processor->processResponse('daily_schedule', [
            'proposals' => [[
                'proposal_id' => 'p1',
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => 1,
                'title' => 'Task A',
                'reason_score' => 1200,
                'reason_code_primary' => 'fit_window',
                'reason_codes_secondary' => ['time_bound'],
                'explainability_facts' => [['key' => 'slot', 'value' => 'evening']],
                'narrative_anchor' => ['title' => 'Task A'],
                'start_datetime' => '2026-03-29T09:00:00+00:00',
                'end_datetime' => '2026-03-29T09:30:00+00:00',
                'duration_minutes' => 30,
                'apply_payload' => ['action' => 'update_task', 'arguments' => ['taskId' => 1, 'updates' => []]],
            ]],
            'items' => [[
                'title' => 'Task A',
                'entity_type' => 'task',
                'entity_id' => 1,
                'start_datetime' => '2026-03-29T09:00:00+00:00',
                'end_datetime' => '2026-03-29T09:30:00+00:00',
                'duration_minutes' => 30,
            ]],
            'blocks' => [[
                'start_time' => '09:00',
                'end_time' => '09:30',
                'label' => 'Task A',
                'task_id' => 1,
                'event_id' => null,
                'note' => null,
            ]],
            'schedule_variant' => 'daily',
            'framing' => 'Here is a focused plan.',
            'reasoning' => 'This keeps your workload realistic.',
            'confirmation' => 'Do these times work for you?',
            'window_selection_explanation' => 'I used your requested evening window.',
            'window_selection_struct' => ['window_mode' => 'requested_window', 'reason_code_primary' => 'window_matched_request'],
            'ordering_rationale' => ['#1 Task A: placed at 9:00 AM as one of the strongest fit windows.'],
            'ordering_rationale_struct' => [[
                'rank' => 1,
                'title' => 'Task A',
                'slot_start' => '2026-03-29T09:00:00+00:00',
                'fit_reason_code' => 'strongest_fit_window',
                'fit_facts' => [['key' => 'slot_label', 'value' => '9:00 AM']],
            ]],
            'blocking_reasons' => [],
            'blocking_reasons_struct' => [],
        ], [
            'tasks' => [['id' => 1]],
            'events' => [],
            'projects' => [],
        ]);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }
}
