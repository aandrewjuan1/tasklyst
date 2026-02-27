<?php

use App\Actions\Llm\RunLlmInferenceAction;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Models\User;
use App\Services\Llm\LlmHealthCheck;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('run inference returns structured result when ollama is reachable and prisma returns valid response', function (): void {
    $this->mock(LlmHealthCheck::class, function ($mock): void {
        $mock->shouldReceive('isReachable')->once()->andReturn(true);
    });

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'entity_type' => 'task',
                'recommended_action' => 'Schedule for Friday 2pm',
                'reasoning' => 'Based on deadline and availability.',
                'start_datetime' => now()->next('Friday')->setTime(14, 0)->toIso8601String(),
                'end_datetime' => now()->next('Friday')->setTime(15, 0)->toIso8601String(),
                'priority' => 'high',
            ])
            ->withUsage(new Usage(100, 50)),
    ]);

    $action = app(RunLlmInferenceAction::class);
    $result = $action->execute(
        $this->user,
        'Schedule my dashboard task by Friday',
        LlmIntent::ScheduleTask,
        LlmEntityType::Task,
        null,
        null
    );

    expect($result->usedFallback)->toBeFalse()
        ->and($result->structured)->toHaveKeys(['entity_type', 'recommended_action', 'reasoning'])
        ->and($result->structured['entity_type'])->toBe('task')
        ->and($result->promptTokens)->toBe(100)
        ->and($result->completionTokens)->toBe(50)
        ->and($result->promptVersion)->not->toBeEmpty();
});

test('run inference returns fallback when ollama is not reachable', function (): void {
    $this->mock(LlmHealthCheck::class, function ($mock): void {
        $mock->shouldReceive('isReachable')->once()->andReturn(false);
    });

    $action = app(RunLlmInferenceAction::class);
    $result = $action->execute(
        $this->user,
        'What should I focus on today?',
        LlmIntent::PrioritizeTasks,
        LlmEntityType::Task,
        null,
        null
    );

    expect($result->usedFallback)->toBeTrue()
        ->and($result->structured)->toHaveKeys(['entity_type', 'recommended_action', 'reasoning'])
        ->and($result->promptTokens)->toBe(0)
        ->and($result->completionTokens)->toBe(0);
});

test('prioritize_tasks fallback includes rule-based ranked tasks when user provided', function (): void {
    $this->mock(LlmHealthCheck::class, function ($mock): void {
        $mock->shouldReceive('isReachable')->once()->andReturn(false);
    });

    $action = app(RunLlmInferenceAction::class);
    $result = $action->execute(
        $this->user,
        'What should I focus on?',
        LlmIntent::PrioritizeTasks,
        LlmEntityType::Task,
        null,
        null
    );

    expect($result->usedFallback)->toBeTrue()
        ->and($result->structured)->toHaveKey('entity_type')
        ->and($result->structured['entity_type'])->toBe('task');
});

test('inference result toArray returns expected keys', function (): void {
    $this->mock(LlmHealthCheck::class, function ($mock): void {
        $mock->shouldReceive('isReachable')->once()->andReturn(false);
    });

    $action = app(RunLlmInferenceAction::class);
    $result = $action->execute(
        $this->user,
        'Hello',
        LlmIntent::GeneralQuery,
        LlmEntityType::Task,
        null,
        null
    );

    $arr = $result->toArray();

    expect($arr)->toHaveKeys(['structured', 'prompt_version', 'prompt_tokens', 'completion_tokens', 'used_fallback']);
});
