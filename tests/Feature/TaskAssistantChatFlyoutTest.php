<?php

use App\Jobs\BroadcastTaskAssistantStreamJob;
use App\Models\Task;
use App\Models\TaskAssistantThread;
use App\Models\User;
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

test('chat flyout shows loading spinner text while streaming and no reconnect hint', function () {
    Bus::fake();
    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    Livewire::test('assistant.chat-flyout')
        ->set('newMessage', 'Please help me plan my day')
        ->call('submitMessage')
        ->assertSet('isStreaming', true)
        ->assertSee('Thinking...')
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

test('chat flyout submits list message and dispatches job', function () {
    /** @var \Illuminate\Foundation\Testing\TestCase $this */
    Bus::fake();
    $user = User::factory()->create();
    assert($user instanceof User);
    $this->actingAs($user);

    Prism::fake([
        StructuredResponseFake::make()
            ->withStructured([
                'action' => 'list_tasks',
                'args' => ['limit' => 5],
            ])
            ->withUsage(new Usage(5, 10)),
    ]);

    Livewire::test('assistant.chat-flyout')
        ->set('newMessage', 'List my tasks')
        ->call('submitMessage')
        ->assertSet('isStreaming', true);

    Bus::assertDispatched(BroadcastTaskAssistantStreamJob::class);
});

test('chat flyout can decline a schedule proposal item', function () {
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
                    'proposal_id' => 'proposal-1',
                    'status' => 'pending',
                    'entity_type' => 'task',
                    'entity_id' => null,
                    'title' => 'Focus block',
                    'start_datetime' => now()->toIso8601String(),
                    'duration_minutes' => 30,
                ]],
            ],
        ],
    ]);

    Livewire::test('assistant.chat-flyout')
        ->call('declineScheduleProposalItem', $assistantMessage->id, 'proposal-1');

    $assistantMessage->refresh();
    expect(data_get($assistantMessage->metadata, 'daily_schedule.proposals.0.status'))->toBe('declined');
});

test('chat flyout can accept a task schedule proposal item and apply updates', function () {
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
        ->call('acceptScheduleProposalItem', $assistantMessage->id, 'proposal-task-1');

    $assistantMessage->refresh();
    $task->refresh();

    expect(data_get($assistantMessage->metadata, 'daily_schedule.proposals.0.status'))->toBe('accepted');
    expect($task->duration)->toBe(90);
    expect($task->start_datetime?->toIso8601String())->toContain($startAt->format('Y-m-d\TH'));
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
        ->assertSee('Thinking...');
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
        ->assertSee('Stop generation')
        ->call('requestStopStreaming')
        ->assertSet('isStreaming', false)
        ->assertDontSee('Stop generation');

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
        ->assertSee('Stopped');
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

test('chat flyout renders prioritize next option chips and submits schedule intent', function (): void {
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
        'content' => '',
        'metadata' => [
            'prioritize' => [
                'next_options_chip_texts' => [
                    'Schedule these for later',
                    'Schedule these tasks for a specific time',
                ],
            ],
        ],
    ]);

    Livewire::test('assistant.chat-flyout')
        ->assertSee('Schedule these for later')
        ->call('submitSuggestedMessage', 'Schedule these for later')
        ->assertSet('isStreaming', true);

    Bus::assertDispatched(BroadcastTaskAssistantStreamJob::class);
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
    $activeAssistant?->refresh();

    expect(data_get($activeAssistant?->metadata, 'stream.status'))->toBe('stopped');
    expect(data_get($oldThread->metadata, 'stream.processing'))->toBeNull();
});
