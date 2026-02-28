<?php

use App\Actions\Llm\RunLlmInferenceAction;
use App\DataTransferObjects\Llm\LlmSystemPromptResult;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Models\User;
use App\Services\Llm\LlmHealthCheck;
use App\Services\LlmInferenceService;
use Illuminate\Support\Facades\Http;
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

test('inference accepts LLM response with leading spaces in JSON keys and trims them', function (): void {
    $contentWithSpacedKeys = '{" entity_type":"task"," recommended_action":"Focus on Write chapter 1."," reasoning":"Soonest deadline."," ranked_tasks":[{" rank":1," title":"Write chapter 1"},{" rank":2," title":"Get contractor quotes"," end_datetime":"2026-03-16T23:59:56+08:00"}]}';
    $ollamaUrl = rtrim((string) config('prism.providers.ollama.url', 'http://127.0.0.1:11434'), '/');

    Http::fake([
        $ollamaUrl.'/api/chat' => Http::response([
            'message' => ['content' => $contentWithSpacedKeys],
            'prompt_eval_count' => 10,
            'eval_count' => 20,
        ], 200),
    ]);

    $promptResult = new LlmSystemPromptResult(systemPrompt: 'You are a helpful assistant.', version: 'v1.1');
    $service = app(LlmInferenceService::class);
    $result = $service->infer(
        'You are a helpful assistant.',
        'Prioritize my tasks.',
        LlmIntent::PrioritizeTasks,
        $promptResult,
        $this->user
    );

    expect($result->usedFallback)->toBeFalse()
        ->and($result->structured)->toHaveKeys(['entity_type', 'recommended_action', 'reasoning', 'ranked_tasks'])
        ->and($result->structured['entity_type'])->toBe('task')
        ->and($result->structured['ranked_tasks'])->toHaveCount(2)
        ->and($result->structured['ranked_tasks'][0])->toHaveKeys(['rank', 'title'])
        ->and($result->structured['ranked_tasks'][0]['title'])->toBe('Write chapter 1');
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
