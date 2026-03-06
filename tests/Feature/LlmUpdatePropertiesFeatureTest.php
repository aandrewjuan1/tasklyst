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
        'endDatetime' => now()->copy()->addHours(2)->toIso8601String(),
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

    /** @var ApplyAssistantTaskRecommendationAction $action */
    $action = app(ApplyAssistantTaskRecommendationAction::class);

    $action->execute($user, $task, $snapshot, userAction: 'accept');

    $task->refresh();

    expect($task->priority?->value)->toBe('high')
        ->and($task->duration)->toBe(60);
}
);
