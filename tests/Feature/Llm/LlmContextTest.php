<?php

use App\Enums\EventStatus;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\Event;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Services\Llm\ContextBuilder;
use Carbon\CarbonImmutable;

/**
 * @return array{0: User, 1: ContextBuilder}
 */
function llmContextFixture(): array
{
    return [User::factory()->create(), app(ContextBuilder::class)];
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-11 15:00:00', 'Asia/Manila'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

test('prioritize task context uses canonical shape', function (): void {
    [$user, $builder] = llmContextFixture();
    Task::factory()->for($user)->create([
        'title' => 'Prepare quiz reviewer',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
    ]);

    $context = $builder->build(
        $user,
        LlmIntent::PrioritizeTasks,
        LlmEntityType::Task,
        null,
        null,
        'Rank my tasks'
    );

    expect($context)->toHaveKeys(['current_time', 'current_date', 'timezone', 'tasks', 'conversation_history'])
        ->and($context['tasks'])->toHaveCount(1)
        ->and($context['tasks'][0])->toHaveKeys([
            'id',
            'title',
            'description',
            'status',
            'priority',
            'complexity',
            'duration',
            'start_datetime',
            'end_datetime',
            'project_name',
            'event_title',
            'is_recurring',
            'is_overdue',
            'due_today',
            'is_assessment',
        ]);
});

test('schedule tasks context applies schedule overlay fields', function (): void {
    [$user, $builder] = llmContextFixture();
    Task::factory()->for($user)->create([
        'title' => 'Write reflection paper',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
    ]);

    $context = $builder->build(
        $user,
        LlmIntent::ScheduleTasks,
        LlmEntityType::Multiple,
        null,
        null,
        'From 7pm to 11pm tonight, schedule my existing tasks and do not schedule more than 3 hours'
    );

    expect($context['tasks'])->not->toBeEmpty()
        ->and($context)->toHaveKeys([
            'availability',
            'user_scheduling_request',
            'context_authority',
            'requested_window_start',
            'requested_window_end',
            'focused_work_cap_minutes',
        ])
        ->and($context['focused_work_cap_minutes'])->toBe(180);
});

test('multiple prioritize context includes canonical tasks events and projects', function (): void {
    [$user, $builder] = llmContextFixture();
    Task::factory()->for($user)->create(['status' => TaskStatus::ToDo]);
    Event::factory()->for($user)->create(['status' => EventStatus::Scheduled]);
    Project::factory()->for($user)->create();

    $context = $builder->build(
        $user,
        LlmIntent::PrioritizeAll,
        LlmEntityType::Multiple,
        null,
        null,
        'Prioritize all my items'
    );

    expect($context)->toHaveKeys(['tasks', 'events', 'projects'])
        ->and($context['tasks'])->not->toBeEmpty()
        ->and($context['events'])->not->toBeEmpty()
        ->and($context['projects'])->not->toBeEmpty();
});

test('prioritize task context filters by requested tag before ranking', function (): void {
    [$user, $builder] = llmContextFixture();

    $examTag = Tag::factory()->for($user)->create(['name' => 'Exam']);
    $otherTag = Tag::factory()->for($user)->create(['name' => 'Household']);

    $examTask = Task::factory()->for($user)->create([
        'title' => 'MATH 201 - Quiz 3',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
    ]);
    $nonExamTask = Task::factory()->for($user)->create([
        'title' => 'Library research for history essay',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Medium,
    ]);

    $examTask->tags()->sync([$examTag->id]);
    $nonExamTask->tags()->sync([$otherTag->id]);

    $context = $builder->build(
        $user,
        LlmIntent::PrioritizeTasks,
        LlmEntityType::Task,
        null,
        null,
        'Look at everything tagged as "Exam" and prioritize it from most to least urgent.'
    );

    expect($context['tasks'])->toHaveCount(1)
        ->and($context['tasks'][0]['title'])->toBe('MATH 201 - Quiz 3');
});

test('prioritize all context excludes non-taggable projects when tag filter is required', function (): void {
    [$user, $builder] = llmContextFixture();

    $examTag = Tag::factory()->for($user)->create(['name' => 'Exam']);

    $task = Task::factory()->for($user)->create([
        'title' => 'CS 220 - Midterm coverage review',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
    ]);
    Project::factory()->for($user)->create(['name' => 'Capstone sprint']);

    $task->tags()->sync([$examTag->id]);

    $context = $builder->build(
        $user,
        LlmIntent::PrioritizeAll,
        LlmEntityType::Multiple,
        null,
        null,
        'Look at everything tagged as "Exam" and prioritize it from most to least urgent.'
    );

    expect($context['tasks'])->toHaveCount(1)
        ->and($context['tasks'][0]['title'])->toBe('CS 220 - Midterm coverage review')
        ->and($context['projects'])->toBeArray()->toBeEmpty();
});

test('prioritize task context filters by task properties before ranking', function (): void {
    [$user, $builder] = llmContextFixture();

    $matchingTask = Task::factory()->for($user)->create([
        'title' => 'High recurring no-deadline task',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'end_datetime' => null,
    ]);
    \App\Models\RecurringTask::factory()->for($matchingTask)->create();

    $nonRecurringTask = Task::factory()->for($user)->create([
        'title' => 'High non-recurring no-deadline task',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'end_datetime' => null,
    ]);

    $nonHighTask = Task::factory()->for($user)->create([
        'title' => 'Medium recurring no-deadline task',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Medium,
        'end_datetime' => null,
    ]);
    \App\Models\RecurringTask::factory()->for($nonHighTask)->create();

    $context = $builder->build(
        $user,
        LlmIntent::PrioritizeTasks,
        LlmEntityType::Task,
        null,
        null,
        'Prioritize only high priority recurring tasks with no due date.'
    );

    expect($context['tasks'])->toHaveCount(1)
        ->and($context['tasks'][0]['title'])->toBe('High recurring no-deadline task')
        ->and($context['tasks'][0]['priority'])->toBe('high')
        ->and($context['tasks'][0]['is_recurring'])->toBeTrue()
        ->and($context['tasks'][0]['end_datetime'])->toBeNull()
        ->and(collect($context['tasks'])->pluck('title')->all())->not->toContain($nonRecurringTask->title)
        ->and(collect($context['tasks'])->pluck('title')->all())->not->toContain($nonHighTask->title);
});

test('prioritize task context treats school-only as tasks with a subject name', function (): void {
    [$user, $builder] = llmContextFixture();

    $schoolTask = Task::factory()->for($user)->create([
        'title' => 'CS 220 – Homework 3',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'subject_name' => 'CS 220 – Data Structures',
        'end_datetime' => CarbonImmutable::parse('2026-03-11 20:00:00', 'Asia/Manila'),
    ]);

    $choreTask = Task::factory()->for($user)->create([
        'title' => 'Clean the kitchen',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Medium,
        'subject_name' => null,
        'end_datetime' => CarbonImmutable::parse('2026-03-11 21:00:00', 'Asia/Manila'),
    ]);

    $context = $builder->build(
        $user,
        LlmIntent::PrioritizeTasks,
        LlmEntityType::Task,
        null,
        null,
        'For today only, what are the top 5 school-related tasks I should focus on? Ignore chores and personal stuff.'
    );

    $titles = collect($context['tasks'] ?? [])->pluck('title')->all();

    expect($titles)->toContain($schoolTask->title)
        ->and($titles)->not->toContain($choreTask->title);
});

test('conversation history and explicit previous list context are included', function (): void {
    [$user, $builder] = llmContextFixture();
    $thread = AssistantThread::factory()->for($user)->create();

    AssistantMessage::factory()->for($thread, 'assistantThread')->create([
        'role' => 'user',
        'content' => 'Can you rank my tasks?',
    ]);

    AssistantMessage::factory()->for($thread, 'assistantThread')->create([
        'role' => 'assistant',
        'content' => 'Here is your ranking.',
        'metadata' => [
            'recommendation_snapshot' => [
                'structured' => [
                    'ranked_tasks' => [
                        ['rank' => 1, 'title' => 'Task A'],
                        ['rank' => 2, 'title' => 'Task B'],
                    ],
                ],
            ],
        ],
    ]);

    Task::factory()->for($user)->create(['title' => 'Task A', 'status' => TaskStatus::ToDo]);

    $context = $builder->build(
        $user,
        LlmIntent::ScheduleTask,
        LlmEntityType::Task,
        null,
        $thread,
        'schedule that task for tonight'
    );

    expect($context['conversation_history'])->toHaveCount(2)
        ->and($context)->toHaveKey('previous_list_context')
        ->and($context['previous_list_context'])->toHaveKeys(['entity_type', 'items_in_order']);
});

test('school-only today prompt excludes chores and includes overdue school tasks', function (): void {
    [$user, $builder] = llmContextFixture();

    $householdTag = Tag::factory()->for($user)->create(['name' => 'Household']);

    Task::factory()->for($user)->create([
        'title' => 'CS 220 - Lab write-up',
        'subject_name' => 'CS 220 – Data Structures',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::High,
        'end_datetime' => CarbonImmutable::now('Asia/Manila')->subDay()->setTime(20, 0),
    ]);

    Task::factory()->for($user)->create([
        'title' => 'ITCS 101 - Function drill',
        'subject_name' => 'ITCS 101 – Intro to Programming',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Medium,
        'end_datetime' => CarbonImmutable::now('Asia/Manila')->setTime(21, 0),
    ]);

    $choreTask = Task::factory()->for($user)->create([
        'title' => 'Clean kitchen',
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Low,
        'end_datetime' => CarbonImmutable::now('Asia/Manila')->setTime(22, 0),
    ]);
    $choreTask->tags()->sync([$householdTag->id]);

    $context = $builder->build(
        $user,
        LlmIntent::PrioritizeTasks,
        LlmEntityType::Task,
        null,
        null,
        'For today only, what are the top 5 school-related tasks I should focus on? Ignore chores and personal stuff.'
    );

    expect($context['tasks'])->toHaveCount(2)
        ->and(collect($context['tasks'])->pluck('title')->all())->toContain('CS 220 - Lab write-up', 'ITCS 101 - Function drill')
        ->and(collect($context['tasks'])->pluck('title')->all())->not->toContain('Clean kitchen')
        ->and($context['filtering_summary']['dimensions'])->toContain('school_only', 'time_window');
});

test('list filter search context keeps exam-related tasks and events for this week', function (): void {
    [$user, $builder] = llmContextFixture();

    $examTag = Tag::factory()->for($user)->create(['name' => 'Exam']);
    $householdTag = Tag::factory()->for($user)->create(['name' => 'Household']);

    $examTaskThisWeek = Task::factory()->for($user)->create([
        'title' => 'ITCS 101 – Quiz 2: Conditions',
        'status' => TaskStatus::Done,
        'completed_at' => CarbonImmutable::now('Asia/Manila')->subHour(),
        'end_datetime' => CarbonImmutable::now('Asia/Manila')->addDay(),
    ]);
    $examTaskThisWeek->tags()->sync([$examTag->id]);

    $examEventThisWeek = Event::factory()->for($user)->create([
        'title' => 'Math exam review session',
        'status' => EventStatus::Scheduled,
        'start_datetime' => CarbonImmutable::now('Asia/Manila')->addDays(2),
        'end_datetime' => CarbonImmutable::now('Asia/Manila')->addDays(2)->addHour(),
    ]);

    $nonExamTask = Task::factory()->for($user)->create([
        'title' => 'Wash dishes after dinner',
        'status' => TaskStatus::ToDo,
        'end_datetime' => CarbonImmutable::now('Asia/Manila')->addDay(),
    ]);
    $nonExamTask->tags()->sync([$householdTag->id]);

    $examTaskOutsideWeek = Task::factory()->for($user)->create([
        'title' => 'MATH 201 – Take-home Exam 1 Submission',
        'status' => TaskStatus::ToDo,
        'end_datetime' => CarbonImmutable::now('Asia/Manila')->addDays(10),
    ]);
    $examTaskOutsideWeek->tags()->sync([$examTag->id]);

    $context = $builder->build(
        $user,
        LlmIntent::ListFilterSearch,
        LlmEntityType::Multiple,
        null,
        null,
        'Show only my exam-related tasks and events for this week.'
    );

    expect(collect($context['tasks'])->pluck('title')->all())->toContain('ITCS 101 – Quiz 2: Conditions')
        ->and(collect($context['events'])->pluck('title')->all())->toContain('Math exam review session')
        ->and(collect($context['tasks'])->pluck('title')->all())->not->toContain('Wash dishes after dinner')
        ->and(collect($context['tasks'])->pluck('title')->all())->not->toContain('MATH 201 – Take-home Exam 1 Submission')
        ->and($context['filtering_summary']['dimensions'])->toContain('required_tag', 'time_window', 'exam_related');
});

test('list filter search context keeps health and household tasks only', function (): void {
    [$user, $builder] = llmContextFixture();

    $healthTag = Tag::factory()->for($user)->create(['name' => 'Health']);
    $householdTag = Tag::factory()->for($user)->create(['name' => 'Household']);
    $examTag = Tag::factory()->for($user)->create(['name' => 'Exam']);

    $healthTask = Task::factory()->for($user)->create([
        'title' => 'Walk 10k steps',
        'status' => TaskStatus::ToDo,
    ]);
    $healthTask->tags()->sync([$healthTag->id]);

    $householdTask = Task::factory()->for($user)->create([
        'title' => 'Prepare tomorrow’s school bag',
        'status' => TaskStatus::ToDo,
    ]);
    $householdTask->tags()->sync([$householdTag->id]);

    $academicTask = Task::factory()->for($user)->create([
        'title' => 'MATH 201 – Quiz 3: Graph Theory',
        'status' => TaskStatus::ToDo,
    ]);
    $academicTask->tags()->sync([$examTag->id]);

    $context = $builder->build(
        $user,
        LlmIntent::ListFilterSearch,
        LlmEntityType::Task,
        null,
        null,
        'List all tasks related to health or household chores.'
    );

    expect(collect($context['tasks'])->pluck('title')->all())->toContain('Walk 10k steps', 'Prepare tomorrow’s school bag')
        ->and(collect($context['tasks'])->pluck('title')->all())->not->toContain('MATH 201 – Quiz 3: Graph Theory')
        ->and($context['filtering_summary']['dimensions'])->toContain('health_or_household_only', 'required_tag');
});

test('list filter search events-only context uses rolling next seven days window', function (): void {
    [$user, $builder] = llmContextFixture();

    Event::factory()->for($user)->create([
        'title' => 'CS group project meetup',
        'status' => EventStatus::Scheduled,
        'start_datetime' => CarbonImmutable::now('Asia/Manila')->addDays(6),
        'end_datetime' => CarbonImmutable::now('Asia/Manila')->addDays(6)->addHours(2),
    ]);
    Event::factory()->for($user)->create([
        'title' => 'Campus club orientation night',
        'status' => EventStatus::Scheduled,
        'start_datetime' => CarbonImmutable::now('Asia/Manila')->addDays(8),
        'end_datetime' => CarbonImmutable::now('Asia/Manila')->addDays(8)->addHour(),
    ]);
    Task::factory()->for($user)->create([
        'title' => 'Task inside range should be excluded by entity scope',
        'status' => TaskStatus::ToDo,
        'end_datetime' => CarbonImmutable::now('Asia/Manila')->addDays(2),
    ]);

    $context = $builder->build(
        $user,
        LlmIntent::ListFilterSearch,
        LlmEntityType::Event,
        null,
        null,
        'Filter to events only and show what’s coming up in the next 7 days.'
    );

    expect(collect($context['events'])->pluck('title')->all())->toContain('CS group project meetup')
        ->and(collect($context['events'])->pluck('title')->all())->not->toContain('Campus club orientation night')
        ->and($context['tasks'] ?? [])->toBeArray()->toBeEmpty()
        ->and($context['filtering_summary']['dimensions'])->toContain('time_window');
});
