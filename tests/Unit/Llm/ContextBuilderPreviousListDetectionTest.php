<?php

use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\AssistantThread;
use App\Models\Event;
use App\Models\Task;
use App\Models\User;
use App\Services\Llm\ContextBuilder;

it('does not inject previous_list_context without an explicit prior assistant list', function (): void {
    /** @var ContextBuilder $builder */
    $builder = app(ContextBuilder::class);

    $user = User::factory()->create();
    Task::factory()->for($user)->create([
        'title' => 'Any task',
        'status' => TaskStatus::ToDo,
        'completed_at' => null,
        'end_datetime' => now()->addDays(3),
    ]);

    $context = $builder->build(
        user: $user,
        intent: LlmIntent::ScheduleTask,
        entityType: LlmEntityType::Task,
        entityId: null,
        thread: null,
        userMessage: 'schedule my top 1 task for later evening'
    );

    expect($context)->toHaveKey('tasks')
        ->and($context['tasks'])->not->toBeEmpty()
        ->and($context)->not->toHaveKey('previous_list_context');
});

it('orders tasks by urgency when user references top task and no previous list', function (): void {
    /** @var ContextBuilder $builder */
    $builder = app(ContextBuilder::class);

    $user = User::factory()->create();

    $overdueTitle = 'Overdue task';
    $dueTodayTitle = 'Due today task';
    $laterTitle = 'Later task';

    Task::factory()->for($user)->create([
        'title' => $overdueTitle,
        'status' => TaskStatus::ToDo,
        'completed_at' => null,
        'priority' => TaskPriority::Medium,
        'end_datetime' => now()->subDay(),
    ]);
    Task::factory()->for($user)->create([
        'title' => $dueTodayTitle,
        'status' => TaskStatus::ToDo,
        'completed_at' => null,
        'priority' => TaskPriority::High,
        'end_datetime' => now()->endOfDay(),
    ]);
    Task::factory()->for($user)->create([
        'title' => $laterTitle,
        'status' => TaskStatus::ToDo,
        'completed_at' => null,
        'priority' => TaskPriority::Low,
        'end_datetime' => now()->addDays(5),
    ]);

    $context = $builder->build(
        user: $user,
        intent: LlmIntent::ScheduleTask,
        entityType: LlmEntityType::Task,
        entityId: null,
        thread: null,
        userMessage: 'schedule my top task for tonight'
    );

    expect($context)->toHaveKey('tasks');
    $tasks = $context['tasks'];
    expect($tasks)->toHaveCount(3);
    expect($tasks[0]['title'])->toBe($overdueTitle);
    expect($tasks[1]['title'])->toBe($dueTodayTitle);
    expect($tasks[2]['title'])->toBe($laterTitle);
});

it('orders tasks by previous ranked list when user references it so top task is consistent', function (): void {
    /** @var ContextBuilder $builder */
    $builder = app(ContextBuilder::class);

    $user = User::factory()->create();

    $titleFirst = 'PRELIM DEPT EXAM - First';
    $titleSecond = 'Antas/Teorya ng wika - Second';
    $titleThird = 'Midterm Task 1 - Third';

    Task::factory()->for($user)->create([
        'title' => $titleFirst,
        'status' => TaskStatus::ToDo,
        'completed_at' => null,
        'end_datetime' => now()->addDays(10),
    ]);
    Task::factory()->for($user)->create([
        'title' => $titleSecond,
        'status' => TaskStatus::ToDo,
        'completed_at' => null,
        'end_datetime' => now()->addDays(2),
    ]);
    Task::factory()->for($user)->create([
        'title' => $titleThird,
        'status' => TaskStatus::ToDo,
        'completed_at' => null,
        'end_datetime' => now()->addDays(5),
    ]);

    $thread = AssistantThread::factory()->create(['user_id' => $user->id]);
    $thread->messages()->create([
        'role' => 'assistant',
        'content' => 'Here are your top 3 tasks.',
        'metadata' => [
            'recommendation_snapshot' => [
                'structured' => [
                    'ranked_tasks' => [
                        ['title' => $titleFirst],
                        ['title' => $titleSecond],
                        ['title' => $titleThird],
                    ],
                ],
            ],
        ],
    ]);

    $context = $builder->build(
        user: $user,
        intent: LlmIntent::ScheduleTask,
        entityType: LlmEntityType::Task,
        entityId: null,
        thread: $thread,
        userMessage: 'schedule the top task for today'
    );

    expect($context)->toHaveKey('tasks');
    $tasks = $context['tasks'];
    expect($tasks)->toHaveCount(3);
    expect($tasks[0]['title'])->toBe($titleFirst)
        ->and($context)->toHaveKey('previous_list_context')
        ->and($context['previous_list_context'])->toHaveKey('items_in_order');
});

it('treats top 1 phrasing as referring to previous list', function (): void {
    /** @var ContextBuilder $builder */
    $builder = app(ContextBuilder::class);

    $user = User::factory()->create();
    $thread = AssistantThread::factory()->create([
        'user_id' => $user->id,
    ]);

    $assistantMessage = $thread->messages()->create([
        'role' => 'assistant',
        'content' => 'You have 4 tasks matching that request.',
        'metadata' => [
            'recommendation_snapshot' => [
                'structured' => [
                    'listed_items' => [
                        ['title' => 'Output # 1: My Light to the Society  - Due'],
                        ['title' => 'Output # 2: EMILIAN - ἀρετή - Due'],
                    ],
                ],
            ],
        ],
    ]);

    expect($assistantMessage->exists())->toBeTrue();

    $context = $builder->build(
        user: $user,
        intent: LlmIntent::ScheduleTask,
        entityType: LlmEntityType::Task,
        entityId: null,
        thread: $thread,
        userMessage: 'schedule the top 1 for later'
    );

    expect($context)->toHaveKey('tasks');
});

it('restricts schedule followup using those to previously listed tasks only', function (): void {
    /** @var ContextBuilder $builder */
    $builder = app(ContextBuilder::class);

    $user = User::factory()->create();

    $titleFirst = 'MATH 201 – Take-home Exam 1 Submission';
    $titleSecond = 'ITCS 101 – Programming Exercise: Functions';
    $titleThird = 'CS 220 – Lab 5: Linked Lists';
    $extraTitle = 'ENG 105 – Draft 2: Comparative Essay';

    Task::factory()->for($user)->create([
        'title' => $titleFirst,
        'status' => TaskStatus::ToDo,
        'completed_at' => null,
        'end_datetime' => now()->addDays(1),
    ]);
    Task::factory()->for($user)->create([
        'title' => $titleSecond,
        'status' => TaskStatus::ToDo,
        'completed_at' => null,
        'end_datetime' => now()->addDays(1),
    ]);
    Task::factory()->for($user)->create([
        'title' => $titleThird,
        'status' => TaskStatus::ToDo,
        'completed_at' => null,
        'end_datetime' => now()->addDays(1),
    ]);
    Task::factory()->for($user)->create([
        'title' => $extraTitle,
        'status' => TaskStatus::ToDo,
        'completed_at' => null,
        'end_datetime' => now()->addDays(1),
    ]);

    $thread = AssistantThread::factory()->create(['user_id' => $user->id]);
    $thread->messages()->create([
        'role' => 'assistant',
        'content' => 'Here are your top school tasks for today.',
        'metadata' => [
            'recommendation_snapshot' => [
                'structured' => [
                    'ranked_tasks' => [
                        ['title' => $titleFirst],
                        ['title' => $titleSecond],
                        ['title' => $titleThird],
                    ],
                ],
            ],
        ],
    ]);

    $context = $builder->build(
        user: $user,
        intent: LlmIntent::ScheduleTasks,
        entityType: LlmEntityType::Multiple,
        entityId: null,
        thread: $thread,
        userMessage: 'Okay, schedule those across tonight and tomorrow evening.'
    );

    expect($context)->toHaveKey('tasks');
    $tasks = $context['tasks'];

    expect(collect($tasks)->pluck('title')->all())->toMatchArray([
        $titleFirst,
        $titleSecond,
        $titleThird,
    ])->and(collect($tasks)->pluck('title')->all())->not->toContain($extraTitle);
});

it('restricts schedule followup using those to previously listed exam tasks and events', function (): void {
    /** @var ContextBuilder $builder */
    $builder = app(ContextBuilder::class);

    $user = User::factory()->create();

    $quizTitle = 'ITCS 101 – Quiz 2: Conditions';
    $mathQuizTitle = 'MATH 201 – Quiz 3: Graph Theory';
    $takeHomeTitle = 'MATH 201 – Take-home Exam 1 Submission';
    $reviewEventTitle = 'Math exam review session';
    $extraTaskTitle = 'Library research for history essay';

    Task::factory()->for($user)->create([
        'title' => $quizTitle,
        'status' => TaskStatus::ToDo,
        'completed_at' => null,
        'end_datetime' => now()->addDays(1),
    ]);
    Task::factory()->for($user)->create([
        'title' => $mathQuizTitle,
        'status' => TaskStatus::ToDo,
        'completed_at' => null,
        'end_datetime' => now()->addDays(2),
    ]);
    Task::factory()->for($user)->create([
        'title' => $takeHomeTitle,
        'status' => TaskStatus::Done,
        'completed_at' => now()->subDay(),
        'end_datetime' => now()->subDay(),
    ]);
    Task::factory()->for($user)->create([
        'title' => $extraTaskTitle,
        'status' => TaskStatus::ToDo,
        'completed_at' => null,
        'end_datetime' => now()->addDays(3),
    ]);

    Event::factory()->for($user)->create([
        'title' => $reviewEventTitle,
        'start_datetime' => now()->addDays(1)->setTime(16, 0),
        'end_datetime' => now()->addDays(1)->setTime(18, 0),
    ]);

    $thread = AssistantThread::factory()->create(['user_id' => $user->id]);
    $thread->messages()->create([
        'role' => 'assistant',
        'content' => 'Here are the exam-related tasks and events.',
        'metadata' => [
            'recommendation_snapshot' => [
                'structured' => [
                    'listed_items' => [
                        ['title' => $quizTitle],
                        ['title' => $mathQuizTitle],
                        ['title' => $takeHomeTitle],
                        ['title' => $reviewEventTitle],
                    ],
                ],
            ],
        ],
    ]);

    $context = $builder->build(
        user: $user,
        intent: LlmIntent::ScheduleTasksAndEvents,
        entityType: LlmEntityType::Multiple,
        entityId: null,
        thread: $thread,
        userMessage: 'Using those, create a 3-day study plan that balances time between ITCS 101 and MATH 201.'
    );

    expect($context)->toHaveKeys(['tasks', 'events']);
    $taskTitles = collect($context['tasks'])->pluck('title')->all();
    $eventTitles = collect($context['events'])->pluck('title')->all();

    expect($taskTitles)->not->toContain($extraTaskTitle)
        ->and($eventTitles)->toContain($reviewEventTitle);
});
