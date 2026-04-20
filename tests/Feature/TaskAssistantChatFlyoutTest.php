<?php

use App\Jobs\BroadcastTaskAssistantStreamJob;
use App\Models\AssistantSchedulePlan;
use App\Models\AssistantSchedulePlanItem;
use App\Models\DatabaseNotification;
use App\Models\Task;
use App\Models\TaskAssistantThread;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;

test('authenticated user sees task assistant flyout trigger and can open chat', function () {
    /** @var \Illuminate\Foundation\Testing\TestCase $this */
    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    $response = $this->get(route('workspace'));
    $response->assertSuccessful();
    $response->assertSee('Assistant', false);
    $response->assertDontSee('wire:click="applyQuickPromptChip', false);
});

test('chat flyout component dispatches job on submit', function () {
    /** @var \Illuminate\Foundation\Testing\TestCase $this */
    Bus::fake();
    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    Livewire::test('assistant.chat-flyout')
        ->assertSet('newMessage', '')
        ->assertSet('isStreaming', false)
        ->set('newMessage', 'Hello')
        ->call('submitMessage')
        ->assertSet('newMessage', '')
        ->assertSet('isStreaming', true);

    Bus::assertDispatched(BroadcastTaskAssistantStreamJob::class, function (BroadcastTaskAssistantStreamJob $job) use ($user) {
        return $job->userId === $user->id
            && $job->queue === config('task-assistant.queue', 'task-assistant');
    });
});

test('chat flyout does nothing when submitting empty input', function () {
    Bus::fake();
    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    Livewire::test('assistant.chat-flyout')
        ->assertSet('isStreaming', false)
        ->set('newMessage', '')
        ->call('submitMessage')
        ->assertSet('isStreaming', false)
        ->assertSet('streamingMessageId', null)
        ->assertSet('newMessage', '');

    Bus::assertNotDispatched(BroadcastTaskAssistantStreamJob::class);
});

test('chat flyout shows loading spinner text while streaming and no reconnect hint', function () {
    Bus::fake();
    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    Livewire::test('assistant.chat-flyout')
        ->set('newMessage', 'Please help me plan my day')
        ->call('submitMessage')
        ->assertSet('isStreaming', true)
        ->assertSee('Thinking through this for you...')
        ->assertSee('This usually takes just a moment.')
        ->assertDontSee('Reconnecting to assistant...');
});

test('chat flyout rate limits rapid submissions per user', function () {
    /** @var \Illuminate\Foundation\Testing\TestCase $this */
    Bus::fake();
    config()->set('task-assistant.rate_limit.submissions_per_minute', 1);
    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    Livewire::test('assistant.chat-flyout')
        ->set('newMessage', 'First')
        ->call('submitMessage')
        ->set('newMessage', 'Second')
        ->call('submitMessage')
        ->assertHasErrors(['newMessage']);
});

test('chat flyout quick prompt chip inserts into input', function (): void {
    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    Livewire::test('assistant.chat-flyout')
        ->assertSet('isStreaming', false)
        ->set('newMessage', '')
        ->call('applyQuickPromptChip', 'What should I do first')
        ->assertSet('newMessage', 'What should I do first');
});

test('chat flyout quick prompt chip replaces existing input', function (): void {
    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    Livewire::test('assistant.chat-flyout')
        ->assertSet('isStreaming', false)
        ->set('newMessage', 'Existing text')
        ->call('applyQuickPromptChip', 'Schedule my most important task')
        ->assertSet('newMessage', 'Schedule my most important task');
});

test('chat flyout quick prompt chip does nothing while streaming', function (): void {
    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    Livewire::test('assistant.chat-flyout')
        ->assertSet('isStreaming', false)
        ->set('newMessage', 'Existing text')
        ->set('isStreaming', true)
        ->call('applyQuickPromptChip', 'Schedule my most important task')
        ->assertSet('newMessage', 'Existing text');
});

test('chat flyout renders four dynamic empty-state quick chips in morning', function (): void {
    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-20 08:30:00', $timezone));

    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    Livewire::test('assistant.chat-flyout')
        ->assertSet('isStreaming', false)
        ->assertCount('emptyStateQuickChips', 4)
        ->assertSee('Create a plan for today');

    CarbonImmutable::setTestNow();
});

test('chat flyout evening new chat includes tomorrow and later scheduling chips', function (): void {
    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-20 20:45:00', $timezone));

    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    Livewire::test('assistant.chat-flyout')
        ->call('startNewChat')
        ->assertSet('isStreaming', false)
        ->assertCount('emptyStateQuickChips', 4)
        ->assertSee('Create a plan for tomorrow')
        ->assertSee('Schedule top 1 for later');

    CarbonImmutable::setTestNow();
});

test('chat flyout submits prioritize-oriented message and dispatches job', function () {
    /** @var \Illuminate\Foundation\Testing\TestCase $this */
    Bus::fake();
    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'prioritization',
                'confidence' => 0.9,
                'rationale' => 'User wants help choosing what to work on.',
            ])
            ->withUsage(new Usage(1, 2)),
        StructuredResponseFake::make()
            ->withStructured([
                'summary' => 'Here is a sensible order to tackle things.',
                'assistant_note' => 'Pick one task and start a short focus block.',
                'reasoning' => 'Balances urgency with available time.',
                'strategy_points' => ['Start with the smallest win if energy is low.'],
                'suggested_next_steps' => ['Block 25 minutes for the first item.'],
                'assumptions' => [],
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    Livewire::test('assistant.chat-flyout')
        ->set('newMessage', 'Help me choose what to work on next')
        ->call('submitMessage')
        ->assertSet('isStreaming', true);

    Bus::assertDispatched(BroadcastTaskAssistantStreamJob::class);
});

test('chat flyout submits message and dispatches job', function () {
    /** @var \Illuminate\Foundation\Testing\TestCase $this */
    Bus::fake();
    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'intent' => 'task',
                'acknowledgement' => 'Sure.',
                'message' => 'I can help with your task planning.',
                'suggested_next_actions' => [
                    'Tell me what to prioritize first',
                    'Share when you want to schedule work',
                ],
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    Livewire::test('assistant.chat-flyout')
        ->set('newMessage', 'List my tasks')
        ->call('submitMessage')
        ->assertSet('isStreaming', true);

    Bus::assertDispatched(BroadcastTaskAssistantStreamJob::class);
});

test('chat flyout can accept all schedule proposals and apply updates', function () {
    /** @var User $user */
    $user = User::factory()->create();
    $this->actingAs($user);

    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    session(['task_assistant.current_thread_id' => $thread->id]);

    $task = Task::factory()->for($user)->create([
        'title' => 'Task to schedule',
        'status' => \App\Enums\TaskStatus::ToDo,
        'start_datetime' => null,
        'duration' => 15,
    ]);
    $startAt = now()->addHour()->startOfHour();

    $assistantMessage = $thread->messages()->create([
        'role' => \App\Enums\MessageRole::Assistant,
        'content' => 'Proposed schedule',
        'metadata' => [
            'daily_schedule' => [
                'proposals' => [[
                    'proposal_id' => 'proposal-task-1',
                    'status' => 'pending',
                    'entity_type' => 'task',
                    'entity_id' => $task->id,
                    'title' => $task->title,
                    'start_datetime' => $startAt->toIso8601String(),
                    'duration_minutes' => 90,
                ]],
            ],
        ],
    ]);

    Livewire::test('assistant.chat-flyout')
        ->call('acceptAllScheduleProposals', $assistantMessage->id);

    $assistantMessage->refresh();
    $task->refresh();

    expect(data_get($assistantMessage->metadata, 'schedule.proposals.0.status'))->toBe('accepted');
    expect($task->duration)->toBe(90);
    expect($task->start_datetime?->toIso8601String())->toContain($startAt->format('Y-m-d\TH'));

    $notification = DatabaseNotification::query()
        ->where('notifiable_type', User::class)
        ->where('notifiable_id', $user->id)
        ->latest()
        ->first();

    expect($notification)->not->toBeNull();
    expect(data_get($notification?->data, 'type'))->toBe('assistant_schedule_accept_success');
    expect(data_get($notification?->data, 'route'))->toBe('workspace');
    expect(data_get($notification?->data, 'meta.accepted_count'))->toBe(1);

    $plan = AssistantSchedulePlan::query()
        ->where('user_id', $user->id)
        ->where('thread_id', $thread->id)
        ->where('assistant_message_id', $assistantMessage->id)
        ->first();

    expect($plan)->not->toBeNull();

    $planItem = AssistantSchedulePlanItem::query()
        ->where('assistant_schedule_plan_id', $plan?->id)
        ->where('proposal_uuid', 'proposal-task-1')
        ->first();

    expect($planItem)->not->toBeNull();
    expect($planItem?->entity_type)->toBe('task');
    expect((int) $planItem?->entity_id)->toBe($task->id);
});

test('chat flyout accept all applies multiple pending task proposals', function () {
    /** @var User $user */
    $user = User::factory()->create();
    $this->actingAs($user);

    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    session(['task_assistant.current_thread_id' => $thread->id]);

    $taskA = Task::factory()->for($user)->create([
        'title' => 'Task A',
        'status' => \App\Enums\TaskStatus::ToDo,
        'start_datetime' => null,
        'duration' => 30,
    ]);
    $taskB = Task::factory()->for($user)->create([
        'title' => 'Task B',
        'status' => \App\Enums\TaskStatus::ToDo,
        'start_datetime' => null,
        'duration' => 30,
    ]);
    $startA = now()->addHours(2)->startOfHour();
    $startB = now()->addHours(4)->startOfHour();

    $assistantMessage = $thread->messages()->create([
        'role' => \App\Enums\MessageRole::Assistant,
        'content' => 'Proposed schedule',
        'metadata' => [
            'daily_schedule' => [
                'proposals' => [
                    [
                        'proposal_id' => 'pa',
                        'status' => 'pending',
                        'entity_type' => 'task',
                        'entity_id' => $taskA->id,
                        'title' => $taskA->title,
                        'start_datetime' => $startA->toIso8601String(),
                        'end_datetime' => $startA->copy()->addMinutes(60)->toIso8601String(),
                        'duration_minutes' => 60,
                    ],
                    [
                        'proposal_id' => 'pb',
                        'status' => 'pending',
                        'entity_type' => 'task',
                        'entity_id' => $taskB->id,
                        'title' => $taskB->title,
                        'start_datetime' => $startB->toIso8601String(),
                        'end_datetime' => $startB->copy()->addMinutes(45)->toIso8601String(),
                        'duration_minutes' => 45,
                    ],
                ],
            ],
        ],
    ]);

    Livewire::test('assistant.chat-flyout')
        ->call('acceptAllScheduleProposals', $assistantMessage->id);

    $assistantMessage->refresh();
    $taskA->refresh();
    $taskB->refresh();

    expect(data_get($assistantMessage->metadata, 'schedule.proposals.0.status'))->toBe('accepted');
    expect(data_get($assistantMessage->metadata, 'schedule.proposals.1.status'))->toBe('accepted');
    expect($taskA->duration)->toBe(60);
    expect($taskB->duration)->toBe(45);
});

test('chat flyout accept all persists scheduled focus items visible in workspace plan panel', function () {
    /** @var User $user */
    $user = User::factory()->create();
    $this->actingAs($user);

    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    session(['task_assistant.current_thread_id' => $thread->id]);

    $task = Task::factory()->for($user)->create([
        'title' => 'Focus panel persistence check',
        'status' => \App\Enums\TaskStatus::ToDo,
        'start_datetime' => null,
        'duration' => 30,
    ]);
    $startAt = now()->addHour()->startOfHour();

    $assistantMessage = $thread->messages()->create([
        'role' => \App\Enums\MessageRole::Assistant,
        'content' => 'Proposed schedule',
        'metadata' => [
            'daily_schedule' => [
                'proposals' => [[
                    'proposal_id' => 'focus-plan-panel-1',
                    'status' => 'pending',
                    'entity_type' => 'task',
                    'entity_id' => $task->id,
                    'title' => $task->title,
                    'start_datetime' => $startAt->toIso8601String(),
                    'duration_minutes' => 60,
                ]],
            ],
        ],
    ]);

    Livewire::test('assistant.chat-flyout')
        ->call('acceptAllScheduleProposals', $assistantMessage->id);

    $planItem = AssistantSchedulePlanItem::query()
        ->where('user_id', $user->id)
        ->where('proposal_uuid', 'focus-plan-panel-1')
        ->first();

    expect($planItem)->not->toBeNull();
    expect($planItem?->status?->value)->toBe('planned');

    Livewire::test('pages::workspace.index')
        ->assertSee('Scheduled focus')
        ->assertSee('Focus panel persistence check');
});

test('chat flyout does not accept all on stale schedule card when a newer assistant message exists', function () {
    /** @var User $user */
    $user = User::factory()->create();
    $this->actingAs($user);

    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    session(['task_assistant.current_thread_id' => $thread->id]);

    $task = Task::factory()->for($user)->create([
        'status' => \App\Enums\TaskStatus::ToDo,
        'start_datetime' => null,
    ]);
    $startAt = now()->addHour();

    $olderAssistant = $thread->messages()->create([
        'role' => \App\Enums\MessageRole::Assistant,
        'content' => 'Older schedule',
        'metadata' => [
            'daily_schedule' => [
                'proposals' => [[
                    'proposal_id' => 'old',
                    'status' => 'pending',
                    'entity_type' => 'task',
                    'entity_id' => $task->id,
                    'title' => 'T',
                    'start_datetime' => $startAt->toIso8601String(),
                    'duration_minutes' => 30,
                ]],
            ],
        ],
    ]);

    $thread->messages()->create([
        'role' => \App\Enums\MessageRole::Assistant,
        'content' => 'Newer reply',
        'metadata' => [],
    ]);

    Livewire::test('assistant.chat-flyout')
        ->call('acceptAllScheduleProposals', $olderAssistant->id);

    $olderAssistant->refresh();
    expect(data_get($olderAssistant->metadata, 'daily_schedule.proposals.0.status'))->toBe('pending');
});

test('chat flyout accept all dispatches success toast only on full success', function () {
    /** @var User $user */
    $user = User::factory()->create();
    $this->actingAs($user);

    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    session(['task_assistant.current_thread_id' => $thread->id]);

    $task = Task::factory()->for($user)->create([
        'title' => 'Task to schedule',
        'status' => \App\Enums\TaskStatus::ToDo,
        'start_datetime' => null,
        'duration' => 30,
    ]);
    $startAt = now()->addHour()->startOfHour();

    $assistantMessage = $thread->messages()->create([
        'role' => \App\Enums\MessageRole::Assistant,
        'content' => 'Proposed schedule',
        'metadata' => [
            'daily_schedule' => [
                'proposals' => [[
                    'proposal_id' => 'full-success',
                    'status' => 'pending',
                    'entity_type' => 'task',
                    'entity_id' => $task->id,
                    'title' => $task->title,
                    'start_datetime' => $startAt->toIso8601String(),
                    'duration_minutes' => 60,
                ]],
            ],
        ],
    ]);

    Livewire::test('assistant.chat-flyout')
        ->call('acceptAllScheduleProposals', $assistantMessage->id)
        ->assertDispatched('toast', type: 'success', message: 'Accepted 1 proposal.');
});

test('chat flyout accept all does not dispatch success toast or notification when nothing is schedulable', function () {
    /** @var User $user */
    $user = User::factory()->create();
    $this->actingAs($user);

    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    session(['task_assistant.current_thread_id' => $thread->id]);

    $assistantMessage = $thread->messages()->create([
        'role' => \App\Enums\MessageRole::Assistant,
        'content' => 'Proposed schedule',
        'metadata' => [
            'daily_schedule' => [
                'proposals' => [[
                    'proposal_id' => 'placeholder',
                    'status' => 'pending',
                    'entity_type' => 'task',
                    'entity_id' => null,
                    'title' => 'No schedulable items found',
                    'start_datetime' => now()->addHour()->toIso8601String(),
                ]],
            ],
        ],
    ]);

    Livewire::test('assistant.chat-flyout')
        ->call('acceptAllScheduleProposals', $assistantMessage->id)
        ->assertNotDispatched('toast');

    $count = DatabaseNotification::query()
        ->where('notifiable_type', User::class)
        ->where('notifiable_id', $user->id)
        ->count();

    expect($count)->toBe(0);
});

test('chat flyout restores streaming state from persisted thread metadata after reload', function () {
    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    $thread = TaskAssistantThread::factory()->create([
        'user_id' => $user->id,
        'metadata' => [
            'stream' => [
                'processing' => [
                    'active' => true,
                    'assistant_message_id' => 0,
                    'started_at' => now()->toIso8601String(),
                ],
            ],
        ],
    ]);
    session(['task_assistant.current_thread_id' => $thread->id]);

    $thread->messages()->create([
        'role' => \App\Enums\MessageRole::User,
        'content' => 'What should I do first?',
    ]);
    $assistant = $thread->messages()->create([
        'role' => \App\Enums\MessageRole::Assistant,
        'content' => '',
        'metadata' => [],
    ]);

    $metadata = $thread->metadata ?? [];
    data_set($metadata, 'stream.processing.assistant_message_id', $assistant->id);
    $thread->update(['metadata' => $metadata]);

    Livewire::test('assistant.chat-flyout')
        ->assertSet('isStreaming', true)
        ->assertSet('streamingMessageId', $assistant->id)
        ->assertSee('Thinking through this for you...');
});

test('chat flyout can request stop and marks assistant message as stopped', function () {
    Bus::fake();
    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    $component = Livewire::test('assistant.chat-flyout')
        ->set('newMessage', 'Help me plan this afternoon')
        ->call('submitMessage')
        ->assertSet('isStreaming', true)
        ->assertSee('Cancel')
        ->call('requestStopStreaming')
        ->assertSet('isStreaming', false)
        ->assertDontSee('Cancel');

    $threadId = (int) data_get($component->get('thread'), 'id', 0);
    expect($threadId)->toBeGreaterThan(0);

    $thread = TaskAssistantThread::query()->findOrFail($threadId);
    $assistantMessage = $thread->messages()
        ->where('role', \App\Enums\MessageRole::Assistant)
        ->latest('id')
        ->first();

    expect($assistantMessage)->not->toBeNull();
    expect(data_get($assistantMessage?->metadata, 'stream.status'))->toBe('stopped');
    expect((string) ($assistantMessage?->content ?? ''))->toBe('');
    expect(data_get($thread->metadata, 'stream.processing'))->toBeNull();
});

test('chat flyout shows stopped label for stopped assistant message', function () {
    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    session(['task_assistant.current_thread_id' => $thread->id]);

    $thread->messages()->create([
        'role' => \App\Enums\MessageRole::User,
        'content' => 'Please help me plan',
    ]);

    $thread->messages()->create([
        'role' => \App\Enums\MessageRole::Assistant,
        'content' => '',
        'metadata' => [
            'stream' => [
                'status' => 'stopped',
                'stopped_at' => now()->toIso8601String(),
            ],
        ],
    ]);

    Livewire::test('assistant.chat-flyout')
        ->assertSee('Response stopped');
});

test('chat flyout does not append late deltas after stop request', function () {
    Bus::fake();
    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    Livewire::test('assistant.chat-flyout')
        ->set('newMessage', 'Draft my plan')
        ->call('submitMessage')
        ->assertSet('isStreaming', true)
        ->call('requestStopStreaming')
        ->assertSet('isStreaming', false)
        ->dispatch('echo-private:task-assistant.user.'.$user->id.',.json_delta', [
            'assistant_message_id' => 999999,
            'delta' => 'late text',
        ])
        ->assertSet('streamingContent', '')
        ->assertDontSee('late text');
});

test('chat flyout ignores stream end for a different assistant message id', function () {
    Bus::fake();
    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    Livewire::test('assistant.chat-flyout')
        ->set('newMessage', 'First')
        ->call('submitMessage')
        ->assertSet('isStreaming', true)
        ->dispatch('echo-private:task-assistant.user.'.$user->id.',.stream_end', [
            'assistant_message_id' => 999999,
        ])
        ->assertSet('isStreaming', true);
});

test('chat flyout does not rehydrate loading state for stopped message on reload', function () {
    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    $thread = TaskAssistantThread::factory()->create([
        'user_id' => $user->id,
        'metadata' => [
            'stream' => [
                'processing' => [
                    'active' => true,
                    'assistant_message_id' => 0,
                    'started_at' => now()->toIso8601String(),
                ],
            ],
        ],
    ]);
    session(['task_assistant.current_thread_id' => $thread->id]);

    $assistant = $thread->messages()->create([
        'role' => \App\Enums\MessageRole::Assistant,
        'content' => '',
        'metadata' => [
            'stream' => [
                'status' => 'stopped',
                'stopped_at' => now()->toIso8601String(),
            ],
        ],
    ]);

    $metadata = $thread->metadata ?? [];
    data_set($metadata, 'stream.processing.assistant_message_id', $assistant->id);
    $thread->update(['metadata' => $metadata]);

    Livewire::test('assistant.chat-flyout')
        ->assertSet('isStreaming', false);

    $thread->refresh();
    expect(data_get($thread->metadata, 'stream.processing'))->toBeNull();
});

test('chat flyout marks timeout when stream health window is exceeded', function () {
    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);
    config()->set('task-assistant.streaming.health_timeout_seconds', 1);

    $thread = TaskAssistantThread::factory()->create([
        'user_id' => $user->id,
        'metadata' => [
            'stream' => [
                'processing' => [
                    'active' => true,
                    'assistant_message_id' => 0,
                    'started_at' => now()->subSeconds(10)->toIso8601String(),
                ],
            ],
        ],
    ]);
    session(['task_assistant.current_thread_id' => $thread->id]);

    $assistant = $thread->messages()->create([
        'role' => \App\Enums\MessageRole::Assistant,
        'content' => '',
        'metadata' => [],
    ]);

    $metadata = $thread->metadata ?? [];
    data_set($metadata, 'stream.processing.assistant_message_id', $assistant->id);
    $thread->update(['metadata' => $metadata]);

    Livewire::test('assistant.chat-flyout')
        ->call('checkStreamingTimeout')
        ->assertSet('streamingTimedOutAt', fn ($value): bool => is_string($value) && $value !== '');
});

test('chat flyout stops previous active assistant run when sending a new prompt', function () {
    Bus::fake();
    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    session(['task_assistant.current_thread_id' => $thread->id]);

    $previousAssistant = $thread->messages()->create([
        'role' => \App\Enums\MessageRole::Assistant,
        'content' => '',
        'metadata' => [],
    ]);

    Livewire::test('assistant.chat-flyout')
        ->set('newMessage', 'Start new prompt')
        ->call('submitMessage');

    $previousAssistant->refresh();
    expect(data_get($previousAssistant->metadata, 'stream.status'))->toBe('stopped');
});

test('chat flyout renders prioritize next option chips only for latest assistant message', function (): void {
    Bus::fake();

    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    session(['task_assistant.current_thread_id' => $thread->id]);

    $thread->messages()->create([
        'role' => \App\Enums\MessageRole::User,
        'content' => 'What should I do next?',
    ]);

    $thread->messages()->create([
        'role' => \App\Enums\MessageRole::Assistant,
        'content' => 'Older assistant content',
        'metadata' => [
            'prioritize' => [
                'next_options_chip_texts' => [
                    'Old chip one',
                    'Old chip two',
                ],
            ],
        ],
    ]);

    $thread->messages()->create([
        'role' => \App\Enums\MessageRole::Assistant,
        'content' => '',
        'metadata' => [
            'prioritize' => [
                'next_options_chip_texts' => [
                    'Schedule these',
                    'Show next 3',
                ],
            ],
        ],
    ]);

    Livewire::test('assistant.chat-flyout')
        ->assertSet('isStreaming', false)
        ->assertSee('Schedule these')
        ->assertSee('Show next 3')
        ->assertDontSee('Old chip one')
        ->assertDontSee('Old chip two');

    Bus::assertNotDispatched(BroadcastTaskAssistantStreamJob::class);
});

test('chat flyout chip click auto-submits next option and dispatches job', function (): void {
    Bus::fake();

    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    session(['task_assistant.current_thread_id' => $thread->id]);

    $thread->messages()->create([
        'role' => \App\Enums\MessageRole::User,
        'content' => 'What should I do first?',
    ]);

    $assistant = $thread->messages()->create([
        'role' => \App\Enums\MessageRole::Assistant,
        'content' => 'If you want, I can schedule this for later, or show your next 3 priorities.',
        'metadata' => [
            'prioritize' => [
                'next_options_chip_texts' => [
                    'Show next 3',
                ],
            ],
        ],
    ]);

    Livewire::test('assistant.chat-flyout')
        ->assertSet('isStreaming', false)
        ->assertSee('Show next 3')
        ->call('submitNextOptionChip', $assistant->id, 0)
        ->assertSet('newMessage', '')
        ->assertSet('isStreaming', true);

    Bus::assertDispatched(BroadcastTaskAssistantStreamJob::class, function (BroadcastTaskAssistantStreamJob $job) use ($user) {
        return $job->userId === $user->id;
    });
});

test('chat flyout new chat stops active processing run before switching thread', function () {
    Bus::fake();
    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    $component = Livewire::test('assistant.chat-flyout')
        ->set('newMessage', 'Active run message')
        ->call('submitMessage')
        ->assertSet('isStreaming', true);

    $oldThreadId = (int) data_get($component->get('thread'), 'id', 0);
    $oldThread = TaskAssistantThread::query()->findOrFail($oldThreadId);
    $activeAssistant = $oldThread->messages()
        ->where('role', \App\Enums\MessageRole::Assistant)
        ->latest('id')
        ->first();
    expect($activeAssistant)->not->toBeNull();

    $component
        ->call('startNewChat')
        ->assertSet('isStreaming', false);

    $oldThread->refresh();
    if ($activeAssistant instanceof \App\Models\TaskAssistantMessage) {
        $activeAssistant->refresh();
    }

    expect(data_get($activeAssistant?->metadata, 'stream.status'))->toBe('stopped');
    expect(data_get($oldThread->metadata, 'stream.processing'))->toBeNull();
});
