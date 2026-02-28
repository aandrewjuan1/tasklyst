<?php

use App\Services\Llm\QueryRelevanceService;

it('treats task and planning queries as relevant', function (): void {
    /** @var QueryRelevanceService $service */
    $service = app(QueryRelevanceService::class);

    expect($service->isRelevant('Schedule my dashboard task by Friday'))->toBeTrue()
        ->and($service->isRelevant('Help me plan my study session for the calculus exam next week'))->toBeTrue()
        ->and($service->isRelevant('What tasks should I focus on today?'))->toBeTrue()
        ->and($service->isRelevant('Hi'))->toBeTrue()
        ->and($service->isRelevant('Hello, I need help planning my week'))->toBeTrue();
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
        ->and($service->isSocialClosing('schedule my task'))->toBeFalse()
        ->and($service->isSocialClosing(''))->toBeFalse();
});
