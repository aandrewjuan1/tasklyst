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
        return $job->userId === $user->id;
    });
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
                'chosen_task_id' => null,
                'chosen_task_title' => null,
                'summary' => 'Structured task choice summary.',
                'reason' => 'Because it is sensible.',
                'suggested_next_steps' => ['Step 1'],
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
