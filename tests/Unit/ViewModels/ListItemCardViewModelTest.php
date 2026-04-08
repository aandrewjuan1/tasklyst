<?php

use App\Enums\FocusSessionType;
use App\Models\Event;
use App\Models\FocusSession;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\ViewModels\ListItemCardViewModel;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->task = Task::factory()->for($this->user)->create(['title' => 'Test Task']);
});

it('produces viewData with expected keys for a task', function () {
    $this->actingAs($this->user);
    $vm = new ListItemCardViewModel(
        kind: 'task',
        item: $this->task,
        listFilterDate: '2025-02-15',
        filters: [],
        availableTags: [],
        isOverdue: false,
        activeFocusSession: null,
        defaultWorkDurationMinutes: 25,
    );

    $data = $vm->viewData();

    expect($data)->toHaveKeys([
        'kind', 'title', 'description', 'sourceUrl', 'type', 'deleteMethod', 'updatePropertyMethod',
        'owner', 'hasCollaborators', 'currentUserIsOwner', 'showOwnerBadge',
        'canEdit', 'canEditTags', 'canEditDates', 'canEditRecurrence', 'canDelete',
        'focusModeDefaultHint', 'headerRecurrenceInitial', 'item', 'listFilterDate', 'filters', 'availableTags',
    ]);
    expect($data['kind'])->toBe('task');
    expect($data['title'])->toBe('Test Task');
    expect($data['sourceUrl'])->toBeNull();
    expect($data['deleteMethod'])->toBe('deleteTask');
    expect($data['updatePropertyMethod'])->toBe('updateTaskProperty');
});

it('includes sourceUrl when task has a source_url', function () {
    $this->actingAs($this->user);
    $taskWithUrl = Task::factory()->for($this->user)->create([
        'title' => 'Task with URL',
        'source_url' => 'https://brightspace.example.com/item/123',
    ]);

    $vm = new ListItemCardViewModel(
        kind: 'task',
        item: $taskWithUrl,
        listFilterDate: null,
        filters: [],
        availableTags: [],
        isOverdue: false,
        activeFocusSession: null,
        defaultWorkDurationMinutes: 25,
    );

    $data = $vm->viewData();
    $config = $vm->alpineConfig();

    expect($data['sourceUrl'])->toBe('https://brightspace.example.com/item/123');
    expect($config['sourceUrl'])->toBe('https://brightspace.example.com/item/123');
});

it('produces alpineConfig with expected keys and no callables for a task', function () {
    $this->actingAs($this->user);
    $vm = new ListItemCardViewModel(
        kind: 'task',
        item: $this->task,
        listFilterDate: '2025-02-15',
        filters: [],
        availableTags: [],
        isOverdue: false,
        activeFocusSession: null,
        defaultWorkDurationMinutes: 25,
    );

    $config = $vm->alpineConfig();

    expect($config)->toHaveKeys([
        'kind', 'itemId', 'canEdit', 'canDelete', 'deleteMethod', 'updatePropertyMethod',
        'editedTitle', 'recurrence', 'activeFocusSession', 'defaultWorkDurationMinutes', 'taskDurationMinutes',
        'focusModeType', 'focusModeTypes', 'focusModeComingSoonToast',
        'hasTaskDurationTarget', 'taskTargetDurationSeconds', 'taskFocusSpentSeconds', 'taskFocusRemainingSeconds', 'taskFocusProgressPercent',
    ]);
    expect($config['kind'])->toBe('task');
    expect($config['itemId'])->toBe($this->task->id);
    expect($config['editedTitle'])->toBe('Test Task');
    expect($config['focusModeType'])->toBe('countdown');
    expect($config['focusModeTypes'])->toBeArray();
    expect($config['focusModeTypes'])->toContain(['value' => 'countdown', 'label' => 'Sprint', 'available' => true]);

    foreach ($config as $value) {
        expect($value)->not->toBeInstanceOf(\Closure::class);
    }
});

it('accepts collection for availableTags and normalizes to array', function () {
    $this->actingAs($this->user);
    $tags = collect([['id' => 1, 'name' => 'Tag1']]);
    $vm = new ListItemCardViewModel(
        kind: 'task',
        item: $this->task,
        listFilterDate: null,
        filters: [],
        availableTags: $tags,
        isOverdue: false,
        activeFocusSession: null,
        defaultWorkDurationMinutes: 25,
    );

    $data = $vm->viewData();
    expect($data['availableTags'])->toBeArray();
    expect($data['availableTags'])->toHaveCount(1);
});

it('hides project and event pills when parent project or event is trashed', function () {
    $this->actingAs($this->user);
    $project = Project::factory()->for($this->user)->create(['name' => 'My Project']);
    $event = Event::factory()->for($this->user)->create(['title' => 'My Event']);
    $task = Task::factory()->for($this->user)->create([
        'title' => 'Subtask',
        'project_id' => $project->id,
        'event_id' => $event->id,
    ]);
    $project->delete();
    $event->delete();
    $task->refresh();
    expect($task->project_id)->toBe($project->id);
    expect($task->event_id)->toBe($event->id);
    expect($task->project)->toBeNull();
    expect($task->event)->toBeNull();

    $vm = new ListItemCardViewModel(
        kind: 'task',
        item: $task,
        listFilterDate: null,
        filters: [],
        availableTags: [],
        isOverdue: false,
        activeFocusSession: null,
        defaultWorkDurationMinutes: 25,
    );
    $config = $vm->alpineConfig();

    expect($config['showProjectPill'])->toBeFalse();
    expect($config['showEventPill'])->toBeFalse();
    expect($config['itemProjectName'])->toBeNull();
    expect($config['itemEventTitle'])->toBeNull();
});

it('shows project and event pills when parents exist and are not trashed', function () {
    $this->actingAs($this->user);
    $project = Project::factory()->for($this->user)->create(['name' => 'My Project']);
    $event = Event::factory()->for($this->user)->create(['title' => 'My Event']);
    $task = Task::factory()->for($this->user)->create([
        'title' => 'Subtask',
        'project_id' => $project->id,
        'event_id' => $event->id,
    ]);
    $task->load(['project', 'event']);

    $vm = new ListItemCardViewModel(
        kind: 'task',
        item: $task,
        listFilterDate: null,
        filters: [],
        availableTags: [],
        isOverdue: false,
        activeFocusSession: null,
        defaultWorkDurationMinutes: 25,
    );
    $config = $vm->alpineConfig();

    expect($config['showProjectPill'])->toBeTrue();
    expect($config['showEventPill'])->toBeTrue();
    expect($config['itemProjectName'])->toBe('My Project');
    expect($config['itemEventTitle'])->toBe('My Event');
});

it('includes previous unfinished focus session payload for task cards', function () {
    $this->actingAs($this->user);
    $task = Task::factory()->for($this->user)->create(['title' => 'Task with unfinished focus']);
    $session = FocusSession::factory()
        ->for($this->user)
        ->for($task, 'focusable')
        ->work()
        ->create([
            'completed' => false,
            'duration_seconds' => 1500,
            'paused_seconds' => 180,
            'started_at' => now()->subMinutes(15),
            'ended_at' => now()->subMinutes(2),
        ]);
    $task->load('latestUnfinishedFocusSession');

    $vm = new ListItemCardViewModel(
        kind: 'task',
        item: $task,
        listFilterDate: null,
        filters: [],
        availableTags: [],
        isOverdue: false,
        activeFocusSession: null,
        defaultWorkDurationMinutes: 25,
    );

    $config = $vm->alpineConfig();

    expect($config['previousUnfinishedSession'])->toBeArray()
        ->and($config['previousUnfinishedSession']['id'])->toBe($session->id)
        ->and($config['previousUnfinishedSession']['task_id'])->toBe($task->id)
        ->and($config['previousUnfinishedSession']['completed'])->toBeFalse()
        ->and($config['previousUnfinishedSession']['duration_seconds'])->toBe(1500);
});

it('hides task progress when task duration is not set', function () {
    $this->actingAs($this->user);
    $task = Task::factory()->for($this->user)->create([
        'title' => 'No duration task',
        'duration' => null,
    ]);

    FocusSession::factory()
        ->for($this->user)
        ->for($task, 'focusable')
        ->work()
        ->create([
            'started_at' => now()->subMinutes(10),
            'ended_at' => now()->subMinutes(5),
            'paused_seconds' => 0,
        ]);

    $task->load('focusSessions');

    $config = (new ListItemCardViewModel(
        kind: 'task',
        item: $task,
        listFilterDate: null,
        filters: [],
        availableTags: [],
        isOverdue: false,
        activeFocusSession: null,
        defaultWorkDurationMinutes: 25,
    ))->alpineConfig();

    expect($config['hasTaskDurationTarget'])->toBeFalse()
        ->and($config['taskTargetDurationSeconds'])->toBe(0)
        ->and($config['taskFocusSpentSeconds'])->toBe(0)
        ->and($config['taskFocusRemainingSeconds'])->toBe(0)
        ->and($config['taskFocusProgressPercent'])->toBe(0);
});

it('computes task progress from ended and paused work sessions only', function () {
    $this->actingAs($this->user);
    $task = Task::factory()->for($this->user)->create([
        'title' => 'Progress task',
        'duration' => 60,
    ]);

    FocusSession::factory()
        ->for($this->user)
        ->for($task, 'focusable')
        ->work()
        ->create([
            'started_at' => now()->subMinutes(70),
            'ended_at' => now()->subMinutes(50),
            'paused_seconds' => 120,
            'completed' => true,
        ]);

    FocusSession::factory()
        ->for($this->user)
        ->for($task, 'focusable')
        ->work()
        ->create([
            'started_at' => now()->subMinutes(30),
            'paused_at' => now()->subMinutes(20),
            'ended_at' => null,
            'paused_seconds' => 60,
            'completed' => false,
        ]);

    FocusSession::factory()
        ->for($this->user)
        ->for($task, 'focusable')
        ->inProgress()
        ->work()
        ->create([
            'started_at' => now()->subMinutes(5),
            'paused_at' => null,
            'ended_at' => null,
            'paused_seconds' => 0,
            'completed' => false,
        ]);

    FocusSession::factory()
        ->for($this->user)
        ->for($task, 'focusable')
        ->create([
            'type' => FocusSessionType::ShortBreak,
            'started_at' => now()->subMinutes(15),
            'ended_at' => now()->subMinutes(10),
            'paused_seconds' => 0,
            'completed' => true,
        ]);

    $task->load('focusSessions');

    $config = (new ListItemCardViewModel(
        kind: 'task',
        item: $task,
        listFilterDate: null,
        filters: [],
        availableTags: [],
        isOverdue: false,
        activeFocusSession: null,
        defaultWorkDurationMinutes: 25,
    ))->alpineConfig();

    // Ended/completed work: 20m - 2m pause = 1080s
    // Paused work: 10m - 1m pause = 540s
    // Active in-progress work excluded from base.
    $expectedSpent = 1620;
    $expectedTarget = 3600;
    $expectedRemaining = 1980;
    $expectedPercent = 45;

    expect($config['hasTaskDurationTarget'])->toBeTrue()
        ->and($config['taskTargetDurationSeconds'])->toBe($expectedTarget)
        ->and($config['taskFocusSpentSeconds'])->toBe($expectedSpent)
        ->and($config['taskFocusRemainingSeconds'])->toBe($expectedRemaining)
        ->and($config['taskFocusProgressPercent'])->toBe($expectedPercent);
});
