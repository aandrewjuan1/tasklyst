<?php

use App\Actions\Llm\ClassifyLlmIntentAction;
use App\DataTransferObjects\Llm\LlmIntentClassificationResult;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Enums\LlmOperationMode;
use App\Services\Llm\LlmIntentAliasResolver;
use App\Services\LlmIntentClassificationService;

class TestableClassifyLlmIntentAction extends ClassifyLlmIntentAction
{
    public ?LlmIntentClassificationResult $fallbackResult = null;

    protected function performLlmClassification(string $userMessage, ?\App\Models\AssistantThread $thread = null): ?LlmIntentClassificationResult
    {
        return $this->fallbackResult;
    }
}

it('uses LLM fallback classification when confidence below threshold', function (): void {
    config([
        'tasklyst.intent.confidence_threshold' => 0.9,
        'tasklyst.intent.use_llm_fallback' => true,
    ]);

    $service = \Mockery::mock(LlmIntentClassificationService::class);
    $regexResult = new LlmIntentClassificationResult(
        LlmIntent::GeneralQuery,
        LlmEntityType::Task,
        0.3,
        LlmOperationMode::General,
        [LlmEntityType::Task],
    );

    $service->shouldReceive('classify')
        ->once()
        ->with('message')
        ->andReturn($regexResult);

    $fallbackResult = new LlmIntentClassificationResult(
        LlmIntent::PrioritizeTasks,
        LlmEntityType::Task,
        0.85,
        LlmOperationMode::Prioritize,
        [LlmEntityType::Task],
    );

    $action = new TestableClassifyLlmIntentAction($service, app(LlmIntentAliasResolver::class));
    $action->fallbackResult = $fallbackResult;

    $result = $action->execute('message');

    expect($result->intent)->toBe(LlmIntent::PrioritizeTasks)
        ->and($result->entityType)->toBe(LlmEntityType::Task)
        ->and($result->confidence)->toBe(0.85);
});

it('falls back to regex result when LLM fallback returns null', function (): void {
    config([
        'tasklyst.intent.confidence_threshold' => 0.9,
        'tasklyst.intent.use_llm_fallback' => true,
    ]);

    $service = \Mockery::mock(LlmIntentClassificationService::class);
    $regexResult = new LlmIntentClassificationResult(
        LlmIntent::GeneralQuery,
        LlmEntityType::Task,
        0.3,
        LlmOperationMode::General,
        [LlmEntityType::Task],
    );

    $service->shouldReceive('classify')
        ->once()
        ->with('another message')
        ->andReturn($regexResult);

    $action = new TestableClassifyLlmIntentAction($service, app(LlmIntentAliasResolver::class));
    $action->fallbackResult = null;

    $result = $action->execute('another message');

    expect($result->intent)->toBe(LlmIntent::GeneralQuery)
        ->and($result->entityType)->toBe(LlmEntityType::Task)
        ->and($result->confidence)->toBe(0.3);
});
