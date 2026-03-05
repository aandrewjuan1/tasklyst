<?php

use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Services\Llm\StructuredOutputSanitizer;

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
        ->and($out['recommended_action'])->toContain('no events')
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

test('sanitize prioritize_tasks with empty context strips ranked_tasks', function (): void {
    $structured = [
        'entity_type' => 'task',
        'ranked_tasks' => [['rank' => 1, 'title' => 'Fake task']],
    ];
    $context = ['tasks' => []];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::PrioritizeTasks);

    expect($out['ranked_tasks'])->toBeArray()->toBeEmpty();
});

test('sanitize non prioritize intent returns structured unchanged', function (): void {
    $structured = ['entity_type' => 'task', 'recommended_action' => 'Do X', 'reasoning' => 'Because'];
    $context = ['tasks' => []];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::ScheduleTask);

    expect($out)->toEqual($structured);
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
            ['rank' => 2, 'title' => 'Fake Event'],
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
            ['rank' => 2, 'name' => 'Fake Project'],
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
            ['rank' => 2, 'name' => 'Fake Project'],
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

test('sanitize ScheduleTasksAndEvents keeps only tasks and events that exist in context', function (): void {
    $structured = [
        'entity_type' => 'task,event',
        'recommended_action' => 'Suggested times below.',
        'reasoning' => 'Based on availability.',
        'scheduled_tasks' => [
            ['title' => 'Real Task', 'start_datetime' => '2026-03-10T09:00:00+00:00', 'end_datetime' => '2026-03-10T10:00:00+00:00'],
            ['title' => 'Hallucinated Task', 'start_datetime' => '2026-03-11T09:00:00+00:00', 'end_datetime' => '2026-03-11T10:00:00+00:00'],
        ],
        'scheduled_events' => [
            ['title' => 'Real Event', 'start_datetime' => '2026-03-12T14:00:00+00:00', 'end_datetime' => '2026-03-12T15:00:00+00:00'],
            ['title' => 'Fake Event', 'start_datetime' => '2026-03-13T14:00:00+00:00', 'end_datetime' => '2026-03-13T15:00:00+00:00'],
        ],
    ];
    $context = [
        'tasks' => [['id' => 1, 'title' => 'Real Task']],
        'events' => [['id' => 1, 'title' => 'Real Event']],
    ];

    $out = $this->sanitizer->sanitize($structured, $context, LlmIntent::ScheduleTasksAndEvents);

    expect($out['scheduled_tasks'])->toHaveCount(1)
        ->and($out['scheduled_tasks'][0]['title'])->toBe('Real Task')
        ->and($out['scheduled_tasks'][0]['start_datetime'])->toBe('2026-03-10T09:00:00+00:00')
        ->and($out['scheduled_events'])->toHaveCount(1)
        ->and($out['scheduled_events'][0]['title'])->toBe('Real Event')
        ->and($out['scheduled_events'][0]['start_datetime'])->toBe('2026-03-12T14:00:00+00:00');
});

test('sanitize ScheduleAll keeps only scheduled items that exist in context', function (): void {
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
            ['name' => 'Fake Project', 'start_datetime' => '2026-03-18T09:00:00+00:00', 'end_datetime' => '2026-03-19T17:00:00+00:00'],
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
        ->and($out['scheduled_events'])->toHaveCount(1)
        ->and($out['scheduled_events'][0]['title'])->toBe('Real Event')
        ->and($out['scheduled_projects'])->toHaveCount(1)
        ->and($out['scheduled_projects'][0]['name'])->toBe('Real Project');
});

test('sanitize prioritize_tasks accepts fuzzy title match and normalizes to context title', function (): void {
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

    expect($out['ranked_tasks'])->toHaveCount(1)
        ->and($out['ranked_tasks'][0]['title'])->toBe('Ortograpiya/ Barayti ng wika - Due')
        ->and($out['ranked_tasks'][0]['rank'])->toBe(1);
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
