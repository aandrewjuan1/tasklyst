<?php

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
        'kind', 'title', 'description', 'type', 'deleteMethod', 'updatePropertyMethod',
        'owner', 'hasCollaborators', 'currentUserIsOwner', 'showOwnerBadge',
        'canEdit', 'canEditTags', 'canEditDates', 'canEditRecurrence', 'canDelete',
        'focusModeDefaultHint', 'headerRecurrenceInitial', 'item', 'listFilterDate', 'filters', 'availableTags',
    ]);
    expect($data['kind'])->toBe('task');
    expect($data['title'])->toBe('Test Task');
    expect($data['deleteMethod'])->toBe('deleteTask');
    expect($data['updatePropertyMethod'])->toBe('updateTaskProperty');
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
    ]);
    expect($config['kind'])->toBe('task');
    expect($config['itemId'])->toBe($this->task->id);
    expect($config['editedTitle'])->toBe('Test Task');

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
