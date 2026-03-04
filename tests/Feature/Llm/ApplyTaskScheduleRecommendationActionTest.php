<?php

use App\Actions\Llm\ApplyTaskScheduleRecommendationAction;
use App\DataTransferObjects\Llm\TaskScheduleRecommendationDto;
use App\Enums\LlmIntent;
use App\Models\Task;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('applies task schedule recommendation on accept', function (): void {
    $user = User::factory()->create();
    actingAs($user);

    /** @var Task $task */
    $task = Task::factory()->create([
        'user_id' => $user->id,
        'start_datetime' => null,
        'end_datetime' => null,
        'priority' => null,
    ]);

    $start = now()->addDay()->setTime(9, 0)->toImmutable();
    $end = now()->addDay()->setTime(10, 0)->toImmutable();

    $dto = new TaskScheduleRecommendationDto(
        startDatetime: \Illuminate\Support\Carbon::instance($start),
        endDatetime: \Illuminate\Support\Carbon::instance($end),
        durationMinutes: 60,
        priority: 'high',
        reasoning: 'Schedule in the morning to avoid conflicts.',
    );

    $action = app(ApplyTaskScheduleRecommendationAction::class);
    $action->execute(
        user: $user,
        task: $task->refresh(),
        recommendation: $dto,
        intent: LlmIntent::ScheduleTask,
        userAction: 'accept'
    );

    $task->refresh();

    expect((string) $task->priority?->value)->toBe('high');
});

it('does not change task on reject but records audit', function (): void {
    $user = User::factory()->create();
    actingAs($user);

    /** @var Task $task */
    $task = Task::factory()->create([
        'user_id' => $user->id,
        'start_datetime' => null,
        'end_datetime' => null,
        'priority' => null,
    ]);

    $start = now()->addDay()->setTime(9, 0)->toImmutable();
    $end = now()->addDay()->setTime(10, 0)->toImmutable();

    $dto = new TaskScheduleRecommendationDto(
        startDatetime: \Illuminate\Support\Carbon::instance($start),
        endDatetime: \Illuminate\Support\Carbon::instance($end),
        durationMinutes: 60,
        priority: 'high',
        reasoning: 'Schedule in the morning to avoid conflicts.',
    );

    $action = app(ApplyTaskScheduleRecommendationAction::class);
    $action->execute(
        user: $user,
        task: $task->refresh(),
        recommendation: $dto,
        intent: LlmIntent::ScheduleTask,
        userAction: 'reject'
    );

    $task->refresh();

    expect($task->start_datetime)->toBeNull()
        ->and($task->end_datetime)->toBeNull()
        ->and($task->priority)->toBeNull();
});

it('does not apply recommendation that ends in the past', function (): void {
    $user = User::factory()->create();
    actingAs($user);

    /** @var Task $task */
    $task = Task::factory()->create([
        'user_id' => $user->id,
        'start_datetime' => null,
        'end_datetime' => null,
        'priority' => null,
    ]);

    $start = now()->subDay()->setTime(9, 0)->toImmutable();
    $end = now()->subDay()->setTime(10, 0)->toImmutable();

    $dto = new TaskScheduleRecommendationDto(
        startDatetime: \Illuminate\Support\Carbon::instance($start),
        endDatetime: \Illuminate\Support\Carbon::instance($end),
        durationMinutes: 60,
        priority: 'high',
        reasoning: 'Schedule yesterday.',
    );

    $action = app(ApplyTaskScheduleRecommendationAction::class);
    $action->execute(
        user: $user,
        task: $task->refresh(),
        recommendation: $dto,
        intent: LlmIntent::ScheduleTask,
        userAction: 'accept'
    );

    $task->refresh();

    expect($task->start_datetime)->toBeNull()
        ->and($task->end_datetime)->toBeNull()
        ->and($task->priority)->toBeNull();
});

it('does not apply schedule that extends beyond existing due date for schedule_task', function (): void {
    $user = User::factory()->create();
    actingAs($user);

    /** @var Task $task */
    $task = Task::factory()->create([
        'user_id' => $user->id,
        'start_datetime' => null,
        'end_datetime' => now()->addDay()->endOfDay(),
        'priority' => null,
    ]);

    $start = now()->addDays(2)->setTime(9, 0)->toImmutable();
    $end = now()->addDays(2)->setTime(10, 0)->toImmutable();

    $dto = new TaskScheduleRecommendationDto(
        startDatetime: \Illuminate\Support\Carbon::instance($start),
        endDatetime: \Illuminate\Support\Carbon::instance($end),
        durationMinutes: 60,
        priority: 'high',
        reasoning: 'Suggesting time after the due date.',
    );

    $action = app(ApplyTaskScheduleRecommendationAction::class);
    $action->execute(
        user: $user,
        task: $task->refresh(),
        recommendation: $dto,
        intent: LlmIntent::ScheduleTask,
        userAction: 'accept'
    );

    $task->refresh();

    expect($task->start_datetime)->toBeNull()
        ->and($task->end_datetime->toIso8601String())->toBe($task->end_datetime->toIso8601String())
        ->and($task->priority)->toBeNull();
});
