<?php

namespace Tests\Unit;

use App\Services\LLM\TaskAssistant\TaskAssistantResponseProcessor;
use Tests\TestCase;

class TaskAssistantResponseProcessorTest extends TestCase
{
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
