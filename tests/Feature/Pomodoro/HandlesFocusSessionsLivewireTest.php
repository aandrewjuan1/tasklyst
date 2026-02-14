<?php

use App\Models\FocusSession;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('workspace index getActiveFocusSession returns null when user has no in-progress session', function (): void {
    $this->actingAs($this->user);

    $component = Livewire::test('pages::workspace.index')->instance();
    $result = $component->getActiveFocusSession();

    expect($result)->toBeNull();
});

test('workspace index getActiveFocusSession returns session array when user has in-progress session', function (): void {
    $this->actingAs($this->user);
    $task = Task::factory()->for($this->user)->create();
    $session = FocusSession::factory()->for($this->user)->inProgress()->create([
        'focusable_type' => Task::class,
        'focusable_id' => $task->id,
    ]);

    $component = Livewire::test('pages::workspace.index')->instance();
    $result = $component->getActiveFocusSession();

    expect($result)->toBeArray()
        ->and($result['id'])->toBe($session->id)
        ->and($result['task_id'])->toBe($task->id)
        ->and($result['duration_seconds'])->toBe($session->duration_seconds)
        ->and($result['type'])->toBe($session->type->value);
});

test('workspace index startFocusSession creates session and dispatches no error toast', function (): void {
    $this->actingAs($this->user);
    $task = Task::factory()->for($this->user)->create();
    $payload = [
        'type' => 'work',
        'duration_seconds' => 1500,
        'started_at' => now()->toIso8601String(),
        'sequence_number' => 1,
    ];

    Livewire::test('pages::workspace.index')
        ->call('startFocusSession', $task->id, $payload)
        ->assertNotDispatched('toast', type: 'error');

    $session = FocusSession::query()->where('user_id', $this->user->id)->inProgress()->first();
    expect($session)->not->toBeNull()
        ->and($session->focusable_id)->toBe($task->id)
        ->and($session->type->value)->toBe('work');
});

test('workspace index abandonFocusSession ends session and dispatches toast', function (): void {
    $this->actingAs($this->user);
    $session = FocusSession::factory()->for($this->user)->inProgress()->create();

    Livewire::test('pages::workspace.index')
        ->call('abandonFocusSession', $session->id)
        ->assertDispatched('toast', type: 'info', message: __('Focus session stopped.'));

    $session->refresh();
    expect($session->ended_at)->not->toBeNull()
        ->and($session->completed)->toBeFalse();
});

test('workspace index completeFocusSession updates session and dispatches toast', function (): void {
    $this->actingAs($this->user);
    $session = FocusSession::factory()->for($this->user)->inProgress()->create();
    $payload = [
        'ended_at' => now()->toIso8601String(),
        'completed' => true,
        'paused_seconds' => 0,
    ];

    Livewire::test('pages::workspace.index')
        ->call('completeFocusSession', $session->id, $payload)
        ->assertDispatched('toast', type: 'success', message: __('Focus session saved.'));

    $session->refresh();
    expect($session->completed)->toBeTrue()
        ->and($session->ended_at)->not->toBeNull();
});

test('workspace index startFocusSession with invalid type dispatches error and creates no session', function (): void {
    $this->actingAs($this->user);
    $task = Task::factory()->for($this->user)->create();
    $payload = [
        'type' => 'short_break',
        'duration_seconds' => 300,
        'started_at' => now()->toIso8601String(),
    ];

    Livewire::test('pages::workspace.index')
        ->call('startFocusSession', $task->id, $payload)
        ->assertDispatched('toast', type: 'error');

    expect(FocusSession::query()->forUser($this->user->id)->inProgress()->count())->toBe(0);
});
