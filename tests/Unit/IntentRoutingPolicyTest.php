<?php

use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\ExecutionPlan;
use App\Services\LLM\TaskAssistant\IntentRoutingPolicy;
use App\Support\LLM\TaskAssistantReasonCodes;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

test('empty message routes to general guidance with empty_message reason', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, '   ');

    expect($decision->flow)->toBe('general_guidance');
    expect($decision->reasonCodes)->toContain(TaskAssistantReasonCodes::EMPTY_MESSAGE);
});

test('pure greeting fallback carries deterministic greeting reason code', function (): void {
    config()->set('task-assistant.intent.use_llm', false);
    config()->set('task-assistant.greeting.patterns', ['/^(hi)$/iu']);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'hello there');

    expect($decision->flow)->toBe('general_guidance');
    expect($decision->reasonCodes)->toContain(
        TaskAssistantReasonCodes::GENERAL_GUIDANCE_GREETING_ONLY_DETERMINISTIC
    );
});

test('LLM intent prioritization maps to prioritize flow', function (): void {
    config()->set('task-assistant.intent.inference.skip_when_signal_confident', false);

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

test('schedule my day maps to prioritize_schedule planning flow', function (): void {
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

    expect($decision->flow)->toBe('prioritize_schedule');
    expect($decision->reasonCodes)->toContain('fresh_day_planning_prioritize_schedule');
});

test('LLM intent prioritize_schedule maps to prioritize_schedule flow', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'prioritize_schedule',
                'confidence' => 0.9,
                'rationale' => 'Rank tasks, then pick the right time window.',
            ])
            ->withUsage(new Usage(1, 2)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    // Avoid the combined-prompt policy short-circuit (needs both rank-ish + time cues from regex set).
    $decision = app(IntentRoutingPolicy::class)->decide(
        $thread,
        'Slot my deadlines for tomorrow by difficulty'
    );

    expect($decision->flow)->toBe('prioritize_schedule');
    expect($decision->reasonCodes)->toContain('llm_intent_prioritize_schedule');
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
    expect($decision->reasonCodes)->toContain('intent_llm_failed_signal_fallback');
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

test('aggressive gibberish noise short-circuits to general guidance', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'asdj12 !!@@ ## qx9 z1 z2 z3 ??????');

    expect($decision->flow)->toBe('general_guidance');
    expect($decision->reasonCodes)->toContain('gibberish_shortcircuit_general_guidance');
});

test('valid short prioritize prompt is not treated as gibberish', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'top tasks now');

    expect($decision->flow)->toBe('prioritize');
    expect($decision->reasonCodes)->toContain('signal_only');
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

test('schedule second one after single-target schedule context resolves from larger ranked listing', function (): void {
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
    $state = app(\App\Services\LLM\TaskAssistant\TaskAssistantConversationStateService::class);
    $state->rememberPrioritizedItems($thread, [
        ['entity_type' => 'task', 'entity_id' => 10, 'title' => 'Task A'],
        ['entity_type' => 'task', 'entity_id' => 20, 'title' => 'Task B'],
        ['entity_type' => 'task', 'entity_id' => 30, 'title' => 'Task C'],
    ], 3);
    $state->rememberScheduleContext($thread, [
        ['entity_type' => 'task', 'entity_id' => 10, 'title' => 'Task A'],
    ], 'later');

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'now schedule the second one later as well');

    expect($decision->constraints['target_entities'])->toHaveCount(1);
    expect((int) $decision->constraints['target_entities'][0]['entity_id'])->toBe(20);
    expect($decision->constraints['count_limit'])->toBe(1);
});

test('schedule my top 1 tasks for later routes to prioritize_schedule with single count', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'schedule my top 1 tasks for later');

    expect($decision->flow)->toBe('prioritize_schedule');
    expect($decision->constraints['count_limit'])->toBe(1);
});

test('schedule my most important task for later routes to prioritize_schedule with single count', function (): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'schedule my most important task for later');

    expect($decision->flow)->toBe('prioritize_schedule');
    expect($decision->constraints['count_limit'])->toBe(1);
});

test('schedule my top tasks for later routes to prioritize_schedule with default multi count', function (string $prompt): void {
    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, $prompt);

    expect($decision->flow)->toBe('prioritize_schedule');
    expect($decision->constraints['count_limit'])->toBe(3);
})->with([
    'schedule my top tasks for tomorrow',
    'schedule my top tasks for tomorrow afternoon',
]);

test('prioritize my most important task resolves to prioritize with single count', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'prioritize my most important task');

    expect($decision->flow)->toBe('prioritize');
    expect($decision->constraints['count_limit'])->toBe(1);
});

test('schedule my top tasks for tomorrow afternoon over prior listing targets all top tasks', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

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

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'schedule my top tasks for tomorrow afternoon');

    expect($decision->flow)->toBe('prioritize_schedule');
    expect($decision->constraints['target_entities'])->toHaveCount(3);
    expect($decision->constraints['count_limit'])->toBe(3);
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

    expect($decision->flow)->toBe('prioritize_schedule');
    expect($decision->constraints['target_entities'])->toHaveCount(2);
    expect($decision->constraints['target_entities'][0]['entity_id'])->toBe(101);
    expect($decision->constraints['target_entities'][1]['entity_id'])->toBe(102);
});

test('llm listing_followup without context routes to general guidance clarify path', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'listing_followup',
                'confidence' => 0.82,
                'rationale' => 'Likely follow-up.',
            ])
            ->withUsage(new Usage(1, 2)),
    ]);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'is that order right?');

    expect($decision->flow)->toBe('general_guidance');
    expect($decision->reasonCodes)->toContain('intent_llm_listing_followup_missing_context_clarify');
});

test('help phrasing with specific scheduling intent does not short-circuit to general guidance', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'can you help me schedule my tasks for tomorrow afternoon');

    expect($decision->flow)->not->toBe('general_guidance');
    expect($decision->flow)->toBe('schedule');
});

test('whole-day planning prompt shortcircuits to prioritize_schedule', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'plan my whole day later');

    expect($decision->flow)->toBe('prioritize_schedule');
    expect($decision->reasonCodes)->toContain('fresh_day_planning_prioritize_schedule');
});

test('slang-ish prioritize phrase routes to prioritize in signal-only mode', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'what should i tackle first rn');

    expect($decision->flow)->toBe('prioritize');
});

test('slang-ish day planning phrase routes to prioritize_schedule in signal-only mode', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'map out my day and what should i do first');

    expect($decision->flow)->toBe('prioritize_schedule');
});

test('off-topic sorting phrase does not route to prioritize', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'sort out the best phone for me');

    expect($decision->flow)->toBe('general_guidance');
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

test('schedule them all prefers broader prioritize listing over last single schedule target', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $stateService = app(\App\Services\LLM\TaskAssistant\TaskAssistantConversationStateService::class);

    $stateService->rememberLastListing(
        $thread,
        'prioritize',
        [
            ['entity_type' => 'task', 'entity_id' => 901, 'title' => 'A'],
            ['entity_type' => 'task', 'entity_id' => 902, 'title' => 'B'],
            ['entity_type' => 'task', 'entity_id' => 903, 'title' => 'C'],
        ],
        null,
    );

    $stateService->rememberScheduleContext(
        $thread,
        [
            ['entity_type' => 'task', 'entity_id' => 901, 'title' => 'A'],
        ],
        'later'
    );

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'okay schedule them all for tomorrow instead');

    expect($decision->flow)->toBe('schedule');
    expect($decision->constraints['target_entities'])->toHaveCount(3);
    expect($decision->constraints['count_limit'])->toBe(3);
});

test('fresh explicit top n schedule request does not inherit stale single schedule target', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $stateService = app(\App\Services\LLM\TaskAssistant\TaskAssistantConversationStateService::class);

    $stateService->rememberLastListing(
        $thread,
        'prioritize',
        [
            ['entity_type' => 'task', 'entity_id' => 1001, 'title' => 'A'],
            ['entity_type' => 'task', 'entity_id' => 1002, 'title' => 'B'],
            ['entity_type' => 'task', 'entity_id' => 1003, 'title' => 'C'],
            ['entity_type' => 'task', 'entity_id' => 1004, 'title' => 'D'],
        ],
        null,
    );

    $stateService->rememberScheduleContext(
        $thread,
        [
            ['entity_type' => 'task', 'entity_id' => 1001, 'title' => 'A'],
        ],
        'later'
    );

    $decision = app(IntentRoutingPolicy::class)->decide(
        $thread,
        'im overwhelmed right now i have so many tasks schedule my top 3 for this week spread them out'
    );

    expect($decision->flow)->toBe('prioritize_schedule');
    expect($decision->constraints['count_limit'])->toBe(3);
    expect($decision->constraints['target_entities'])->toBe([]);
});

test('deictic explicit count schedule follow-up still aligns with resolved targets', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    app(\App\Services\LLM\TaskAssistant\TaskAssistantConversationStateService::class)->rememberScheduleContext(
        $thread,
        [
            ['entity_type' => 'task', 'entity_id' => 1201, 'title' => 'A'],
        ],
        'later'
    );

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'schedule those 3 this week');

    expect($decision->flow)->toBe('schedule');
    expect($decision->constraints['target_entities'])->toHaveCount(1);
    expect($decision->constraints['count_limit'])->toBe(1);
});

test('schedule it after multi-item prioritize listing resolves the full ranked set', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $stateService = app(\App\Services\LLM\TaskAssistant\TaskAssistantConversationStateService::class);
    $stateService->rememberLastListing(
        $thread,
        'prioritize',
        [
            ['entity_type' => 'task', 'entity_id' => 901, 'title' => 'A'],
            ['entity_type' => 'task', 'entity_id' => 902, 'title' => 'B'],
            ['entity_type' => 'task', 'entity_id' => 903, 'title' => 'C'],
        ],
        null,
    );

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'schedule it for later today');

    expect($decision->flow)->toBe('schedule');
    expect($decision->constraints['target_entities'])->toHaveCount(3);
    expect($decision->constraints['count_limit'])->toBe(3);
});

test('schedule it after single-item prioritize listing remains single target', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    $stateService = app(\App\Services\LLM\TaskAssistant\TaskAssistantConversationStateService::class);
    $stateService->rememberLastListing(
        $thread,
        'prioritize',
        [
            ['entity_type' => 'task', 'entity_id' => 901, 'title' => 'A'],
        ],
        null,
    );

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'schedule it for later today');

    expect($decision->flow)->toBe('schedule');
    expect($decision->constraints['target_entities'])->toHaveCount(1);
    expect($decision->constraints['count_limit'])->toBe(1);
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

test('schedule the two tasks for later binds count + type from last prioritize listing', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    app(\App\Services\LLM\TaskAssistant\TaskAssistantConversationStateService::class)->rememberLastListing(
        $thread,
        'prioritize',
        [
            ['entity_type' => 'task', 'entity_id' => 10, 'title' => 'A'],
            ['entity_type' => 'event', 'entity_id' => 99, 'title' => 'E'],
            ['entity_type' => 'task', 'entity_id' => 20, 'title' => 'B'],
        ],
        null,
    );

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'schedule the two tasks for later');

    expect($decision->flow)->toBe('schedule');
    expect($decision->constraints['target_entities'])->toHaveCount(2);
    expect($decision->constraints['target_entities'][0]['entity_id'])->toBe(10);
    expect($decision->constraints['target_entities'][1]['entity_id'])->toBe(20);
    expect($decision->constraints['count_limit'])->toBe(2);
});

test('schedule two tasks for later extracts word count when no listing exists', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'schedule two tasks for later');

    expect($decision->flow)->toBe('schedule');
    expect($decision->constraints['count_limit'])->toBe(2);
});

test('schedule my tasks for later short-circuits to prioritize_schedule flow', function (): void {
    config()->set('task-assistant.intent.use_llm', true);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'schedule my tasks for later');

    expect($decision->flow)->toBe('prioritize_schedule');
    expect($decision->reasonCodes)->toContain('fresh_batch_schedule_shortcircuit');
    expect($decision->constraints['time_window_hint'])->toBe('later');
});

test('schedule only the first one for later extracts count_limit 1 when no listing exists', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'schedule only the first one for later');

    expect($decision->flow)->toBe('schedule');
    expect($decision->constraints['count_limit'])->toBe(1);
});

test('schedule second task resolves to second task entity with count_limit 1', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    app(\App\Services\LLM\TaskAssistant\TaskAssistantConversationStateService::class)->rememberLastListing(
        $thread,
        'prioritize',
        [
            ['entity_type' => 'task', 'entity_id' => 10, 'title' => 'A'],
            ['entity_type' => 'event', 'entity_id' => 99, 'title' => 'E'],
            ['entity_type' => 'task', 'entity_id' => 20, 'title' => 'B'],
        ],
        null,
    );

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'schedule second task for tomorrow');

    expect($decision->flow)->toBe('schedule');
    expect($decision->constraints['target_entities'])->toHaveCount(1);
    expect($decision->constraints['target_entities'][0]['entity_id'])->toBe(20);
    expect($decision->constraints['count_limit'])->toBe(1);
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

test('pending schedule context plus edit-like prompt shortcircuits to schedule', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    app(\App\Services\LLM\TaskAssistant\TaskAssistantConversationStateService::class)->rememberScheduleContext(
        $thread,
        [
            ['entity_type' => 'task', 'entity_id' => 701, 'title' => 'A'],
            ['entity_type' => 'task', 'entity_id' => 702, 'title' => 'B'],
        ],
        null
    );

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'move the first one to last');

    expect($decision->flow)->toBe('schedule');
    expect($decision->reasonCodes)->toContain('schedule_refinement_context_shortcircuit');
});

test('pending schedule context plus implicit edit phrase shortcircuits to schedule', function (): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    app(\App\Services\LLM\TaskAssistant\TaskAssistantConversationStateService::class)->rememberScheduleContext(
        $thread,
        [
            ['entity_type' => 'task', 'entity_id' => 701, 'title' => 'A'],
            ['entity_type' => 'task', 'entity_id' => 702, 'title' => 'B'],
            ['entity_type' => 'task', 'entity_id' => 703, 'title' => 'C'],
        ],
        null
    );

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'third one at evening instead');

    expect($decision->flow)->toBe('schedule');
    expect($decision->reasonCodes)->toContain('schedule_refinement_context_shortcircuit');
});

test('prioritize-first prompts include routing signal diagnostics', function (string $prompt): void {
    config()->set('task-assistant.intent.use_llm', false);

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, $prompt);

    expect($decision->flow)->toBeIn(['prioritize', 'prioritize_schedule']);
    expect(data_get($decision->constraints, 'routing_signal_strength.source'))->toBe('heuristic_v1');
    expect((float) data_get($decision->constraints, 'routing_signal_strength.schedule'))->toBeGreaterThan(0.0);
    expect((float) data_get($decision->constraints, 'routing_signal_strength.hybrid'))->toBeGreaterThan(0.0);
    if ($decision->flow === 'prioritize') {
        expect((string) ($decision->constraints['demotion_reason_detail'] ?? ''))->not->toBe('');
    }
})->with([
    'next 3 priorities tomorrow' => 'what are my next 3 priorities for tomorrow',
    'top priorities later today' => 'show my top priorities later today',
]);
