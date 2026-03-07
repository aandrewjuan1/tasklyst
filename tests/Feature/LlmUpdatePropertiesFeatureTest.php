<?php

use App\Actions\Llm\ApplyAssistantTaskPropertiesRecommendationAction;
use App\Actions\Llm\ApplyAssistantTaskRecommendationAction;
use App\Enums\LlmIntent;
use App\Models\Task;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('applies task properties recommendation via assistant wrapper', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    actingAs($user);

    /** @var Task $task */
    $task = Task::factory()->for($user)->create([
        'title' => 'Original title',
        'priority' => \App\Enums\TaskPriority::Medium,
        'duration' => 60,
    ]);

    $snapshot = [
        'intent' => LlmIntent::UpdateTaskProperties->value,
        'structured' => [
            'entity_type' => 'task',
            'recommended_action' => 'I recommend lowering the priority and shortening the duration.',
            'reasoning' => 'Because you said this task is too heavy, lowering priority and duration will make it more manageable.',
            'confidence' => 0.9,
            'properties' => [
                'priority' => 'low',
                'duration' => 30,
            ],
        ],
    ];

    /** @var ApplyAssistantTaskPropertiesRecommendationAction $action */
    $action = app(ApplyAssistantTaskPropertiesRecommendationAction::class);

    $action->execute($user, $task, $snapshot, userAction: 'accept');

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

it('throws when suggested schedule time is in the past', function (): void {
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
})->throws(\Illuminate\Validation\ValidationException::class);
