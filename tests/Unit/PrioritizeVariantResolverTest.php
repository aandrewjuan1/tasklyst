<?php

use App\Enums\TaskAssistantPrioritizeVariant;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\PrioritizeVariantResolver;
use App\Services\LLM\TaskAssistant\TaskAssistantConversationStateService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

test('follow-up slice wins before browse rank or classifier', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    app(TaskAssistantConversationStateService::class)->rememberLastListing(
        $thread,
        'prioritize',
        [
            ['entity_type' => 'task', 'entity_id' => 1, 'title' => 'One'],
        ],
    );

    $resolution = app(PrioritizeVariantResolver::class)->resolve(
        $thread,
        'show next 3',
        ['prioritize_followup' => true],
        [],
    );

    expect($resolution->variant)->toBe(TaskAssistantPrioritizeVariant::FollowupSlice);
    expect($resolution->usedClassifier)->toBeFalse();
});

test('clear browse message resolves to browse without classifier', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $resolution = app(PrioritizeVariantResolver::class)->resolve(
        $thread,
        'List my tasks',
        [],
        [],
    );

    expect($resolution->variant)->toBe(TaskAssistantPrioritizeVariant::Browse);
    expect($resolution->usedClassifier)->toBeFalse();
});

test('clear prioritize message resolves to rank without classifier', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $resolution = app(PrioritizeVariantResolver::class)->resolve(
        $thread,
        'Please prioritize my homework',
        [],
        [],
    );

    expect($resolution->variant)->toBe(TaskAssistantPrioritizeVariant::Rank);
    expect($resolution->usedClassifier)->toBeFalse();
});

test('ambiguous list plus prioritize uses classifier when enabled', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'prioritize_variant' => 'browse',
                'confidence' => 0.88,
                'rationale' => 'Listing tone dominates.',
            ])
            ->withUsage(new Usage(1, 1)),
    ]);

    config()->set('task-assistant.prioritize.use_variant_classifier', true);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $resolution = app(PrioritizeVariantResolver::class)->resolve(
        $thread,
        'Show my tasks and prioritize them',
        [],
        [],
    );

    expect($resolution->variant)->toBe(TaskAssistantPrioritizeVariant::Browse);
    expect($resolution->usedClassifier)->toBeTrue();
});

test('ambiguous message falls back to rank when classifier disabled', function (): void {
    config()->set('task-assistant.prioritize.use_variant_classifier', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $resolution = app(PrioritizeVariantResolver::class)->resolve(
        $thread,
        'Show my tasks and help me prioritize',
        [],
        [],
    );

    expect($resolution->variant)->toBe(TaskAssistantPrioritizeVariant::Rank);
    expect($resolution->usedClassifier)->toBeFalse();
});

test('classifier low confidence falls back to rank', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'prioritize_variant' => 'browse',
                'confidence' => 0.2,
                'rationale' => 'unsure',
            ])
            ->withUsage(new Usage(1, 1)),
    ]);

    config()->set('task-assistant.prioritize.use_variant_classifier', true);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $resolution = app(PrioritizeVariantResolver::class)->resolve(
        $thread,
        'List my tasks and prioritize them',
        [],
        [],
    );

    expect($resolution->variant)->toBe(TaskAssistantPrioritizeVariant::Rank);
    expect($resolution->usedClassifier)->toBeTrue();
    expect($resolution->classifierRationale)->toBe('low_confidence_fallback_rank');
});
