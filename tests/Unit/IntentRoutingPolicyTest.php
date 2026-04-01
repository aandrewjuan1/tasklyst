<?php

use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\ExecutionPlan;
use App\Services\LLM\TaskAssistant\IntentRoutingPolicy;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

test('empty message routes to general guidance with empty_message reason', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, '   ');

    expect($decision->flow)->toBe('general_guidance');
    expect($decision->reasonCodes)->toContain('empty_message');
});

test('LLM intent prioritization maps to prioritize flow', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'prioritization',
                'confidence' => 0.92,
                'rationale' => 'User wants to see tasks.',
            ])
            ->withUsage(new Usage(1, 2)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'List my tasks with high priority');

    expect($decision->flow)->toBe('prioritize');
    expect($decision->reasonCodes)->toContain('llm_intent_prioritization');
});

test('LLM intent scheduling maps to schedule flow', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'scheduling',
                'confidence' => 0.95,
                'rationale' => 'User wants calendar planning.',
            ])
            ->withUsage(new Usage(1, 2)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'Schedule my day');

    expect($decision->flow)->toBe('schedule');
    expect($decision->reasonCodes)->toContain('llm_intent_scheduling');
});

test('invalid LLM intent label falls back to general guidance', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'not_a_valid_intent',
                'confidence' => 0.5,
                'rationale' => 'bad',
            ])
            ->withUsage(new Usage(1, 2)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'Hello there friend');

    expect($decision->flow)->toBe('general_guidance');
    expect($decision->reasonCodes)->toContain('intent_llm_failed_fallback_general_guidance');
});

test('signal-only mode with strong prioritize cues routes to prioritize', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'What are my top 3 tasks');

    expect($decision->flow)->toBe('prioritize');
    expect($decision->reasonCodes)->toContain('signal_only');
});

test('overwhelmed what should i do first routes to prioritize (not general_guidance)', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide(
        $thread,
        'I feel overwhelmed right, what should I do first?'
    );

    expect($decision->flow)->toBe('prioritize');
    expect($decision->constraints['count_limit'])->toBe(1);
    expect($decision->reasonCodes)->toContain('prioritize_first_shortcircuit');
});

test('explicit top count with do first keeps requested count', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide(
        $thread,
        'in my tasks whats the top 3 that i should do first?'
    );

    expect($decision->flow)->toBe('prioritize');
    expect($decision->reasonCodes)->toContain('prioritize_first_shortcircuit');
    expect($decision->constraints['count_limit'])->toBe(3);
});

test('what top tasks should i do first short circuits to prioritize with multi default count', function (): void {
    config()->set('task-assistant.intent.use_llm', false);
    config()->set('task-assistant.intent.prioritize_default_multi_count', 3);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide(
        $thread,
        'what top tasks should i do first'
    );

    expect($decision->flow)->toBe('prioritize');
    expect($decision->reasonCodes)->toContain('prioritize_first_shortcircuit');
    expect($decision->constraints['count_limit'])->toBe(3);
});

test('in my tasks what should i do first stays single item count', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide(
        $thread,
        'in my tasks what should i do first'
    );

    expect($decision->flow)->toBe('prioritize');
    expect($decision->reasonCodes)->toContain('prioritize_first_shortcircuit');
    expect($decision->constraints['count_limit'])->toBe(1);
});

test('time query routes to general guidance (not schedule)', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'what is the current time right now?');

    expect($decision->flow)->toBe('general_guidance');
    expect($decision->reasonCodes)->toContain('time_query_heuristic');
});

test('confidence gap between prioritize and schedule prefers general guidance', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'prioritization',
                'confidence' => 0.20,
                'rationale' => 'Ambiguous between prioritize and schedule.',
            ])
            ->withUsage(new Usage(1, 2)),
    ]);

    config()->set('task-assistant.intent.merge.ambiguity_gap_min', 0.30);
    config()->set('task-assistant.intent.merge.ambiguity_second_composite_min', 0.10);
    config()->set('task-assistant.intent.merge.ambiguity_top_composite_max', 0.95);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    // Intentionally includes both prioritize-ish and schedule-ish signals.
    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'tasks due today those tasks');

    expect($decision->flow)->toBe('general_guidance');
    expect($decision->reasonCodes)->toContain('confidence_gap_ambiguous_general_guidance');
});

test('policy routing resolves multiturn target entities and constraints', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'scheduling',
                'confidence' => 0.9,
                'rationale' => 'Scheduling follow-up.',
            ])
            ->withUsage(new Usage(1, 2)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    app(\App\Services\LLM\TaskAssistant\TaskAssistantConversationStateService::class)->rememberPrioritizedItems($thread, [[
        'entity_type' => 'task',
        'entity_id' => 1001,
        'title' => 'Deep work block',
    ]], 1);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'Schedule those 2 in the afternoon');

    // If fewer targets exist than requested, we align how many items we schedule
    // with the resolved target entities set (count_limit never exceeds target_entities length).
    expect($decision->constraints['count_limit'])->toBe(1);
    expect($decision->constraints['time_window_hint'])->toBe('later_afternoon');
    expect($decision->constraints['target_entities'])->toHaveCount(1);
});

test('schedule top task resolves single target and count limit one', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'scheduling',
                'confidence' => 0.9,
                'rationale' => 'Scheduling follow-up.',
            ])
            ->withUsage(new Usage(1, 2)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    app(\App\Services\LLM\TaskAssistant\TaskAssistantConversationStateService::class)->rememberPrioritizedItems($thread, [
        ['entity_type' => 'task', 'entity_id' => 10, 'title' => 'Task A'],
        ['entity_type' => 'task', 'entity_id' => 20, 'title' => 'Task B'],
    ], 2);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'Schedule the top task for later today');

    expect($decision->constraints['count_limit'])->toBe(1);
    expect($decision->constraints['target_entities'])->toHaveCount(1);
    expect((int) $decision->constraints['target_entities'][0]['entity_id'])->toBe(10);
});

test('schedule flow resolves top N against last_listing', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'scheduling',
                'confidence' => 0.9,
                'rationale' => 'Scheduling follow-up.',
            ])
            ->withUsage(new Usage(1, 2)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    app(\App\Services\LLM\TaskAssistant\TaskAssistantConversationStateService::class)->rememberLastListing(
        $thread,
        'prioritize',
        [
            ['entity_type' => 'task', 'entity_id' => 101, 'title' => 'One'],
            ['entity_type' => 'task', 'entity_id' => 102, 'title' => 'Two'],
            ['entity_type' => 'task', 'entity_id' => 103, 'title' => 'Three'],
        ],
        null,
    );

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'Schedule top 2 for later afternoon');

    expect($decision->flow)->toBe('schedule');
    expect($decision->constraints['target_entities'])->toHaveCount(2);
    expect($decision->constraints['target_entities'][0]['entity_id'])->toBe(101);
    expect($decision->constraints['target_entities'][1]['entity_id'])->toBe(102);
});

test('execution plan holds normalized orchestration fields', function (): void {
    $plan = new ExecutionPlan(
        flow: 'schedule',
        confidence: 0.82,
        clarificationNeeded: false,
        clarificationQuestion: null,
        reasonCodes: ['llm_intent_scheduling'],
        constraints: ['count_limit' => 2],
        targetEntities: [],
        timeWindowHint: 'morning',
        countLimit: 2,
        generationProfile: 'schedule',
    );

    expect($plan->flow)->toBe('schedule');
    expect($plan->countLimit)->toBe(2);
    expect($plan->generationProfile)->toBe('schedule');
});

test('after schedule, those/them resolve against last_schedule target_entities', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $stateService = app(\App\Services\LLM\TaskAssistant\TaskAssistantConversationStateService::class);

    $stateService->rememberLastListing(
        $thread,
        'prioritize',
        [
            ['entity_type' => 'task', 'entity_id' => 100, 'title' => 'A'],
            ['entity_type' => 'task', 'entity_id' => 101, 'title' => 'B'],
            ['entity_type' => 'task', 'entity_id' => 102, 'title' => 'C'],
        ],
        null,
    );

    $stateService->rememberScheduleContext(
        $thread,
        [
            ['entity_type' => 'task', 'entity_id' => 200, 'title' => 'X'],
            ['entity_type' => 'task', 'entity_id' => 201, 'title' => 'Y'],
        ],
        'later_afternoon'
    );

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'Schedule those 2 tasks in the afternoon');

    expect($decision->flow)->toBe('schedule');
    expect($decision->constraints['count_limit'])->toBe(2);
    expect($decision->constraints['time_window_hint'])->toBe('later_afternoon');
    expect($decision->constraints['target_entities'])->toHaveCount(2);
    expect($decision->constraints['target_entities'][0]['entity_id'])->toBe(200);
    expect($decision->constraints['target_entities'][1]['entity_id'])->toBe(201);
});

test('schedule those in the afternoon schedules all resolved targets by default', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    app(\App\Services\LLM\TaskAssistant\TaskAssistantConversationStateService::class)->rememberLastListing(
        $thread,
        'prioritize',
        [
            ['entity_type' => 'task', 'entity_id' => 101, 'title' => 'A'],
            ['entity_type' => 'task', 'entity_id' => 102, 'title' => 'B'],
            ['entity_type' => 'task', 'entity_id' => 103, 'title' => 'C'],
            ['entity_type' => 'task', 'entity_id' => 104, 'title' => 'D'],
            ['entity_type' => 'task', 'entity_id' => 105, 'title' => 'E'],
        ],
        null,
    );

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'Schedule those in the afternoon');

    expect($decision->flow)->toBe('schedule');
    expect($decision->constraints['time_window_hint'])->toBe('later_afternoon');
    expect($decision->constraints['target_entities'])->toHaveCount(5);
    expect($decision->constraints['count_limit'])->toBe(5);
});

test('schedule 1 and 2 for later afternoon resolves numeric index targets', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    app(\App\Services\LLM\TaskAssistant\TaskAssistantConversationStateService::class)->rememberLastListing(
        $thread,
        'prioritize',
        [
            ['entity_type' => 'task', 'entity_id' => 201, 'title' => 'A'],
            ['entity_type' => 'task', 'entity_id' => 202, 'title' => 'B'],
            ['entity_type' => 'task', 'entity_id' => 203, 'title' => 'C'],
            ['entity_type' => 'task', 'entity_id' => 204, 'title' => 'D'],
        ],
        null,
    );

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'Schedule 1 and 2 for later afternoon');

    expect($decision->flow)->toBe('schedule');
    expect($decision->constraints['time_window_hint'])->toBe('later_afternoon');
    expect($decision->constraints['target_entities'])->toHaveCount(2);
    expect($decision->constraints['target_entities'][0]['entity_id'])->toBe(201);
    expect($decision->constraints['target_entities'][1]['entity_id'])->toBe(202);
    expect($decision->constraints['count_limit'])->toBe(2);
});

test('schedule phrase with onwards maps to afternoon_onwards and strict only flag', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    app(\App\Services\LLM\TaskAssistant\TaskAssistantConversationStateService::class)->rememberLastListing(
        $thread,
        'prioritize',
        [
            ['entity_type' => 'task', 'entity_id' => 301, 'title' => 'A'],
            ['entity_type' => 'task', 'entity_id' => 302, 'title' => 'B'],
        ],
        null,
    );

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'Schedule those for later afternoon onwards only');

    expect($decision->flow)->toBe('schedule');
    expect($decision->constraints['time_window_hint'])->toBe('afternoon_onwards');
    expect((bool) ($decision->constraints['strict_window'] ?? false))->toBeTrue();
});

test('schedule phrase after lunch keeps scheduling time hint as later', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    app(\App\Services\LLM\TaskAssistant\TaskAssistantConversationStateService::class)->rememberLastListing(
        $thread,
        'prioritize',
        [
            ['entity_type' => 'task', 'entity_id' => 401, 'title' => 'A'],
        ],
        null,
    );

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'Schedule those after lunch');

    expect($decision->flow)->toBe('schedule');
    expect($decision->constraints['time_window_hint'])->toBe('later');
});

test('schedule phrase with afternoon and evening maps to combined hint', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    app(\App\Services\LLM\TaskAssistant\TaskAssistantConversationStateService::class)->rememberLastListing(
        $thread,
        'prioritize',
        [
            ['entity_type' => 'task', 'entity_id' => 501, 'title' => 'A'],
            ['entity_type' => 'task', 'entity_id' => 502, 'title' => 'B'],
            ['entity_type' => 'task', 'entity_id' => 503, 'title' => 'C'],
        ],
        null,
    );

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'schedule those three for later afternoon and evening');

    expect($decision->flow)->toBe('schedule');
    expect($decision->constraints['time_window_hint'])->toBe('afternoon_evening');
});
