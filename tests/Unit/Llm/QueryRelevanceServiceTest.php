<?php

use App\Services\Llm\QueryRelevanceService;

it('treats existential questions as off-topic and not relevant', function (string $query): void {
    /** @var QueryRelevanceService $service */
    $service = app(QueryRelevanceService::class);

    expect($service->isRelevant($query))->toBeFalse();
})->with([
    'what is the purpose of living',
    'WHAT EVEN IS THE PURPOSE OF LIVING',
    'what even is the point of living',
    'what even is the purpose of living',
    'what is the meaning of life',
]);

it('treats task and planning queries as relevant', function (): void {
    /** @var QueryRelevanceService $service */
    $service = app(QueryRelevanceService::class);

    expect($service->isRelevant('Schedule my dashboard task by Friday'))->toBeTrue()
        ->and($service->isRelevant('Help me plan my study session for the calculus exam next week'))->toBeTrue()
        ->and($service->isRelevant('What tasks should I focus on today?'))->toBeTrue()
        ->and($service->isRelevant('Hi'))->toBeTrue()
        ->and($service->isRelevant('Hello, I need help planning my week'))->toBeTrue()
        ->and($service->isRelevant('okay help me'))->toBeTrue()
        ->and($service->isRelevant('help me'))->toBeTrue()
        ->and($service->isRelevant('go ahead'))->toBeTrue()
        ->and($service->isRelevant('what next'))->toBeTrue()
        ->and($service->isRelevant('how can i focus'))->toBeTrue()
        ->and($service->isRelevant('i need to get organized'))->toBeTrue();
});

it('treats clearly general knowledge questions as off-topic', function (): void {
    /** @var QueryRelevanceService $service */
    $service = app(QueryRelevanceService::class);

    expect($service->isRelevant('Who is the current president of the Philippines?'))->toBeFalse()
        ->and($service->isRelevant('What is the capital of France?'))->toBeFalse();
});

it('treats short vague non-domain phrases as off-topic', function (): void {
    /** @var QueryRelevanceService $service */
    $service = app(QueryRelevanceService::class);

    expect($service->isRelevant('girlfriend problems'))->toBeFalse()
        ->and($service->isRelevant('burnout'))->toBeFalse();
});

it('treats blocklisted terms as off-topic even when combined with domain keywords', function (): void {
    /** @var QueryRelevanceService $service */
    $service = app(QueryRelevanceService::class);

    expect($service->isRelevant('tanginamo'))->toBeFalse()
        ->and($service->isRelevant('tanginamo tasks'))->toBeFalse();
});

it('treats short gibberish plus domain keyword as off-topic', function (): void {
    /** @var QueryRelevanceService $service */
    $service = app(QueryRelevanceService::class);

    expect($service->isRelevant('wdangoaiwnoda tasks'))->toBeFalse()
        ->and($service->isRelevant('xyz tasks'))->toBeFalse()
        ->and($service->isRelevant('my tasks'))->toBeTrue()
        ->and($service->isRelevant('what tasks should i do'))->toBeTrue();
});

it('identifies social closings and polite phrases', function (): void {
    /** @var QueryRelevanceService $service */
    $service = app(QueryRelevanceService::class);

    expect($service->isSocialClosing('thank you'))->toBeTrue()
        ->and($service->isSocialClosing('thanks'))->toBeTrue()
        ->and($service->isSocialClosing('bye'))->toBeTrue()
        ->and($service->isSocialClosing('goodbye'))->toBeTrue()
        ->and($service->isSocialClosing('okay thank you'))->toBeTrue()
        ->and($service->isSocialClosing('hahaha'))->toBeTrue()
        ->and($service->isSocialClosing('got it'))->toBeTrue()
        ->and($service->isSocialClosing('thank you so much'))->toBeTrue()
        ->and($service->isSocialClosing('that works'))->toBeTrue()
        ->and($service->isSocialClosing('all good'))->toBeTrue()
        ->and($service->isSocialClosing('schedule my task'))->toBeFalse()
        ->and($service->isSocialClosing(''))->toBeFalse();
});
