<?php

use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Services\Llm\StructuredOutputSanitizer;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->sanitizer = new StructuredOutputSanitizer;
});

test('sanitize prioritize_events with empty context strips ranked_events and overrides message', function (): void {
    $structured = [
        'entity_type' => 'event',
        'recommended_action' => 'Focus on the exam.',
        'reasoning' => 'It has high priority.',
        'ranked_events' => [
            ['rank' => 1, 'title' => 'Fake event from conversation'],
        ],
    ];
    $context = ['current_time' => now()->toIso8601String(), 'events' => [], 'conversation_history' => []];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::PrioritizeEvents);

    expect($out['ranked_events'])->toBeArray()->toBeEmpty()
        ->and($out['recommended_action'])->toContain('couldn\'t find any events')
        ->and($out['confidence'])->toBeLessThanOrEqual(0.3);
});

test('sanitize prioritize_events keeps only events that exist in context', function (): void {
    $structured = [
        'entity_type' => 'event',
        'recommended_action' => 'Prioritize these.',
        'reasoning' => 'Order by time.',
        'ranked_events' => [
            ['rank' => 1, 'title' => 'Real Event A'],
            ['rank' => 2, 'title' => 'Fake from history'],
            ['rank' => 3, 'title' => 'Real Event B'],
        ],
    ];
    $context = [
        'events' => [
            ['id' => 1, 'title' => 'Real Event A'],
            ['id' => 2, 'title' => 'Real Event B'],
        ],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::PrioritizeEvents);

    expect($out['ranked_events'])->toHaveCount(2)
        ->and($out['ranked_events'][0]['title'])->toBe('Real Event A')
        ->and($out['ranked_events'][1]['title'])->toBe('Real Event B')
        ->and($out['ranked_events'][0]['rank'])->toBe(1)
        ->and($out['ranked_events'][1]['rank'])->toBe(2);
});

test('sanitize prioritize_events preserves LLM ordering and does not fill missing events', function (): void {
    $structured = [
        'entity_type' => 'event',
        'recommended_action' => 'Prioritize these.',
        'reasoning' => 'Order by time.',
        'ranked_events' => [
            ['rank' => 1, 'title' => 'Real Event A'],
        ],
    ];
    $context = [
        'events' => [
            ['id' => 1, 'title' => 'Real Event A', 'start_datetime' => '2026-03-12T10:00:00+08:00', 'end_datetime' => '2026-03-12T11:00:00+08:00'],
            ['id' => 2, 'title' => 'Real Event B', 'start_datetime' => '2026-03-13T10:00:00+08:00', 'end_datetime' => '2026-03-13T11:00:00+08:00'],
        ],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::PrioritizeEvents);

    expect($out['ranked_events'])->toHaveCount(1)
        ->and($out['ranked_events'][0]['rank'])->toBe(1)
        ->and($out['ranked_events'][0]['title'])->toBe('Real Event A');
});

test('sanitize prioritize_projects keeps only projects that exist in context and does not fill missing projects', function (): void {
    $structured = [
        'entity_type' => 'project',
        'recommended_action' => 'Prioritize these projects.',
        'reasoning' => 'Order by deadline.',
        'ranked_projects' => [
            ['rank' => 1, 'name' => 'Project A', 'end_datetime' => '2030-01-01T00:00:00+00:00'],
            ['rank' => 2, 'name' => 'Hallucinated Project'],
        ],
    ];
    $context = [
        'projects' => [
            ['id' => 1, 'name' => 'Project A', 'end_datetime' => '2026-03-20T17:00:00+08:00'],
            ['id' => 2, 'name' => 'Project B', 'end_datetime' => '2026-03-25T17:00:00+08:00'],
        ],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::PrioritizeProjects);

    expect($out['ranked_projects'])->toHaveCount(1)
        ->and($out['ranked_projects'][0]['name'])->toBe('Project A')
        ->and($out['ranked_projects'][0]['end_datetime'])->toBe('2026-03-20T17:00:00+08:00');
});

test('sanitize prioritize_tasks with empty context strips ranked_tasks', function (): void {
    $structured = [
        'entity_type' => 'task',
        'ranked_tasks' => [['rank' => 1, 'title' => 'Fake task']],
    ];
    $context = ['tasks' => []];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::PrioritizeTasks);

    expect($out['ranked_tasks'])->toBeArray()->toBeEmpty();
});

test('sanitize prioritize_tasks preserves LLM ordering and does not fill missing tasks', function (): void {
    $structured = [
        'entity_type' => 'task',
        'recommended_action' => 'Prioritize these.',
        'reasoning' => 'Order by urgency.',
        'ranked_tasks' => [
            ['rank' => 1, 'title' => 'Task A'],
        ],
    ];
    $context = [
        'tasks' => [
            ['id' => 1, 'title' => 'Task A', 'end_datetime' => '2026-03-13T23:59:00+08:00'],
            ['id' => 2, 'title' => 'Task B', 'end_datetime' => '2026-03-13T23:00:00+08:00'],
            ['id' => 3, 'title' => 'Task C'],
        ],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::PrioritizeTasks);

    expect($out['ranked_tasks'])->toHaveCount(1)
        ->and($out['ranked_tasks'][0]['rank'])->toBe(1)
        ->and($out['ranked_tasks'][0]['title'])->toBe('Task A');
});

test('sanitize prioritize_tasks canonicalizes ranked_tasks end_datetime from context', function (): void {
    $structured = [
        'entity_type' => 'task',
        'recommended_action' => 'Prioritize these.',
        'reasoning' => 'Order by urgency.',
        'ranked_tasks' => [
            // Model got time wrong (11:00 instead of 23:00)
            ['rank' => 1, 'title' => 'Task A', 'end_datetime' => '2026-03-13T11:00:00+08:00'],
        ],
    ];
    $context = [
        'tasks' => [
            ['id' => 1, 'title' => 'Task A', 'end_datetime' => '2026-03-13T23:00:00+08:00'],
        ],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::PrioritizeTasks);

    expect($out['ranked_tasks'])->toHaveCount(1)
        ->and($out['ranked_tasks'][0]['title'])->toBe('Task A')
        ->and($out['ranked_tasks'][0]['end_datetime'])->toBe('2026-03-13T23:00:00+08:00');
});

test('sanitize prioritize_tasks does not re-rank items based on deadlines or assessments', function (): void {
    $structured = [
        'entity_type' => 'task',
        'recommended_action' => 'Prioritize these.',
        'reasoning' => 'Order by urgency.',
        'ranked_tasks' => [
            ['rank' => 1, 'title' => 'Quiz Task'],
            ['rank' => 2, 'title' => 'Problem Set Task'],
            ['rank' => 3, 'title' => 'Lab Task'],
        ],
    ];
    $context = [
        'tasks' => [
            ['id' => 1, 'title' => 'Lab Task', 'end_datetime' => '2026-03-13T23:59:00+08:00', 'priority' => 'high', 'duration' => 210, 'due_today' => false, 'is_overdue' => false],
            ['id' => 2, 'title' => 'Problem Set Task', 'end_datetime' => '2026-03-13T23:00:00+08:00', 'priority' => 'medium', 'duration' => 150, 'due_today' => false, 'is_overdue' => false],
            ['id' => 3, 'title' => 'Quiz Task', 'end_datetime' => '2026-03-14T10:00:00+08:00', 'priority' => 'high', 'duration' => 30, 'due_today' => false, 'is_overdue' => false],
        ],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::PrioritizeTasks);

    expect($out['ranked_tasks'])->toHaveCount(3)
        ->and($out['ranked_tasks'][0]['title'])->toBe('Quiz Task')
        ->and($out['ranked_tasks'][1]['title'])->toBe('Problem Set Task')
        ->and($out['ranked_tasks'][2]['title'])->toBe('Lab Task');
});

test('sanitize non prioritize intent returns structured unchanged', function (): void {
    $structured = ['entity_type' => 'task', 'recommended_action' => 'Do X', 'reasoning' => 'Because'];
    $context = ['tasks' => []];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::ResolveDependency);

    expect($out)->toEqual($structured);
});

test('sanitize ScheduleTasks strips scheduled_tasks outside requested from-to tonight window', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-12 18:00:00', config('app.timezone')));

    $structured = [
        'entity_type' => 'task',
        'recommended_action' => 'Suggested schedule.',
        'reasoning' => 'Based on your window.',
        'scheduled_tasks' => [
            ['title' => 'Task A', 'start_datetime' => '2026-03-14T10:30:00+08:00', 'duration' => 30],
            ['title' => 'Task B', 'start_datetime' => '2026-03-12T19:00:00+08:00', 'duration' => 60],
        ],
    ];
    $context = [
        'timezone' => 'Asia/Manila',
        'current_date' => '2026-03-12',
        'current_time' => '2026-03-12T18:00:00+08:00',
        'tasks' => [
            ['id' => 1, 'title' => 'Task A'],
            ['id' => 2, 'title' => 'Task B'],
        ],
    ];

    $out = $this->sanitizer->sanitize(
        $structured,
        $context,
        LlmIntent::ScheduleTasks,
        LlmEntityType::Multiple,
        'From 7pm to 11pm tonight, create a realistic plan using my existing tasks.'
    );

    expect($out['scheduled_tasks'])->toHaveCount(1)
        ->and($out['scheduled_tasks'][0]['title'])->toBe('Task B');
});

test('sanitize PrioritizeTasksAndEvents filters both ranked_tasks and ranked_events by context', function (): void {
    $structured = [
        'entity_type' => 'task,event',
        'recommended_action' => 'Prioritize both.',
        'reasoning' => 'Order by urgency.',
        'ranked_tasks' => [
            ['rank' => 1, 'title' => 'Real Task'],
            ['rank' => 2, 'title' => 'Hallucinated Task'],
        ],
        'ranked_events' => [
            ['rank' => 1, 'title' => 'Real Event'],
            ['rank' => 2, 'title' => 'Completely unrelated meeting'],
        ],
    ];
    $context = [
        'tasks' => [['id' => 1, 'title' => 'Real Task']],
        'events' => [['id' => 1, 'title' => 'Real Event']],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::PrioritizeTasksAndEvents);

    expect($out['ranked_tasks'])->toHaveCount(1)
        ->and($out['ranked_tasks'][0]['title'])->toBe('Real Task')
        ->and($out['ranked_events'])->toHaveCount(1)
        ->and($out['ranked_events'][0]['title'])->toBe('Real Event');
});

test('sanitize PrioritizeTasksAndProjects filters ranked_tasks and ranked_projects by context', function (): void {
    $structured = [
        'entity_type' => 'task,project',
        'recommended_action' => 'Prioritize both.',
        'reasoning' => 'Order by urgency.',
        'ranked_tasks' => [
            ['rank' => 1, 'title' => 'Real Task'],
            ['rank' => 2, 'title' => 'Hallucinated Task'],
        ],
        'ranked_projects' => [
            ['rank' => 1, 'name' => 'Real Project'],
            ['rank' => 2, 'name' => 'Totally different initiative name'],
        ],
    ];
    $context = [
        'tasks' => [['id' => 1, 'title' => 'Real Task']],
        'projects' => [['id' => 1, 'name' => 'Real Project']],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::PrioritizeTasksAndProjects);

    expect($out['ranked_tasks'])->toHaveCount(1)
        ->and($out['ranked_tasks'][0]['title'])->toBe('Real Task')
        ->and($out['ranked_projects'])->toHaveCount(1)
        ->and($out['ranked_projects'][0]['name'])->toBe('Real Project');
});

test('sanitize PrioritizeAll filters all three ranked lists by context', function (): void {
    $structured = [
        'entity_type' => 'all',
        'recommended_action' => 'Prioritize all.',
        'reasoning' => 'Order by urgency.',
        'ranked_tasks' => [
            ['rank' => 1, 'title' => 'Real Task'],
            ['rank' => 2, 'title' => 'Hallucinated Task'],
        ],
        'ranked_events' => [
            ['rank' => 1, 'title' => 'Real Event'],
        ],
        'ranked_projects' => [
            ['rank' => 1, 'name' => 'Real Project'],
            ['rank' => 2, 'name' => 'Completely different capstone'],
        ],
    ];
    $context = [
        'tasks' => [['id' => 1, 'title' => 'Real Task']],
        'events' => [['id' => 1, 'title' => 'Real Event']],
        'projects' => [['id' => 1, 'name' => 'Real Project']],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::PrioritizeAll);

    expect($out['ranked_tasks'])->toHaveCount(1)
        ->and($out['ranked_tasks'][0]['title'])->toBe('Real Task')
        ->and($out['ranked_events'])->toHaveCount(1)
        ->and($out['ranked_events'][0]['title'])->toBe('Real Event')
        ->and($out['ranked_projects'])->toHaveCount(1)
        ->and($out['ranked_projects'][0]['name'])->toBe('Real Project');
});

test('sanitize prioritize intents strips identifier fields from ranked lists', function (): void {
    $structured = [
        'entity_type' => 'all',
        'recommended_action' => 'Prioritize all.',
        'reasoning' => 'Order by urgency.',
        'ranked_tasks' => [
            ['rank' => 1, 'id' => 101, 'task_id' => 101, 'title' => 'Real Task'],
        ],
        'ranked_events' => [
            ['rank' => 1, 'id' => 202, 'event_id' => 202, 'title' => 'Real Event'],
        ],
        'ranked_projects' => [
            ['rank' => 1, 'id' => 303, 'project_id' => 303, 'name' => 'Real Project'],
        ],
    ];
    $context = [
        'tasks' => [['id' => 1, 'title' => 'Real Task']],
        'events' => [['id' => 2, 'title' => 'Real Event']],
        'projects' => [['id' => 3, 'name' => 'Real Project']],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::PrioritizeAll);

    expect($out['ranked_tasks'][0])->not->toHaveKey('id')
        ->and($out['ranked_tasks'][0])->not->toHaveKey('task_id')
        ->and($out['ranked_events'][0])->not->toHaveKey('id')
        ->and($out['ranked_events'][0])->not->toHaveKey('event_id')
        ->and($out['ranked_projects'][0])->not->toHaveKey('id')
        ->and($out['ranked_projects'][0])->not->toHaveKey('project_id');
});

test('sanitize ScheduleTasksAndEvents keeps only tasks and events that exist in context', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-01 10:00:00', config('app.timezone')));

    $structured = [
        'entity_type' => 'task,event',
        'recommended_action' => 'Suggested times below.',
        'reasoning' => 'Based on availability.',
        'scheduled_tasks' => [
            ['title' => 'Real Task', 'start_datetime' => '2026-03-10T09:00:00+08:00', 'end_datetime' => '2026-03-10T10:00:00+08:00'],
            ['title' => 'Hallucinated Task', 'start_datetime' => '2026-03-11T09:00:00+08:00', 'end_datetime' => '2026-03-11T10:00:00+08:00'],
        ],
        'scheduled_events' => [
            ['title' => 'Real Event', 'start_datetime' => '2026-03-12T14:00:00+08:00', 'end_datetime' => '2026-03-12T15:00:00+08:00'],
            ['title' => 'Completely unrelated meeting', 'start_datetime' => '2026-03-13T14:00:00+08:00', 'end_datetime' => '2026-03-13T15:00:00+08:00'],
        ],
    ];
    $context = [
        'tasks' => [['id' => 1, 'title' => 'Real Task']],
        'events' => [['id' => 1, 'title' => 'Real Event']],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::ScheduleTasksAndEvents);

    expect($out['scheduled_tasks'])->toHaveCount(1)
        ->and($out['scheduled_tasks'][0]['title'])->toBe('Real Task')
        ->and($out['scheduled_tasks'][0]['start_datetime'])->toBe('2026-03-10T09:00:00+08:00')
        ->and($out['scheduled_tasks'][0]['id'])->toBe(1)
        ->and($out['scheduled_events'])->toHaveCount(1)
        ->and($out['scheduled_events'][0]['title'])->toBe('Real Event')
        ->and($out['scheduled_events'][0]['start_datetime'])->toBe('2026-03-12T14:00:00+08:00');
});

test('sanitize ScheduleAll keeps only scheduled items that exist in context', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-01 10:00:00', config('app.timezone')));

    $structured = [
        'entity_type' => 'all',
        'recommended_action' => 'Suggested times for all.',
        'reasoning' => 'Based on availability.',
        'scheduled_tasks' => [
            ['title' => 'Real Task', 'start_datetime' => '2026-03-10T09:00:00+00:00', 'end_datetime' => '2026-03-10T10:00:00+00:00'],
            ['title' => 'Hallucinated Task', 'start_datetime' => '2026-03-11T09:00:00+00:00', 'end_datetime' => '2026-03-11T10:00:00+00:00'],
        ],
        'scheduled_events' => [
            ['title' => 'Real Event', 'start_datetime' => '2026-03-12T14:00:00+00:00', 'end_datetime' => '2026-03-12T15:00:00+00:00'],
        ],
        'scheduled_projects' => [
            ['name' => 'Real Project', 'start_datetime' => '2026-03-15T09:00:00+00:00', 'end_datetime' => '2026-03-17T17:00:00+00:00'],
            ['name' => 'Completely different capstone', 'start_datetime' => '2026-03-18T09:00:00+00:00', 'end_datetime' => '2026-03-19T17:00:00+00:00'],
        ],
    ];
    $context = [
        'tasks' => [['id' => 1, 'title' => 'Real Task']],
        'events' => [['id' => 1, 'title' => 'Real Event']],
        'projects' => [['id' => 1, 'name' => 'Real Project']],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::ScheduleAll);

    expect($out['scheduled_tasks'])->toHaveCount(1)
        ->and($out['scheduled_tasks'][0]['title'])->toBe('Real Task')
        ->and($out['scheduled_tasks'][0]['id'])->toBe(1)
        ->and($out['scheduled_events'])->toHaveCount(1)
        ->and($out['scheduled_events'][0]['title'])->toBe('Real Event')
        ->and($out['scheduled_projects'])->toHaveCount(1)
        ->and($out['scheduled_projects'][0]['name'])->toBe('Real Project');
});

test('sanitize prioritize_tasks rejects fuzzy-only title matches', function (): void {
    $structured = [
        'entity_type' => 'task',
        'ranked_tasks' => [
            ['rank' => 1, 'title' => 'Ortograpia/ Barayti ng wika - Due', 'end_datetime' => '2026-02-22T15:59:59+08:00'],
        ],
    ];
    $context = [
        'tasks' => [
            ['id' => 1, 'title' => 'Ortograpiya/ Barayti ng wika - Due'],
        ],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::PrioritizeTasks);

    expect($out['ranked_tasks'])->toBeArray()->toBeEmpty();
});

test('sanitize general_query with no set dates filters listed_items to only tasks with both dates null', function (): void {
    $structured = [
        'entity_type' => 'task',
        'recommended_action' => 'Here are your tasks with no set dates.',
        'reasoning' => 'Filtered.',
        'listed_items' => [
            ['title' => 'Bring insurance card', 'end_datetime' => '2026-03-15T13:13:18+08:00'],
            ['title' => 'Undated task', 'end_datetime' => null],
            ['title' => 'Get contractor quotes', 'end_datetime' => '2026-03-17T10:21:38+08:00'],
        ],
    ];
    $context = [
        'tasks' => [
            ['id' => 1, 'title' => 'Bring insurance card', 'start_datetime' => '2026-03-10T00:00:00+08:00', 'end_datetime' => '2026-03-15T13:13:18+08:00'],
            ['id' => 2, 'title' => 'Undated task', 'start_datetime' => null, 'end_datetime' => null],
            ['id' => 3, 'title' => 'Get contractor quotes', 'start_datetime' => null, 'end_datetime' => '2026-03-17T10:21:38+08:00'],
        ],
    ];

    $out = $this->sanitizer->sanitize(
        $structured,
        $context,
        LlmIntent::GeneralQuery,
        LlmEntityType::Task,
        'what tasks that has no set dates?'
    );

    expect($out['listed_items'])->toHaveCount(1)
        ->and($out['listed_items'][0]['title'])->toBe('Undated task')
        ->and($out['listed_items'][0])->not->toHaveKey('end_datetime')
        ->and($out['listed_items'][0])->not->toHaveKey('start_datetime');
});

test('sanitize general_query with no due date filters to tasks with end_datetime null', function (): void {
    $structured = [
        'entity_type' => 'task',
        'recommended_action' => 'Tasks without due date.',
        'reasoning' => 'Filtered.',
        'listed_items' => [
            ['title' => 'Task with due', 'end_datetime' => '2026-03-16T19:34:01+08:00'],
            ['title' => 'Task no due', 'end_datetime' => null],
        ],
    ];
    $context = [
        'tasks' => [
            ['id' => 1, 'title' => 'Task with due', 'start_datetime' => null, 'end_datetime' => '2026-03-16T19:34:01+08:00'],
            ['id' => 2, 'title' => 'Task no due', 'start_datetime' => null, 'end_datetime' => null],
        ],
    ];

    $out = $this->sanitizer->sanitize(
        $structured,
        $context,
        LlmIntent::GeneralQuery,
        LlmEntityType::Task,
        'list tasks with no due date'
    );

    expect($out['listed_items'])->toHaveCount(1)
        ->and($out['listed_items'][0]['title'])->toBe('Task no due')
        ->and($out['listed_items'][0])->not->toHaveKey('end_datetime');
});

test('sanitize general_query listing request rebuilds list from context when model list is empty', function (): void {
    $structured = [
        'entity_type' => 'task',
        'recommended_action' => 'Here are your tasks.',
        'reasoning' => 'Stub.',
        'listed_items' => [],
    ];
    $context = [
        'tasks' => [
            ['id' => 1, 'title' => 'Task A', 'start_datetime' => null, 'end_datetime' => null, 'priority' => 'medium'],
            ['id' => 2, 'title' => 'Task B', 'start_datetime' => null, 'end_datetime' => null, 'priority' => 'high'],
        ],
    ];

    $out = $this->sanitizer->sanitize(
        $structured,
        $context,
        LlmIntent::GeneralQuery,
        LlmEntityType::Task,
        'show me all my tasks'
    );

    expect($out['listed_items'])->toHaveCount(2)
        ->and($out['listed_items'][0]['title'])->toBe('Task A')
        ->and($out['listed_items'][1]['title'])->toBe('Task B')
        ->and((string) $out['reasoning'])->toContain('latest context');
});

test('sanitize general_query with no due date and empty result overrides message', function (): void {
    $structured = [
        'entity_type' => 'task',
        'recommended_action' => 'Here are your tasks with no set due dates.',
        'reasoning' => 'I found some.',
        'listed_items' => [],
    ];
    $context = [
        'tasks' => [
            ['id' => 1, 'title' => 'Task A', 'start_datetime' => null, 'end_datetime' => '2026-03-20T00:00:00+08:00'],
        ],
    ];

    $out = $this->sanitizer->sanitize(
        $structured,
        $context,
        LlmIntent::GeneralQuery,
        LlmEntityType::Task,
        'list my tasks with no due date'
    );

    expect($out['listed_items'])->toBeArray()->toBeEmpty()
        ->and($out['recommended_action'])->toContain('All your tasks have due dates')
        ->and($out['reasoning'])->toContain('every one has');
});

test('sanitize general_query with no due date and low priority uses AND semantics in empty message', function (): void {
    $structured = [
        'entity_type' => 'task',
        'recommended_action' => 'Here are your low priority tasks without due dates.',
        'reasoning' => 'Filtered.',
        'listed_items' => [],
    ];
    $context = [
        'tasks' => [
            // No-due-date task exists, but is medium priority (so the intersection is empty).
            ['id' => 1, 'title' => 'Undated medium task', 'start_datetime' => '2026-03-10T10:00:00+08:00', 'end_datetime' => null, 'priority' => 'medium'],
            // Low priority exists, but has a due date.
            ['id' => 2, 'title' => 'Low prio with due', 'start_datetime' => null, 'end_datetime' => '2026-03-20T00:00:00+08:00', 'priority' => 'low'],
        ],
    ];

    $out = $this->sanitizer->sanitize(
        $structured,
        $context,
        LlmIntent::GeneralQuery,
        LlmEntityType::Task,
        'List all my tasks that have no due date and low priority.'
    );

    expect($out['listed_items'])->toBeArray()->toBeEmpty()
        ->and($out['recommended_action'])->toContain('low priority')
        ->and($out['recommended_action'])->toContain('without a due date')
        ->and($out['recommended_action'])->not->toContain('All your tasks have due dates');
});

test('sanitize general_query with recurring filter builds list from context', function (): void {
    $structured = [
        'entity_type' => 'task',
        'recommended_action' => 'Here are your recurring tasks.',
        'reasoning' => 'Filtered.',
        'listed_items' => [['title' => 'Non-recurring task']],
    ];
    $context = [
        'tasks' => [
            ['id' => 1, 'title' => 'Recurring task', 'is_recurring' => true],
            ['id' => 2, 'title' => 'Non-recurring task', 'is_recurring' => false],
        ],
    ];

    $out = $this->sanitizer->sanitize(
        $structured,
        $context,
        LlmIntent::GeneralQuery,
        LlmEntityType::Task,
        'list tasks that are recurring'
    );

    expect($out['listed_items'])->toHaveCount(1)
        ->and($out['listed_items'][0]['title'])->toBe('Recurring task');
});

test('sanitize general_query with recurring and no due date uses human reasoning', function (): void {
    $structured = [
        'entity_type' => 'task',
        'recommended_action' => 'Here are your recurring tasks without due dates.',
        'reasoning' => 'I found tasks where end_datetime is null and is_recurring is true.',
        'listed_items' => [],
    ];
    $context = [
        'tasks' => [
            ['id' => 1, 'title' => 'long walk 10km', 'start_datetime' => '2026-03-10T10:00:00+08:00', 'end_datetime' => null, 'priority' => 'medium', 'is_recurring' => true],
        ],
    ];

    $out = $this->sanitizer->sanitize(
        $structured,
        $context,
        LlmIntent::GeneralQuery,
        LlmEntityType::Task,
        'List all my tasks that are recurring and have no due date.'
    );

    expect($out['listed_items'])->toHaveCount(1)
        ->and((string) ($out['reasoning'] ?? ''))->toContain('recurring')
        ->and((string) ($out['reasoning'] ?? ''))->toContain('no due date')
        ->and((string) ($out['reasoning'] ?? ''))->not->toContain('end_datetime')
        ->and((string) ($out['reasoning'] ?? ''))->not->toContain('is_recurring');
});

test('sanitize general_query with all-day filter builds list from context', function (): void {
    $structured = [
        'entity_type' => 'event',
        'recommended_action' => 'Here are your all-day events.',
        'reasoning' => 'Filtered.',
        'listed_items' => [['title' => 'Timed event']],
    ];
    $context = [
        'events' => [
            ['id' => 1, 'title' => 'All-day meeting', 'all_day' => true],
            ['id' => 2, 'title' => 'Timed event', 'all_day' => false],
        ],
    ];

    $out = $this->sanitizer->sanitize(
        $structured,
        $context,
        LlmIntent::GeneralQuery,
        LlmEntityType::Event,
        'which events are all-day?'
    );

    expect($out['listed_items'])->toHaveCount(1)
        ->and($out['listed_items'][0]['title'])->toBe('All-day meeting');
});

test('sanitize general_query with projects uses name for display', function (): void {
    $structured = [
        'entity_type' => 'project',
        'recommended_action' => 'Here are projects with no end date.',
        'reasoning' => 'Filtered.',
        'listed_items' => [],
    ];
    $context = [
        'projects' => [
            ['id' => 1, 'name' => 'Ongoing Project', 'start_datetime' => null, 'end_datetime' => null],
        ],
    ];

    $out = $this->sanitizer->sanitize(
        $structured,
        $context,
        LlmIntent::GeneralQuery,
        LlmEntityType::Project,
        'which projects have no end date?'
    );

    expect($out['listed_items'])->toHaveCount(1)
        ->and($out['listed_items'][0]['title'])->toBe('Ongoing Project');
});

test('sanitize general_query without date filter keeps items that exist in context', function (): void {
    $structured = [
        'entity_type' => 'task',
        'recommended_action' => 'Here are your low priority tasks.',
        'reasoning' => 'Filtered by priority.',
        'listed_items' => [
            ['title' => 'Task A', 'priority' => 'low'],
            ['title' => 'Hallucinated task', 'priority' => 'low'],
        ],
    ];
    $context = [
        'tasks' => [
            ['id' => 1, 'title' => 'Task A', 'start_datetime' => null, 'end_datetime' => '2026-03-20T00:00:00+08:00', 'priority' => 'low'],
        ],
    ];

    $out = $this->sanitizer->sanitize(
        $structured,
        $context,
        LlmIntent::GeneralQuery,
        LlmEntityType::Task,
        'which tasks have low priority?'
    );

    expect($out['listed_items'])->toHaveCount(1)
        ->and($out['listed_items'][0]['title'])->toBe('Task A')
        ->and($out['listed_items'][0]['priority'])->toBe('low');
});

test('sanitize general_query drop/delete queries build list from low priority tasks', function (): void {
    $structured = [
        'entity_type' => 'task',
        'recommended_action' => 'Here are some tasks you could drop.',
        'reasoning' => 'Model suggestion.',
        'listed_items' => [
            ['title' => 'Send email', 'priority' => 'low'],
            ['title' => 'Get contractor quotes', 'priority' => 'medium'],
            ['title' => 'Write chapter 1', 'priority' => 'high'],
        ],
    ];
    $context = [
        'tasks' => [
            ['id' => 1, 'title' => 'Send email', 'start_datetime' => null, 'end_datetime' => '2026-03-19T11:33:13+08:00', 'priority' => 'low'],
            ['id' => 2, 'title' => 'Get contractor quotes', 'start_datetime' => null, 'end_datetime' => '2026-03-17T10:21:38+08:00', 'priority' => 'medium'],
            ['id' => 3, 'title' => 'Write chapter 1', 'start_datetime' => '2026-02-26T04:34:32+08:00', 'end_datetime' => '2026-03-24T19:26:19+08:00', 'priority' => 'high'],
        ],
    ];

    $out = $this->sanitizer->sanitize(
        $structured,
        $context,
        LlmIntent::GeneralQuery,
        LlmEntityType::Task,
        'i have too many tasks help me decide what to drop'
    );

    expect($out['listed_items'])->toHaveCount(1)
        ->and($out['listed_items'][0]['title'])->toBe('Send email')
        ->and($out['listed_items'][0]['priority'])->toBe('low');
});

test('sanitize general_query with priority and complexity filter builds intersected list from context', function (): void {
    $structured = [
        'entity_type' => 'task',
        'recommended_action' => 'Here are your low-priority tasks with moderate complexity.',
        'reasoning' => 'Model suggestion.',
        'listed_items' => [
            ['title' => 'Send email', 'priority' => 'low', 'complexity' => 'moderate'],
            ['title' => 'Buy cake and balloons', 'priority' => 'low', 'complexity' => 'simple'],
        ],
    ];
    $context = [
        'tasks' => [
            ['id' => 1, 'title' => 'Send email', 'priority' => 'low', 'complexity' => 'moderate'],
            ['id' => 2, 'title' => 'Buy cake and balloons', 'priority' => 'low', 'complexity' => 'simple'],
        ],
    ];

    $out = $this->sanitizer->sanitize(
        $structured,
        $context,
        LlmIntent::GeneralQuery,
        LlmEntityType::Task,
        'list tasks that has low priority with moderate complexity'
    );

    expect($out['listed_items'])->toHaveCount(1)
        ->and($out['listed_items'][0]['title'])->toBe('Send email')
        ->and($out['listed_items'][0]['priority'])->toBe('low');
});

test('sanitize schedule task passes through raw output and only strips end_datetime', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-05 15:30:00', config('app.timezone')));

    $structured = [
        'entity_type' => 'task',
        'id' => 1,
        'title' => 'Output # 1: My Light to the Society for today.',
        'recommended_action' => 'Work on the top task.',
        'reasoning' => 'It is overdue and important.',
        'start_datetime' => '2026-03-05T10:00:00+08:00',
        'end_datetime' => '2026-03-05T13:00:00+08:00',
        'duration' => 180,
    ];

    $context = [
        'tasks' => [
            ['id' => 1, 'title' => 'Output # 1: My Light to the Society for today. It\'s overdue and urgent.'],
        ],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::ScheduleTask);

    expect($out)->toHaveKey('start_datetime')
        ->and($out['start_datetime'])->toBe('2026-03-05T10:00:00+08:00')
        ->and($out)->not->toHaveKey('end_datetime')
        ->and($out)->toHaveKey('duration')
        ->and($out['id'])->toBe(1)
        ->and($out['title'])->toBe('Output # 1: My Light to the Society for today.');
});

test('sanitize schedule task passes through start_datetime without same-day correction', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-07 12:00:00', config('app.timezone')));

    $structured = [
        'entity_type' => 'task',
        'id' => 1,
        'title' => 'Output # 2: EMILIAN - Due',
        'recommended_action' => 'Work on Output # 2 tonight at 8pm for 1 hour.',
        'reasoning' => 'Later evening fits.',
        'proposed_properties' => [
            'start_datetime' => '2026-03-08T20:00:00+08:00',
            'duration' => 60,
        ],
        'start_datetime' => '2026-03-08T20:00:00+08:00',
        'duration' => 60,
    ];

    $context = [
        'current_date' => '2026-03-07',
        'tasks' => [
            ['id' => 1, 'title' => 'Output # 2: EMILIAN - Due'],
        ],
    ];

    $out = $this->sanitizer->sanitize(
        $structured,
        $context,
        LlmIntent::ScheduleTask,
        null,
        'schedule my most important task for later evening'
    );

    expect($out['start_datetime'])->toBe('2026-03-08T20:00:00+08:00')
        ->and($out['duration'])->toBe(60)
        ->and($out)->not->toHaveKey('end_datetime');
});

test('sanitize schedule task does not correct when user said tomorrow', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-07 12:00:00', config('app.timezone')));

    $structured = [
        'entity_type' => 'task',
        'recommended_action' => 'Work on it tomorrow evening.',
        'reasoning' => 'Tomorrow fits.',
        'proposed_properties' => [
            'start_datetime' => '2026-03-08T20:00:00+08:00',
            'duration' => 60,
        ],
        'start_datetime' => '2026-03-08T20:00:00+08:00',
        'duration' => 60,
    ];

    $context = [
        'current_date' => '2026-03-07',
        'tasks' => [['id' => 1, 'title' => 'Some task']],
    ];

    $out = $this->sanitizer->sanitize(
        $structured,
        $context,
        LlmIntent::ScheduleTask,
        null,
        'schedule my most important task for tomorrow evening'
    );

    expect($out['start_datetime'])->toBe('2026-03-08T20:00:00+08:00')
        ->and($out['proposed_properties']['start_datetime'])->toBe('2026-03-08T20:00:00+08:00');
});

test('sanitize schedule task passes through id and title from LLM without correction', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-08 14:00:00', config('app.timezone')));

    $structured = [
        'entity_type' => 'task',
        'title' => 'Finish report draft',
        'id' => 999,
        'recommended_action' => 'Work on Finish report draft later today at 8pm for 1 hour.',
        'reasoning' => 'I chose 8pm because your evening is free.',
        'proposed_properties' => [
            'start_datetime' => '2026-03-08T20:00:00+08:00',
            'duration' => 60,
        ],
        'start_datetime' => '2026-03-08T20:00:00+08:00',
        'duration' => 60,
    ];

    $context = [
        'current_date' => '2026-03-08',
        'tasks' => [
            ['id' => 42, 'title' => 'Review chapter 5'],
        ],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::ScheduleTask);

    expect($out['title'])->toBe('Finish report draft')
        ->and($out['id'])->toBe(999)
        ->and($out['start_datetime'])->toBe('2026-03-08T20:00:00+08:00')
        ->and($out)->not->toHaveKey('end_datetime');
});

test('sanitize schedule task passes through LLM id and title without unmatched flag', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-09 10:00:00', config('app.timezone')));

    $structured = [
        'entity_type' => 'task',
        'title' => 'Output # 1: My Light to the Society',
        'id' => 999,
        'recommended_action' => 'Schedule your "My Light to the Society" output for later today at 5pm for 1 hour.',
        'reasoning' => 'I chose 5pm because your afternoon is free.',
        'start_datetime' => '2026-03-09T17:00:00+08:00',
        'duration' => 60,
    ];

    $context = [
        'current_date' => '2026-03-09',
        'tasks' => [
            ['id' => 6, 'title' => 'PRELIM DEPT EXAM: Komunikasyon Sa Akademikong Filipino_Set A_Departmental Exam_2526-2S - Availability Ends'],
        ],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::ScheduleTask);

    expect($out)->not->toHaveKey('_unmatched_task')
        ->and($out['id'])->toBe(999)
        ->and($out['title'])->toBe('Output # 1: My Light to the Society')
        ->and($out['start_datetime'])->toBe('2026-03-09T17:00:00+08:00')
        ->and($out)->not->toHaveKey('end_datetime');
});

test('sanitize schedule task with scheduling_hint uses LLM title when it matches a context task so Apply updates the right task', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-08 14:00:00', config('app.timezone')));

    $structured = [
        'entity_type' => 'task',
        'title' => 'Antas/Teorya ng wika - Due',
        'id' => 2,
        'recommended_action' => 'Work on Antas/Teorya ng wika - Due today at 8pm.',
        'reasoning' => 'I chose 8pm because Antas/Teorya is due tomorrow.',
        'start_datetime' => '2026-03-08T20:00:00+08:00',
        'duration' => 60,
    ];

    $context = [
        'current_date' => '2026-03-08',
        'scheduling_hint' => 'The user asked to schedule their top 1 / top task. The first task in the tasks list is the recommended one.',
        'tasks' => [
            ['id' => 1, 'title' => 'PRELIM DEP EXAM - Availability Ends'],
            ['id' => 2, 'title' => 'Antas/Teorya ng wika - Due'],
        ],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::ScheduleTask);

    expect($out['title'])->toBe('Antas/Teorya ng wika - Due')
        ->and($out['id'])->toBe(2)
        ->and($out['recommended_action'])->toContain('Antas/Teorya');
});

test('sanitize schedule task with scheduling_hint passes through LLM title and id as-is', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-08 14:00:00', config('app.timezone')));

    $structured = [
        'entity_type' => 'task',
        'title' => '',
        'id' => 99,
        'recommended_action' => 'Work on your top task today at 8pm.',
        'reasoning' => 'I chose 8pm because your evening is free.',
        'start_datetime' => '2026-03-08T20:00:00+08:00',
        'duration' => 60,
    ];

    $context = [
        'current_date' => '2026-03-08',
        'scheduling_hint' => 'The user asked to schedule their top task. The first task in the tasks list is the recommended one.',
        'tasks' => [
            ['id' => 1, 'title' => 'PRELIM DEP EXAM - Availability Ends'],
            ['id' => 2, 'title' => 'Antas/Teorya ng wika - Due'],
        ],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::ScheduleTask);

    expect($out['title'])->toBe('')
        ->and($out['id'])->toBe(99)
        ->and($out['start_datetime'])->toBe('2026-03-08T20:00:00+08:00');
});

test('sanitize schedule task passes through title when id missing from LLM', function (): void {
    $structured = [
        'entity_type' => 'task',
        'title' => 'Antas/Teorya ng wika - Due',
        'recommended_action' => 'Work on Antas/Teorya ng wika - Due at 8pm.',
        'reasoning' => 'Evening is free.',
        'start_datetime' => '2026-03-08T20:00:00+08:00',
        'duration' => 60,
    ];

    $context = [
        'tasks' => [
            ['id' => 101, 'title' => 'Other task'],
            ['id' => 102, 'title' => 'Antas/Teorya ng wika - Due'],
        ],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::ScheduleTask);

    expect($out['title'])->toBe('Antas/Teorya ng wika - Due')
        ->and($out)->not->toHaveKey('id')
        ->and($out['start_datetime'])->toBe('2026-03-08T20:00:00+08:00')
        ->and($out)->not->toHaveKey('end_datetime');
});

test('sanitize schedule_event with empty context overrides message for no events', function (): void {
    $structured = [
        'entity_type' => 'event',
        'recommended_action' => 'Schedule your exam.',
        'reasoning' => 'It is important.',
        'start_datetime' => '2026-03-10T09:00:00+00:00',
        'end_datetime' => '2026-03-10T10:00:00+00:00',
        'duration' => 60,
        'proposed_properties' => [
            'start_datetime' => '2026-03-10T09:00:00+00:00',
            'end_datetime' => '2026-03-10T10:00:00+00:00',
        ],
    ];

    $context = [
        'events' => [],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::ScheduleEvent);

    expect($out['recommended_action'])->toContain('no events')
        ->and($out['reasoning'])->toContain('events')
        ->and($out)->not->toHaveKey('start_datetime')
        ->and($out)->not->toHaveKey('end_datetime')
        ->and($out)->not->toHaveKey('duration')
        ->and($out)->not->toHaveKey('proposed_properties');
});

test('sanitize adjust_event_time binds id and title to real context event and drops invented titles', function (): void {
    $structured = [
        'entity_type' => 'event',
        'id' => 999,
        'title' => 'Math Exam',
        'recommended_action' => 'Schedule your next exam for this Friday at 9:00 AM.',
        'reasoning' => 'It avoids conflicts.',
    ];

    $context = [
        'events' => [
            ['id' => 1, 'title' => 'Math Exam'],
            ['id' => 2, 'title' => 'Physics quiz'],
        ],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::AdjustEventTime);

    expect($out['id'])->toBe(1)
        ->and($out['title'])->toBe('Math Exam')
        ->and($out['target_event_id'])->toBe(1)
        ->and($out['target_event_title'])->toBe('Math Exam');
});

test('sanitize adjust_event_time drops id and title when no matching context event exists', function (): void {
    $structured = [
        'entity_type' => 'event',
        'id' => 123,
        'title' => 'Completely invented event',
        'recommended_action' => 'Move this event.',
        'reasoning' => 'Narrative only.',
    ];

    $context = [
        'events' => [
            ['id' => 1, 'title' => 'Real Event A'],
        ],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::AdjustEventTime);

    expect($out)->not->toHaveKey('id')
        ->and($out)->not->toHaveKey('title')
        ->and($out)->not->toHaveKey('target_event_id')
        ->and($out)->not->toHaveKey('target_event_title');
});

test('sanitize schedule_task with empty context overrides message and strips proposed_properties', function (): void {
    $structured = [
        'entity_type' => 'task',
        'recommended_action' => 'Work on your task later.',
        'reasoning' => 'Evening is free.',
        'start_datetime' => '2026-03-10T19:00:00+00:00',
        'duration' => 60,
        'proposed_properties' => [
            'start_datetime' => '2026-03-10T19:00:00+00:00',
            'duration' => 60,
        ],
    ];

    $context = [
        'tasks' => [],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::ScheduleTask);

    expect($out['recommended_action'])->toContain('no tasks')
        ->and($out['reasoning'])->toContain('tasks')
        ->and($out)->not->toHaveKey('start_datetime')
        ->and($out)->not->toHaveKey('duration')
        ->and($out)->not->toHaveKey('proposed_properties');
});

test('sanitize schedule_project with empty context overrides message and strips proposed_properties', function (): void {
    $structured = [
        'entity_type' => 'project',
        'recommended_action' => 'Start the project next week.',
        'reasoning' => 'You have availability.',
        'start_datetime' => '2026-03-15T09:00:00+00:00',
        'end_datetime' => '2026-03-20T17:00:00+00:00',
        'proposed_properties' => [
            'start_datetime' => '2026-03-15T09:00:00+00:00',
            'end_datetime' => '2026-03-20T17:00:00+00:00',
        ],
    ];

    $context = [
        'projects' => [],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::ScheduleProject);

    expect($out['recommended_action'])->toContain('no projects')
        ->and($out['reasoning'])->toContain('projects')
        ->and($out)->not->toHaveKey('start_datetime')
        ->and($out)->not->toHaveKey('end_datetime')
        ->and($out)->not->toHaveKey('proposed_properties');
});

test('sanitize schedule_events_and_projects with empty context overrides message', function (): void {
    $structured = [
        'entity_type' => 'event,project',
        'recommended_action' => 'Schedule items.',
        'reasoning' => 'Based on availability.',
        'scheduled_events' => [
            ['title' => 'Hallucinated Event', 'start_datetime' => '2026-03-12T14:00:00+00:00', 'end_datetime' => '2026-03-12T15:00:00+00:00'],
        ],
        'scheduled_projects' => [
            ['name' => 'Fake Project', 'start_datetime' => '2026-03-15T09:00:00+00:00', 'end_datetime' => '2026-03-17T17:00:00+00:00'],
        ],
    ];

    $context = [
        'events' => [],
        'projects' => [],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::ScheduleEventsAndProjects);

    expect($out['scheduled_events'])->toBeArray()->toBeEmpty()
        ->and($out['scheduled_projects'])->toBeArray()->toBeEmpty()
        ->and($out['recommended_action'])->toContain('no events or projects');
});

test('sanitize list filter search intent rebuilds listed items from context', function (): void {
    $structured = [
        'entity_type' => 'task',
        'listed_items' => [
            ['title' => 'Hallucinated item'],
        ],
        'recommended_action' => 'Stub action',
        'reasoning' => 'Stub reasoning',
    ];

    $context = [
        'tasks' => [
            ['id' => 1, 'title' => 'Task without due date', 'end_datetime' => null, 'start_datetime' => null],
            ['id' => 2, 'title' => 'Task with due date', 'end_datetime' => '2026-03-20T10:00:00+08:00', 'start_datetime' => null],
        ],
    ];

    $out = $this->sanitizer->sanitize(
        $structured,
        $context,
        LlmIntent::ListFilterSearch,
        LlmEntityType::Task,
        'show tasks with no due date'
    );

    expect($out['listed_items'])->toHaveCount(1)
        ->and($out['listed_items'][0]['title'])->toBe('Task without due date');
});
