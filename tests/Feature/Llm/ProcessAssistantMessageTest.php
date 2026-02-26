<?php

use App\Actions\Llm\ClassifyLlmIntentAction;
use App\Actions\Llm\ProcessAssistantMessageAction;
use App\Actions\Llm\RunLlmInferenceAction;
use App\DataTransferObjects\Llm\LlmInferenceResult;
use App\DataTransferObjects\Llm\LlmIntentClassificationResult;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('process assistant message appends user and assistant messages with recommendation snapshot', function (): void {
    $this->mock(ClassifyLlmIntentAction::class, function ($mock): void {
        $mock->shouldReceive('execute')
            ->once()
            ->with('Prioritize my tasks')
            ->andReturn(new LlmIntentClassificationResult(
                LlmIntent::PrioritizeTasks,
                LlmEntityType::Task,
                0.95
            ));
    });

    $this->mock(RunLlmInferenceAction::class, function ($mock): void {
        $mock->shouldReceive('execute')
            ->once()
            ->andReturn(new LlmInferenceResult(
                structured: [
                    'entity_type' => 'task',
                    'recommended_action' => 'Focus on overdue first.',
                    'reasoning' => 'Step 1: Overdue. Step 2: Due date.',
                    'ranked_tasks' => [
                        ['rank' => 1, 'title' => 'Task A', 'end_datetime' => null],
                    ],
                ],
                promptVersion: '1.0',
                promptTokens: 100,
                completionTokens: 50,
                usedFallback: false
            ));
    });

    $action = app(ProcessAssistantMessageAction::class);
    $assistantMessage = $action->execute($this->user, 'Prioritize my tasks', null);

    expect($assistantMessage)->toBeInstanceOf(AssistantMessage::class)
        ->and($assistantMessage->role)->toBe('assistant')
        ->and($assistantMessage->content)->toBe('Focus on overdue first.');

    $thread = $assistantMessage->assistantThread;
    $messages = $thread->messages()->orderBy('created_at')->get();

    expect($messages)->toHaveCount(2)
        ->and($messages[0]->role)->toBe('user')
        ->and($messages[0]->content)->toBe('Prioritize my tasks')
        ->and($messages[1]->role)->toBe('assistant');

    $meta = $assistantMessage->metadata;
    expect($meta)->toHaveKeys(['intent', 'entity_type', 'recommendation_snapshot'])
        ->and($meta['intent'])->toBe('prioritize_tasks')
        ->and($meta['entity_type'])->toBe('task')
        ->and($meta['recommendation_snapshot'])->toHaveKeys(['validation_confidence', 'used_fallback', 'reasoning']);
});

test('process assistant message uses existing thread when thread id provided', function (): void {
    $thread = AssistantThread::factory()->for($this->user)->create();

    $this->mock(ClassifyLlmIntentAction::class)->shouldReceive('execute')->andReturn(
        new LlmIntentClassificationResult(LlmIntent::GeneralQuery, LlmEntityType::Task, 0.8)
    );
    $this->mock(RunLlmInferenceAction::class)->shouldReceive('execute')->andReturn(
        new LlmInferenceResult(
            structured: [
                'entity_type' => 'task',
                'recommended_action' => 'Here is a suggestion.',
                'reasoning' => 'Brief reasoning.',
            ],
            promptVersion: '1.0',
            promptTokens: 50,
            completionTokens: 20,
            usedFallback: false
        )
    );

    $action = app(ProcessAssistantMessageAction::class);
    $assistantMessage = $action->execute($this->user, 'Help me', $thread->id);

    expect($assistantMessage->assistant_thread_id)->toBe($thread->id)
        ->and($thread->messages()->count())->toBe(2);
});
