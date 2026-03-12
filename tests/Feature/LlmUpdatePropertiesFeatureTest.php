<?php

use App\Actions\Llm\ApplyAssistantEventRecommendationAction;
use App\Actions\Llm\ApplyAssistantProjectRecommendationAction;
use App\Actions\Llm\ApplyAssistantTaskRecommendationAction;
use App\Actions\Llm\ApplyAssistantTasksRecommendationAction;
use App\Actions\Llm\ApplyTaskPropertiesRecommendationAction;
use App\DataTransferObjects\Llm\TaskUpdatePropertiesRecommendationDto;
use App\Enums\LlmIntent;
use App\Models\AssistantThread;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

it('applies task properties recommendation via ApplyTaskPropertiesRecommendationAction', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    actingAs($user);

    /** @var Task $task */
    $task = Task::factory()->for($user)->create([
        'title' => 'Original title',
        'priority' => \App\Enums\TaskPriority::Medium,
        'duration' => 60,
    ]);

    $structured = [
        'reasoning' => 'Because you said this task is too heavy, lowering priority and duration will make it more manageable.',
        'confidence' => 0.9,
        'properties' => [
            'priority' => 'low',
            'duration' => 30,
        ],
    ];

    $dto = TaskUpdatePropertiesRecommendationDto::fromStructured($structured);
    expect($dto)->not->toBeNull();

    /** @var ApplyTaskPropertiesRecommendationAction $action */
    $action = app(ApplyTaskPropertiesRecommendationAction::class);

    $action->execute($user, $task, $dto, LlmIntent::UpdateTaskProperties, 'accept');

    $task->refresh();

    expect($task->priority?->value)->toBe('low')
        ->and($task->duration)->toBe(30);
});

it('applies schedule task recommendation via unified properties pipeline', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    actingAs($user);

    /** @var Task $task */
    $task = Task::factory()->for($user)->create([
        'title' => 'Scheduled task',
        'duration' => 60,
        'end_datetime' => now()->copy()->addDay(),
    ]);

    $properties = [
        'startDatetime' => now()->copy()->addHour()->toIso8601String(),
        'duration' => 120,
        'priority' => 'high',
    ];

    $snapshot = [
        'intent' => LlmIntent::ScheduleTask->value,
        'reasoning' => 'Because you have free time this afternoon, this slot is a good fit.',
        'validation_confidence' => 0.9,
        'structured' => [
            'entity_type' => 'task',
            'recommended_action' => 'Schedule this task for this afternoon.',
            'reasoning' => 'Because you have free time this afternoon...',
            'validation_confidence' => 0.9,
        ],
        'appliable_changes' => [
            'entity_type' => 'task',
            'properties' => $properties,
        ],
    ];

    $expectedDue = $task->end_datetime->copy();

    /** @var ApplyAssistantTaskRecommendationAction $action */
    $action = app(ApplyAssistantTaskRecommendationAction::class);

    $action->execute($user, $task, $snapshot, userAction: 'accept');

    $task->refresh();

    expect($task->priority?->value)->toBe('high')
        ->and($task->duration)->toBe(120)
        ->and($task->start_datetime)->not->toBeNull()
        ->and($task->end_datetime)->not->toBeNull()
        ->and($task->end_datetime->eq($expectedDue))->toBeTrue();
});

it('applies schedule_tasks multi update via ApplyAssistantTasksRecommendationAction', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    actingAs($user);

    \Carbon\CarbonImmutable::setTestNow(\Carbon\CarbonImmutable::parse('2026-03-12 18:00:00', config('app.timezone')));

    $t1 = Task::factory()->for($user)->create([
        'title' => 'Multi task 1',
        'duration' => 30,
        'completed_at' => null,
        'status' => 'to_do',
        'end_datetime' => now()->copy()->addDay(),
    ]);
    $t2 = Task::factory()->for($user)->create([
        'title' => 'Multi task 2',
        'duration' => 45,
        'completed_at' => null,
        'status' => 'to_do',
        'end_datetime' => now()->copy()->addDays(2),
    ]);

    $snapshot = [
        'intent' => LlmIntent::ScheduleTasks->value,
        'entity_type' => 'multiple',
        'reasoning' => 'Deterministic schedule.',
        'validation_confidence' => 0.9,
        'structured' => [
            'entity_type' => 'task',
            'recommended_action' => 'Plan.',
            'reasoning' => 'Because.',
            'scheduled_tasks' => [
                [
                    'id' => $t1->id,
                    'title' => $t1->title,
                    'start_datetime' => '2026-03-12T19:00:00+08:00',
                    'duration' => 30,
                ],
                [
                    'id' => $t2->id,
                    'title' => $t2->title,
                    'start_datetime' => '2026-03-12T20:00:00+08:00',
                    'duration' => 45,
                ],
            ],
        ],
    ];

    /** @var ApplyAssistantTasksRecommendationAction $action */
    $action = app(ApplyAssistantTasksRecommendationAction::class);

    $didUpdate = $action->execute($user, $snapshot, 'accept');
    expect($didUpdate)->toBeTrue();

    $t1->refresh();
    $t2->refresh();

    expect($t1->start_datetime?->toIso8601String())->toContain('2026-03-12T19:00:00')
        ->and($t1->duration)->toBe(30)
        ->and($t2->start_datetime?->toIso8601String())->toContain('2026-03-12T20:00:00')
        ->and($t2->duration)->toBe(45);
});

it('applies schedule task recommendation even when task due date is already overdue', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    actingAs($user);

    /** @var Task $task */
    $task = Task::factory()->for($user)->create([
        'title' => 'Overdue task',
        'duration' => 30,
        'start_datetime' => null,
        'end_datetime' => now()->copy()->subWeeks(2), // overdue due date
    ]);

    $startTime = now()->copy()->setTime(19, 0)->addDay();
    $snapshot = [
        'intent' => LlmIntent::ScheduleTask->value,
        'entity_type' => 'task',
        'reasoning' => 'Even though it is overdue, you can still schedule time to work on it.',
        'validation_confidence' => 0.9,
        'structured' => [
            'id' => $task->id,
            'title' => $task->title,
            'start_datetime' => $startTime->toIso8601String(),
            'duration' => 60,
        ],
        'appliable_changes' => [
            'entity_type' => 'task',
            'properties' => [
                'startDatetime' => $startTime->toIso8601String(),
                'duration' => 60,
            ],
        ],
    ];

    /** @var ApplyAssistantTaskRecommendationAction $action */
    $action = app(ApplyAssistantTaskRecommendationAction::class);

    $action->execute($user, $task, $snapshot, userAction: 'accept');

    $task->refresh();

    expect($task->start_datetime)->not->toBeNull()
        ->and($task->start_datetime->toIso8601String())->toContain($startTime->format('Y-m-d'))
        ->and($task->duration)->toBe(60);
});

it('applies schedule task when reasoning is only at snapshot top level (RecommendationDisplayDto shape)', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    actingAs($user);

    /** @var Task $task */
    $task = Task::factory()->for($user)->create([
        'title' => 'Your Antas/Teorya ng wika',
        'duration' => null,
        'start_datetime' => null,
        'end_datetime' => now()->copy()->addDays(10),
    ]);

    $snapshot = [
        'intent' => LlmIntent::ScheduleTask->value,
        'entity_type' => 'task',
        'reasoning' => 'Since you asked to schedule your top task for tomorrow, I chose this task. It fits well in your availability.',
        'validation_confidence' => 0.95,
        'structured' => [
            'start_datetime' => now()->copy()->addDay()->setTime(9, 0)->toIso8601String(),
            'duration' => 60,
        ],
        'appliable_changes' => [
            'entity_type' => 'task',
            'properties' => [
                'startDatetime' => now()->copy()->addDay()->setTime(9, 0)->toIso8601String(),
                'duration' => 60,
            ],
        ],
    ];

    /** @var ApplyAssistantTaskRecommendationAction $action */
    $action = app(ApplyAssistantTaskRecommendationAction::class);

    $action->execute($user, $task, $snapshot, userAction: 'accept');

    $task->refresh();

    expect($task->start_datetime)->not->toBeNull()
        ->and($task->start_datetime->format('H:i'))->toBe('09:00')
        ->and($task->duration)->toBe(60);
});

it('applies schedule task when appliable_changes uses snake_case property keys', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    actingAs($user);

    $startTime = now()->copy()->addHours(2)->setMinute(0)->setSecond(0);
    /** @var Task $task */
    $task = Task::factory()->for($user)->create([
        'title' => 'Task with snake_case payload',
        'duration' => null,
        'start_datetime' => null,
        'end_datetime' => now()->copy()->addDays(5),
    ]);

    $snapshot = [
        'intent' => LlmIntent::ScheduleTask->value,
        'reasoning' => 'Suggested time fits your calendar.',
        'validation_confidence' => 0.9,
        'structured' => [],
        'appliable_changes' => [
            'entity_type' => 'task',
            'properties' => [
                'start_datetime' => $startTime->toIso8601String(),
                'duration' => 45,
            ],
        ],
    ];

    /** @var ApplyAssistantTaskRecommendationAction $action */
    $action = app(ApplyAssistantTaskRecommendationAction::class);

    $action->execute($user, $task, $snapshot, userAction: 'accept');

    $task->refresh();

    expect($task->start_datetime)->not->toBeNull()
        ->and($task->start_datetime->format('H:i'))->toBe($startTime->format('H:i'))
        ->and($task->duration)->toBe(45);
});

it('applies schedule to task identified by target_task_id when user clicks Apply', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    actingAs($user);

    $taskAntas = Task::factory()->for($user)->create([
        'title' => 'Antas/Teorya ng wika',
        'duration' => null,
        'start_datetime' => null,
        'end_datetime' => now()->addDays(7),
    ]);
    $taskOther = Task::factory()->for($user)->create([
        'title' => 'Output # 1: My Light to the Society  - Due',
        'duration' => 30,
        'start_datetime' => null,
        'end_datetime' => now()->addDays(14),
    ]);

    $thread = AssistantThread::factory()->create(['user_id' => $user->id]);
    $startTime = now()->copy()->addHours(2)->setMinute(0)->setSecond(0);
    $assistantMessage = $thread->messages()->create([
        'role' => 'assistant',
        'content' => 'Work on Antas/Teorya ng wika this evening.',
        'metadata' => [
            'recommendation_snapshot' => [
                'intent' => LlmIntent::ScheduleTask->value,
                'entity_type' => 'task',
                'reasoning' => 'Your evening is free.',
                'structured' => [
                    'target_task_id' => $taskAntas->id,
                    'target_task_title' => $taskAntas->title,
                ],
                'appliable_changes' => [
                    'entity_type' => 'task',
                    'properties' => [
                        'startDatetime' => $startTime->toIso8601String(),
                        'duration' => 60,
                    ],
                ],
            ],
        ],
    ]);

    Livewire::test('assistant.chat-flyout', ['threadId' => $thread->id])
        ->call('acceptRecommendation', $assistantMessage->id);

    $taskAntas->refresh();
    $taskOther->refresh();

    expect($taskAntas->start_datetime)->not->toBeNull()
        ->and($taskAntas->start_datetime->format('H:i'))->toBe($startTime->format('H:i'))
        ->and($taskAntas->duration)->toBe(60)
        ->and($taskOther->start_datetime)->toBeNull()
        ->and($taskOther->duration)->toBe(30);
});

it('applies suggested schedule when user accepts even if time is in the past', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    actingAs($user);

    /** @var Task $task */
    $task = Task::factory()->for($user)->create([
        'title' => 'Past time task',
        'start_datetime' => null,
        'end_datetime' => now()->copy()->addWeek(),
    ]);

    $pastTime = now()->copy()->subHour()->toIso8601String();
    $snapshot = [
        'intent' => LlmIntent::ScheduleTask->value,
        'entity_type' => 'task',
        'reasoning' => 'Suggested earlier.',
        'validation_confidence' => 0.9,
        'structured' => [],
        'appliable_changes' => [
            'entity_type' => 'task',
            'properties' => [
                'startDatetime' => $pastTime,
                'duration' => 60,
            ],
        ],
    ];

    /** @var ApplyAssistantTaskRecommendationAction $action */
    $action = app(ApplyAssistantTaskRecommendationAction::class);

    $action->execute($user, $task, $snapshot, userAction: 'accept');

    $task->refresh();
    expect($task->start_datetime)->not->toBeNull()
        ->and($task->start_datetime->toIso8601String())->toBe($pastTime)
        ->and($task->duration)->toBe(60);
});

it('does not apply readonly multi-entity schedule intent even if snapshot includes appliable changes', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    actingAs($user);

    /** @var Task $task */
    $task = Task::factory()->for($user)->create([
        'title' => 'Should remain unchanged',
        'start_datetime' => null,
        'duration' => 30,
        'end_datetime' => now()->copy()->addDays(2),
    ]);

    $thread = AssistantThread::factory()->create(['user_id' => $user->id]);
    $startTime = now()->copy()->addHours(3)->setMinute(0)->setSecond(0);

    $assistantMessage = $thread->messages()->create([
        'role' => 'assistant',
        'content' => 'Draft schedule across tasks and events.',
        'metadata' => [
            'recommendation_snapshot' => [
                'intent' => LlmIntent::ScheduleTasksAndEvents->value,
                'entity_type' => 'multiple',
                'structured' => [
                    'target_task_id' => $task->id,
                    'target_task_title' => $task->title,
                ],
                'appliable_changes' => [
                    'entity_type' => 'task',
                    'target_task_id' => $task->id,
                    'properties' => [
                        'startDatetime' => $startTime->toIso8601String(),
                        'duration' => 90,
                    ],
                ],
            ],
        ],
    ]);

    Livewire::test('assistant.chat-flyout', ['threadId' => $thread->id])
        ->call('acceptRecommendation', $assistantMessage->id);

    $task->refresh();

    expect($task->start_datetime)->toBeNull()
        ->and($task->duration)->toBe(30);
});

it('applies update event properties intent through event apply action', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    actingAs($user);

    /** @var Event $event */
    $event = Event::factory()->for($user)->create([
        'title' => 'Old event title',
        'all_day' => false,
    ]);

    $snapshot = [
        'intent' => LlmIntent::UpdateEventProperties->value,
        'reasoning' => 'This title and all-day setting match your request.',
        'validation_confidence' => 0.92,
        'structured' => [
            'entity_type' => 'event',
            'recommended_action' => 'Update this event title and mark it all-day.',
            'reasoning' => 'This title and all-day setting match your request.',
            'properties' => [
                'title' => 'Updated event title',
                'allDay' => true,
            ],
        ],
        'appliable_changes' => [
            'entity_type' => 'event',
            'properties' => [
                'title' => 'Updated event title',
                'allDay' => true,
            ],
        ],
    ];

    /** @var ApplyAssistantEventRecommendationAction $action */
    $action = app(ApplyAssistantEventRecommendationAction::class);

    $didUpdate = $action->execute($user, $event, $snapshot, userAction: 'accept');

    $event->refresh();

    expect($didUpdate)->toBeTrue()
        ->and($event->title)->toBe('Updated event title')
        ->and((bool) $event->all_day)->toBeTrue();
});

it('applies update project properties intent through project apply action', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    actingAs($user);

    /** @var Project $project */
    $project = Project::factory()->for($user)->create([
        'name' => 'Old project name',
        'description' => 'Old description',
    ]);

    $snapshot = [
        'intent' => LlmIntent::UpdateProjectProperties->value,
        'reasoning' => 'Renaming helps match your new scope.',
        'validation_confidence' => 0.9,
        'structured' => [
            'entity_type' => 'project',
            'recommended_action' => 'Update the project name and description.',
            'reasoning' => 'Renaming helps match your new scope.',
            'properties' => [
                'name' => 'Updated project name',
                'description' => 'Updated description',
            ],
        ],
        'appliable_changes' => [
            'entity_type' => 'project',
            'properties' => [
                'name' => 'Updated project name',
                'description' => 'Updated description',
            ],
        ],
    ];

    /** @var ApplyAssistantProjectRecommendationAction $action */
    $action = app(ApplyAssistantProjectRecommendationAction::class);

    $didUpdate = $action->execute($user, $project, $snapshot, userAction: 'accept');

    $project->refresh();

    expect($didUpdate)->toBeTrue()
        ->and($project->name)->toBe('Updated project name')
        ->and($project->description)->toBe('Updated description');
});
