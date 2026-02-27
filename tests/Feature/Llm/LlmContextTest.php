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

test('general_query returns minimal context with conversation_history', function (): void {
    $context = $this->action->execute(
        $this->user,
        LlmIntent::GeneralQuery,
        LlmEntityType::Task,
        null,
        null
    );

    expect($context)->toHaveKeys(['current_time', 'conversation_history'])
        ->and($context)->not->toHaveKey('tasks');
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
        $thread
    );

    expect($context['conversation_history'])->toHaveCount(2)
        ->and($context['conversation_history'][0])->toEqual(['role' => 'user', 'content' => 'Hello'])
        ->and($context['conversation_history'][1])->toEqual(['role' => 'assistant', 'content' => 'Hi there']);
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
