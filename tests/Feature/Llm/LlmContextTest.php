<?php

use App\Actions\Llm\BuildLlmContextAction;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\Event;
use App\Models\Project;
use App\Models\RecurringEvent;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->action = app(BuildLlmContextAction::class);
});

test('build context for prioritize_tasks includes current_time and tasks array', function (): void {
    Task::factory()->for($this->user)->count(2)->create([
        'title' => 'Task A',
        'completed_at' => null,
        'status' => 'to_do',
    ]);

    $context = $this->action->execute(
        $this->user,
        LlmIntent::PrioritizeTasks,
        LlmEntityType::Task,
        null,
        null
    );

    expect($context)->toHaveKeys(['current_time', 'tasks', 'conversation_history'])
        ->and($context['tasks'])->toBeArray()
        ->and($context['conversation_history'])->toBeArray();
});

test('task context includes is_recurring and minimal fields', function (): void {
    Task::factory()->for($this->user)->create([
        'title' => 'Recurring task',
        'completed_at' => null,
        'status' => 'to_do',
    ]);

    $context = $this->action->execute(
        $this->user,
        LlmIntent::ScheduleTask,
        LlmEntityType::Task,
        null,
        null
    );

    expect($context['tasks'])->toHaveCount(1)
        ->and($context['tasks'][0])->toHaveKeys(['id', 'title', 'is_recurring', 'end_datetime', 'priority'])
        ->and($context['tasks'][0]['title'])->toBe('Recurring task')
        ->and($context['tasks'][0])->toHaveKey('is_recurring');
});

test('build context for prioritize_events includes events array', function (): void {
    Event::factory()->for($this->user)->count(2)->create([
        'status' => 'scheduled',
    ]);

    $context = $this->action->execute(
        $this->user,
        LlmIntent::PrioritizeEvents,
        LlmEntityType::Event,
        null,
        null
    );

    expect($context)->toHaveKeys(['current_time', 'events', 'conversation_history'])
        ->and($context['events'])->toBeArray()
        ->and($context['events'][0])->toHaveKeys(['id', 'title', 'is_recurring', 'start_datetime']);
});

test('build context for prioritize_projects includes projects with tasks', function (): void {
    $project = Project::factory()->for($this->user)->create(['name' => 'My project']);
    Task::factory()->for($this->user)->for($project)->create(['title' => 'Project task', 'completed_at' => null]);

    $context = $this->action->execute(
        $this->user,
        LlmIntent::PrioritizeProjects,
        LlmEntityType::Project,
        null,
        null
    );

    expect($context)->toHaveKeys(['current_time', 'projects', 'conversation_history'])
        ->and($context['projects'])->toHaveCount(1)
        ->and($context['projects'][0]['name'])->toBe('My project')
        ->and($context['projects'][0]['tasks'])->toBeArray();
});

test('general_query with entity task includes tasks so LLM is aware of user items', function (): void {
    Task::factory()->for($this->user)->create([
        'title' => 'Overview task',
        'completed_at' => null,
        'status' => 'to_do',
    ]);

    $context = $this->action->execute(
        $this->user,
        LlmIntent::GeneralQuery,
        LlmEntityType::Task,
        null,
        null
    );

    expect($context)->toHaveKeys(['current_time', 'tasks', 'conversation_history'])
        ->and($context['tasks'])->toBeArray()
        ->and($context['tasks'])->toHaveCount(1)
        ->and($context['tasks'][0]['title'])->toBe('Overview task');
});

test('context includes conversation history when thread provided', function (): void {
    $thread = AssistantThread::factory()->for($this->user)->create();
    AssistantMessage::factory()->for($thread)->user()->create(['content' => 'Hello']);
    AssistantMessage::factory()->for($thread)->assistant()->create(['content' => 'Hi there']);

    $context = $this->action->execute(
        $this->user,
        LlmIntent::PrioritizeTasks,
        LlmEntityType::Task,
        null,
        $thread,
        null
    );

    expect($context['conversation_history'])->toHaveCount(2)
        ->and($context['conversation_history'][0])->toEqual(['role' => 'user', 'content' => 'Hello'])
        ->and($context['conversation_history'][1])->toEqual(['role' => 'assistant', 'content' => 'Hi there']);
});

test('conversation history excludes current user message to avoid duplication', function (): void {
    $thread = AssistantThread::factory()->for($this->user)->create();
    AssistantMessage::factory()->for($thread)->user()->create(['content' => 'First message']);
    AssistantMessage::factory()->for($thread)->assistant()->create(['content' => 'First reply']);
    AssistantMessage::factory()->for($thread)->user()->create(['content' => 'Schedule those tasks']);

    $context = $this->action->execute(
        $this->user,
        LlmIntent::PrioritizeTasks,
        LlmEntityType::Task,
        null,
        $thread,
        'Schedule those tasks'
    );

    expect($context['conversation_history'])->toHaveCount(2)
        ->and($context['conversation_history'][0])->toEqual(['role' => 'user', 'content' => 'First message'])
        ->and($context['conversation_history'][1])->toEqual(['role' => 'assistant', 'content' => 'First reply']);
});

test('prioritize_tasks context scopes to previous list when user references those items', function (): void {
    Task::factory()->for($this->user)->create(['title' => 'Fix stuff', 'status' => 'to_do', 'completed_at' => null]);
    Task::factory()->for($this->user)->create(['title' => 'Send email', 'status' => 'to_do', 'completed_at' => null]);
    Task::factory()->for($this->user)->create(['title' => 'Submit proposal', 'status' => 'to_do', 'completed_at' => null]);

    $thread = AssistantThread::factory()->for($this->user)->create();
    AssistantMessage::factory()->for($thread)->user()->create(['content' => 'list tasks that are recurring']);
    AssistantMessage::factory()->for($thread)->assistant()->withMetadata([
        'recommendation_snapshot' => [
            'structured' => [
                'listed_items' => [
                    ['title' => 'Fix stuff'],
                    ['title' => 'Send email'],
                ],
            ],
        ],
    ])->create(['content' => 'Here are your recurring tasks.', 'role' => 'assistant']);

    $context = $this->action->execute(
        $this->user,
        LlmIntent::PrioritizeTasks,
        LlmEntityType::Task,
        null,
        $thread,
        'in those 2 what should i do first'
    );

    $titles = collect($context['tasks'])->pluck('title')->values()->all();
    expect($context['tasks'])->toHaveCount(2)
        ->and($titles)->toContain('Fix stuff')
        ->and($titles)->toContain('Send email')
        ->and($titles)->not->toContain('Submit proposal');
});

test('schedule_task context scopes to previous list when user says schedule that task', function (): void {
    Task::factory()->for($this->user)->create(['title' => 'Incomplete task 1', 'status' => 'to_do', 'completed_at' => null, 'start_datetime' => null, 'end_datetime' => null]);
    Task::factory()->for($this->user)->create(['title' => 'Other task', 'status' => 'to_do', 'completed_at' => null]);

    $thread = AssistantThread::factory()->for($this->user)->create();
    AssistantMessage::factory()->for($thread)->user()->create(['content' => 'tasks that have no dates']);
    AssistantMessage::factory()->for($thread)->assistant()->withMetadata([
        'recommendation_snapshot' => [
            'structured' => [
                'listed_items' => [['title' => 'Incomplete task 1']],
            ],
        ],
    ])->create(['content' => 'Here are your tasks with no set dates.', 'role' => 'assistant']);

    $context = $this->action->execute(
        $this->user,
        LlmIntent::ScheduleTask,
        LlmEntityType::Task,
        null,
        $thread,
        'schedule that task, suggest a time where i can do it'
    );

    expect($context['tasks'])->toHaveCount(1)
        ->and($context['tasks'][0]['title'])->toBe('Incomplete task 1');
});

test('general_query task context scopes to previous list when user says about those', function (): void {
    Task::factory()->for($this->user)->create(['title' => 'Task A', 'status' => 'to_do', 'completed_at' => null]);
    Task::factory()->for($this->user)->create(['title' => 'Task B', 'status' => 'to_do', 'completed_at' => null]);
    Task::factory()->for($this->user)->create(['title' => 'Other task', 'status' => 'to_do', 'completed_at' => null]);

    $thread = AssistantThread::factory()->for($this->user)->create();
    AssistantMessage::factory()->for($thread)->user()->create(['content' => 'list low priority tasks']);
    AssistantMessage::factory()->for($thread)->assistant()->withMetadata([
        'recommendation_snapshot' => [
            'structured' => [
                'listed_items' => [
                    ['title' => 'Task A'],
                    ['title' => 'Task B'],
                ],
            ],
        ],
    ])->create(['content' => 'Here are your low-priority tasks.', 'role' => 'assistant']);

    $context = $this->action->execute(
        $this->user,
        LlmIntent::GeneralQuery,
        LlmEntityType::Task,
        null,
        $thread,
        'tell me more about those'
    );

    $titles = collect($context['tasks'])->pluck('title')->values()->all();
    expect($context['tasks'])->toHaveCount(2)
        ->and($titles)->toContain('Task A')
        ->and($titles)->toContain('Task B')
        ->and($titles)->not->toContain('Other task');
});

test('schedule_event context scopes to previous list when user says schedule that event', function (): void {
    Event::factory()->for($this->user)->create(['title' => 'Doctor checkup', 'status' => 'scheduled', 'start_datetime' => null, 'end_datetime' => null]);
    Event::factory()->for($this->user)->create(['title' => 'Lunch with Mom', 'status' => 'scheduled']);

    $thread = AssistantThread::factory()->for($this->user)->create();
    AssistantMessage::factory()->for($thread)->user()->create(['content' => 'what events that has no set dates']);
    AssistantMessage::factory()->for($thread)->assistant()->withMetadata([
        'recommendation_snapshot' => [
            'structured' => [
                'listed_items' => [['title' => 'Doctor checkup']],
            ],
        ],
    ])->create(['content' => 'Here are your events with no set dates.', 'role' => 'assistant']);

    $context = $this->action->execute(
        $this->user,
        LlmIntent::ScheduleEvent,
        LlmEntityType::Event,
        null,
        $thread,
        'schedule that event for me, suggest a time'
    );

    expect($context['events'])->toHaveCount(1)
        ->and($context['events'][0]['title'])->toBe('Doctor checkup');
});

test('task context marks is_recurring true when recurringTask exists', function (): void {
    $task = Task::factory()->for($this->user)->create([
        'title' => 'Recurring task',
        'completed_at' => null,
        'status' => 'to_do',
    ]);
    RecurringTask::factory()->for($task)->create();

    $context = $this->action->execute(
        $this->user,
        LlmIntent::PrioritizeTasks,
        LlmEntityType::Task,
        null,
        null
    );

    expect($context['tasks'][0]['id'])->toBe($task->id)
        ->and($context['tasks'][0]['is_recurring'])->toBeTrue();
});

test('event context marks is_recurring true when recurringEvent exists', function (): void {
    $event = Event::factory()->for($this->user)->create([
        'title' => 'Recurring event',
        'status' => 'scheduled',
    ]);
    RecurringEvent::factory()->for($event)->create();

    $context = $this->action->execute(
        $this->user,
        LlmIntent::PrioritizeEvents,
        LlmEntityType::Event,
        null,
        null
    );

    expect($context['events'][0]['id'])->toBe($event->id)
        ->and($context['events'][0]['is_recurring'])->toBeTrue();
});

test('resolve_dependency context includes tasks and events', function (): void {
    Task::factory()->for($this->user)->create(['completed_at' => null]);
    Event::factory()->for($this->user)->create(['status' => 'scheduled']);

    $context = $this->action->execute(
        $this->user,
        LlmIntent::ResolveDependency,
        LlmEntityType::Task,
        null,
        null
    );

    expect($context)->toHaveKeys(['current_time', 'tasks', 'events', 'conversation_history'])
        ->and($context['tasks'])->toBeArray()
        ->and($context['events'])->toBeArray();
});

test('resolve_dependency context scopes to previous list when user says for those', function (): void {
    Task::factory()->for($this->user)->create(['title' => 'Blocked task A', 'status' => 'to_do', 'completed_at' => null]);
    Task::factory()->for($this->user)->create(['title' => 'Other task', 'status' => 'to_do', 'completed_at' => null]);
    Event::factory()->for($this->user)->create(['title' => 'Blocked event', 'status' => 'scheduled']);

    $thread = AssistantThread::factory()->for($this->user)->create();
    AssistantMessage::factory()->for($thread)->user()->create(['content' => 'list blocked items']);
    AssistantMessage::factory()->for($thread)->assistant()->withMetadata([
        'recommendation_snapshot' => [
            'structured' => [
                'listed_items' => [
                    ['title' => 'Blocked task A'],
                    ['title' => 'Blocked event'],
                ],
            ],
        ],
    ])->create(['content' => 'Here are your blocked items.', 'role' => 'assistant']);

    $context = $this->action->execute(
        $this->user,
        LlmIntent::ResolveDependency,
        LlmEntityType::Task,
        null,
        $thread,
        'resolve dependencies for those'
    );

    $taskTitles = collect($context['tasks'])->pluck('title')->values()->all();
    $eventTitles = collect($context['events'])->pluck('title')->values()->all();
    expect($context['tasks'])->toHaveCount(1)
        ->and($taskTitles)->toContain('Blocked task A')
        ->and($taskTitles)->not->toContain('Other task')
        ->and($context['events'])->toHaveCount(1)
        ->and($eventTitles)->toContain('Blocked event');
});

test('context token cap trims conversation history first', function (): void {
    config()->set('tasklyst.context.max_tokens', 25);

    $thread = AssistantThread::factory()->for($this->user)->create();

    AssistantMessage::factory()->for($thread)->user()->create(['content' => str_repeat('U', 500)]);
    AssistantMessage::factory()->for($thread)->assistant()->create(['content' => str_repeat('A', 500)]);
    AssistantMessage::factory()->for($thread)->user()->create(['content' => str_repeat('U', 500)]);
    AssistantMessage::factory()->for($thread)->assistant()->create(['content' => str_repeat('A', 500)]);

    $context = $this->action->execute(
        $this->user,
        LlmIntent::GeneralQuery,
        LlmEntityType::Task,
        null,
        $thread
    );

    expect($context)->toHaveKey('conversation_history')
        ->and($context['conversation_history'])->toBeArray()
        ->and(count($context['conversation_history']))->toBeLessThan(4);
});

test('context respects entity_id filter for single task', function (): void {
    $t1 = Task::factory()->for($this->user)->create([
        'title' => 'First',
        'completed_at' => null,
        'status' => 'to_do',
    ]);
    Task::factory()->for($this->user)->create([
        'title' => 'Second',
        'completed_at' => null,
        'status' => 'to_do',
    ]);

    $context = $this->action->execute(
        $this->user,
        LlmIntent::AdjustTaskDeadline,
        LlmEntityType::Task,
        $t1->id,
        null
    );

    expect($context['tasks'])->toHaveCount(1)
        ->and($context['tasks'][0]['id'])->toBe($t1->id)
        ->and($context['tasks'][0]['title'])->toBe('First');
});

test('prioritize_tasks task context does not include complexity or project_id', function (): void {
    Task::factory()->for($this->user)->create([
        'title' => 'Rank me',
        'completed_at' => null,
        'status' => 'to_do',
    ]);

    $context = $this->action->execute(
        $this->user,
        LlmIntent::PrioritizeTasks,
        LlmEntityType::Task,
        null,
        null
    );

    $task = $context['tasks'][0];
    expect($task)->toHaveKeys(['id', 'title', 'end_datetime', 'priority', 'is_recurring', 'status'])
        ->and($task)->not->toHaveKey('project_id')
        ->and($task)->not->toHaveKey('description');
});

test('general_query task context includes full set with description and complexity', function (): void {
    Task::factory()->for($this->user)->create([
        'title' => 'Full task',
        'description' => 'Some details',
        'completed_at' => null,
        'status' => 'to_do',
    ]);

    $context = $this->action->execute(
        $this->user,
        LlmIntent::GeneralQuery,
        LlmEntityType::Task,
        null,
        null
    );

    $task = $context['tasks'][0];
    expect($task)->toHaveKeys(['id', 'title', 'description', 'complexity', 'duration', 'end_datetime', 'priority', 'is_recurring'])
        ->and($task['title'])->toBe('Full task');
});

test('schedule_task task context includes duration priority description end_datetime', function (): void {
    Task::factory()->for($this->user)->create([
        'title' => 'Schedule me',
        'description' => 'Blockers here',
        'completed_at' => null,
        'status' => 'to_do',
    ]);

    $context = $this->action->execute(
        $this->user,
        LlmIntent::ScheduleTask,
        LlmEntityType::Task,
        null,
        null
    );

    $task = $context['tasks'][0];
    expect($task)->toHaveKeys(['id', 'title', 'description', 'duration', 'priority', 'end_datetime', 'is_recurring'])
        ->and($task)->not->toHaveKey('complexity');
});

test('prioritize_events event context does not include description or status', function (): void {
    Event::factory()->for($this->user)->create([
        'title' => 'Event to rank',
        'status' => 'scheduled',
    ]);

    $context = $this->action->execute(
        $this->user,
        LlmIntent::PrioritizeEvents,
        LlmEntityType::Event,
        null,
        null
    );

    $event = $context['events'][0];
    expect($event)->toHaveKeys([
        'id',
        'title',
        'start_datetime',
        'end_datetime',
        'is_recurring',
        'status',
        'all_day',
        'starts_within_24h',
        'starts_within_7_days',
    ])->and($event)->not->toHaveKey('description');
});

test('general_query event context includes description and status', function (): void {
    Event::factory()->for($this->user)->create([
        'title' => 'Full event',
        'description' => 'Event details',
        'status' => 'scheduled',
    ]);

    $context = $this->action->execute(
        $this->user,
        LlmIntent::GeneralQuery,
        LlmEntityType::Event,
        null,
        null
    );

    $event = $context['events'][0];
    expect($event)->toHaveKeys(['id', 'title', 'description', 'start_datetime', 'end_datetime', 'status', 'is_recurring']);
});
