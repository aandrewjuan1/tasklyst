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

    public function test_prioritize_validation_fails_when_doing_titles_without_coach(): void
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
            'doing_titles' => ['In progress task'],
            'doing_progress_coach' => '',
        ], []);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_prioritize_validation_fails_when_doing_progress_coach_without_doing_titles(): void
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
            'doing_titles' => [],
            'doing_progress_coach' => 'Some coach text without titles.',
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
            'framing' => 'Start with what is due soon so you can make real progress.',
            'reasoning' => 'This ordering matches what you asked for.',
            'next_options' => 'If you want, I can schedule these steps for later.',
            'next_options_chip_texts' => [
                'Schedule these for later',
            ],
            'doing_titles' => ['Other task'],
            'doing_progress_coach' => 'Finishing what you already started before adding something new usually means less mental switching.',
        ], []);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
        $this->assertStringContainsString('In progress', $result['formatted_content']);
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
            'assumptions' => ['Treating today as your local calendar date.'],
            'prioritize_variant' => 'rank',
        ], []);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
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
            'doing_titles' => [],
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
}
