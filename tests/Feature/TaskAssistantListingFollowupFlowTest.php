<?php

use App\Enums\MessageRole;
use App\Models\Task;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

test('listing followup flow produces listing_followup envelope without daily_schedule', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Thanks for checking in—that is a fair question.',
                'rationale' => 'Compared to your workspace ranking, those items sit where you would expect for this slice.',
                'caveats' => 'Urgency is still your call when life throws surprises.',
                'next_options' => 'If you want, we can tweak the plan or refresh your top tasks.',
                'next_options_chip_texts' => [
                    'Schedule them differently',
                    'Show my top tasks again',
                ],
            ])
            ->withUsage(new Usage(2, 8)),
    ]);

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

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'are those two the most urgent?',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('listing_followup');
    expect(data_get($assistantMessage->metadata, 'listing_followup.verdict'))->toBeIn(['yes', 'partial', 'no']);
    expect(data_get($assistantMessage->metadata, 'listing_followup.compared_items'))->toBeArray();
    expect(data_get($assistantMessage->metadata, 'schedule.proposals'))->toBeNull();
    expect($assistantMessage->content)->not->toContain('proposal_uuid');

    $thread->refresh();
    $state = data_get($thread->metadata, 'conversation_state', []);
    expect($state['last_flow'] ?? null)->toBe('schedule');
    expect(data_get($state, 'last_schedule.target_entities'))->toHaveCount(2);
    expect(data_get($state, 'last_listing.source_flow'))->toBe('listing_followup');
    expect(data_get($state, 'last_listing.items'))->toHaveCount(2);
});

test('listing followup works when last context is prioritize listing only', function (): void {
    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'framing' => 'Good question about the list I showed.',
                'rationale' => 'Against your current ranking, those rows are in a sensible band.',
                'caveats' => null,
                'next_options' => 'Want to schedule one of them or refresh the ranking?',
                'next_options_chip_texts' => [
                    'Schedule the top one',
                    'Show top tasks again',
                ],
            ])
            ->withUsage(new Usage(2, 8)),
    ]);

    $user = User::factory()->create();
    $t1 = Task::factory()->for($user)->create();
    $t2 = Task::factory()->for($user)->create();

    $thread = TaskAssistantThread::factory()->create([
        'user_id' => $user->id,
        'metadata' => [
            'conversation_state' => [
                'last_flow' => 'prioritize',
                'last_listing' => [
                    'source_flow' => 'prioritize',
                    'items' => [
                        ['entity_type' => 'task', 'entity_id' => $t1->id, 'title' => (string) $t1->title, 'position' => 0],
                        ['entity_type' => 'task', 'entity_id' => $t2->id, 'title' => (string) $t2->title, 'position' => 1],
                    ],
                ],
            ],
        ],
    ]);

    $userMessage = $thread->messages()->create([
        'role' => MessageRole::User,
        'content' => 'Are those the most urgent ones?',
    ]);
    $assistantMessage = $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => '',
    ]);

    app(TaskAssistantService::class)->processQueuedMessage($thread, $userMessage->id, $assistantMessage->id);

    $assistantMessage->refresh();

    expect($assistantMessage->metadata['structured']['flow'] ?? null)->toBe('listing_followup');
    expect(data_get($assistantMessage->metadata, 'schedule.proposals'))->toBeNull();

    $thread->refresh();
    $state = data_get($thread->metadata, 'conversation_state', []);
    expect($state['last_flow'] ?? null)->toBe('listing_followup');
    expect(data_get($state, 'last_listing.source_flow'))->toBe('listing_followup');
    expect(data_get($state, 'last_listing.items'))->toHaveCount(2);
});
