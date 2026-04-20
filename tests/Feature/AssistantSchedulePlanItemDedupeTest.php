<?php

use App\Enums\MessageRole;
use App\Models\AssistantSchedulePlanItem;
use App\Models\Task;
use App\Models\TaskAssistantThread;
use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;

test('second accept for same entity and calendar day updates existing plan item instead of duplicating', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    $this->actingAs($user);

    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    session(['task_assistant.current_thread_id' => $thread->id]);

    $task = Task::factory()->for($user)->create([
        'title' => 'Dedupe task',
        'status' => \App\Enums\TaskStatus::ToDo,
        'start_datetime' => null,
        'duration' => 30,
    ]);

    $startAt = now()->addDay()->setTime(10, 0);

    $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'First proposal',
        'metadata' => [
            'daily_schedule' => [
                'proposals' => [[
                    'proposal_id' => 'first-proposal',
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
        ->call('acceptAllScheduleProposals', $thread->messages()->latest('id')->first()->id);

    expect(AssistantSchedulePlanItem::query()->where('user_id', $user->id)->active()->count())->toBe(1);
    $firstId = AssistantSchedulePlanItem::query()->where('user_id', $user->id)->active()->first()->id;

    $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Second proposal',
        'metadata' => [
            'daily_schedule' => [
                'proposals' => [[
                    'proposal_id' => 'second-proposal',
                    'status' => 'pending',
                    'entity_type' => 'task',
                    'entity_id' => $task->id,
                    'title' => $task->title,
                    'start_datetime' => $startAt->copy()->addMinutes(30)->toIso8601String(),
                    'duration_minutes' => 45,
                ]],
            ],
        ],
    ]);

    Livewire::test('assistant.chat-flyout')
        ->call('acceptAllScheduleProposals', $thread->messages()->latest('id')->first()->id);

    expect(AssistantSchedulePlanItem::query()->where('user_id', $user->id)->active()->count())->toBe(1);
    $row = AssistantSchedulePlanItem::query()->where('user_id', $user->id)->active()->first();
    expect($row->id)->toBe($firstId);
    expect($row->planned_duration_minutes)->toBe(45);
});

test('accepting proposals on different calendar days supersedes older active item for same entity', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    $this->actingAs($user);

    $thread = TaskAssistantThread::factory()->create(['user_id' => $user->id]);
    session(['task_assistant.current_thread_id' => $thread->id]);

    $task = Task::factory()->for($user)->create([
        'title' => 'Multi-day task',
        'status' => \App\Enums\TaskStatus::ToDo,
        'start_datetime' => null,
        'duration' => 30,
    ]);

    $day1 = Carbon::parse('2030-01-15 09:00:00', config('app.timezone'));
    $day2 = Carbon::parse('2030-01-18 09:00:00', config('app.timezone'));

    $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Day 1',
        'metadata' => [
            'daily_schedule' => [
                'proposals' => [[
                    'proposal_id' => 'proposal-day-1',
                    'status' => 'pending',
                    'entity_type' => 'task',
                    'entity_id' => $task->id,
                    'title' => $task->title,
                    'start_datetime' => $day1->toIso8601String(),
                    'duration_minutes' => 30,
                ]],
            ],
        ],
    ]);

    Livewire::test('assistant.chat-flyout')
        ->call('acceptAllScheduleProposals', $thread->messages()->latest('id')->first()->id);

    $thread->messages()->create([
        'role' => MessageRole::Assistant,
        'content' => 'Day 2',
        'metadata' => [
            'daily_schedule' => [
                'proposals' => [[
                    'proposal_id' => 'proposal-day-2',
                    'status' => 'pending',
                    'entity_type' => 'task',
                    'entity_id' => $task->id,
                    'title' => $task->title,
                    'start_datetime' => $day2->toIso8601String(),
                    'duration_minutes' => 30,
                ]],
            ],
        ],
    ]);

    Livewire::test('assistant.chat-flyout')
        ->call('acceptAllScheduleProposals', $thread->messages()->latest('id')->first()->id);

    expect(AssistantSchedulePlanItem::query()->where('user_id', $user->id)->active()->count())->toBe(1);

    $active = AssistantSchedulePlanItem::query()->where('user_id', $user->id)->active()->first();
    expect($active)->not->toBeNull();
    expect(optional($active->planned_start_at)?->toDateString())->toBe($day2->toDateString());
});
