<?php

use App\Models\Task;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\IntentRoutingPolicy;

test('listing follow-up question with schedule context routes to listing_followup', function (): void {
    $user = User::factory()->create();
    $t1 = Task::factory()->for($user)->create();
    $t2 = Task::factory()->for($user)->create();

    $thread = TaskAssistantThread::factory()->create([
        'user_id' => $user->id,
        'metadata' => [
            'conversation_state' => [
                'last_flow' => 'schedule',
                'last_schedule' => [
                    'target_entities' => [
                        ['entity_type' => 'task', 'entity_id' => $t1->id, 'title' => (string) $t1->title, 'position' => 0],
                        ['entity_type' => 'task', 'entity_id' => $t2->id, 'title' => (string) $t2->title, 'position' => 1],
                    ],
                    'time_window_hint' => null,
                    'last_referenced_proposal_uuids' => [],
                ],
            ],
        ],
    ]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'are those two the most urgent?');

    expect($decision->flow)->toBe('listing_followup')
        ->and($decision->reasonCodes)->toContain('followup_listing_question_shortcircuit');
});

test('listing follow-up question with prioritize listing context routes to listing_followup', function (): void {
    $user = User::factory()->create();
    $t1 = Task::factory()->for($user)->create();

    $thread = TaskAssistantThread::factory()->create([
        'user_id' => $user->id,
        'metadata' => [
            'conversation_state' => [
                'last_flow' => 'prioritize',
                'last_listing' => [
                    'source_flow' => 'prioritize',
                    'items' => [
                        ['entity_type' => 'task', 'entity_id' => $t1->id, 'title' => (string) $t1->title, 'position' => 0],
                    ],
                ],
            ],
        ],
    ]);

    $decision = app(IntentRoutingPolicy::class)->decide($thread, 'Is that one really the most urgent?');

    expect($decision->flow)->toBe('listing_followup')
        ->and($decision->reasonCodes)->toContain('followup_listing_question_shortcircuit');
});
