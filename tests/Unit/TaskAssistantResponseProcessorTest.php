<?php

namespace Tests\Unit;

use App\Services\LLM\TaskAssistant\TaskAssistantResponseProcessor;
use App\Support\LLM\TaskAssistantListingDefaults;
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
            'reasoning' => 'Matches filters.',
            'suggested_guidance' => 'I recommend picking one task to open first so you can focus without getting overwhelmed.',
        ], []);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }

    public function test_browse_validation_fails_when_suggested_guidance_is_too_short(): void
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
            'reasoning' => 'Because these rows matched.',
            'suggested_guidance' => 'Too short.',
        ], []);

        $this->assertFalse($result['valid']);
    }

    public function test_browse_validation_fails_when_reasoning_is_empty(): void
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
            'reasoning' => '',
            'suggested_guidance' => 'I suggest opening one task first to keep things manageable this week.',
        ], []);

        $this->assertFalse($result['valid']);
    }

    public function test_browse_validation_passes_for_well_formed_payload(): void
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
            'reasoning' => 'Matches filters.',
            'suggested_guidance' => 'I recommend picking one task to open first so you can focus without getting overwhelmed.',
        ], []);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }

    public function test_browse_validation_fails_when_reasoning_exceeds_max_length(): void
    {
        $processor = app(TaskAssistantResponseProcessor::class);
        $max = TaskAssistantListingDefaults::maxReasoningChars();

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
            'reasoning' => str_repeat('a', $max + 1),
            'suggested_guidance' => 'I suggest tackling the highest-priority item first to help you manage your time this week.',
        ], []);

        $this->assertFalse($result['valid']);
    }
}
