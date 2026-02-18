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

test('workspace index getActiveFocusSession returns null after mount because mount ends any in-progress session', function (): void {
    $this->actingAs($this->user);
    $task = Task::factory()->for($this->user)->create();
    FocusSession::factory()->for($this->user)->inProgress()->create([
        'focusable_type' => Task::class,
        'focusable_id' => $task->id,
    ]);

    $component = Livewire::test('pages::workspace.index')->instance();
    $result = $component->getActiveFocusSession();

    expect($result)->toBeNull();
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

test('workspace index startFocusSession persists focus_mode_type column and payload for pomodoro', function (): void {
    $this->actingAs($this->user);
    $task = Task::factory()->for($this->user)->create();

    $payload = [
        'type' => 'work',
        'duration_seconds' => 1500,
        'started_at' => now()->toIso8601String(),
        'sequence_number' => 1,
        'payload' => [
            'focus_mode_type' => 'pomodoro',
        ],
    ];

    $component = Livewire::test('pages::workspace.index')
        ->call('startFocusSession', $task->id, $payload)
        ->assertNotDispatched('toast', type: 'error');

    $active = $component->get('activeFocusSession');
    expect($active)->not->toBeNull()
        ->and($active['focus_mode_type'] ?? null)->toBe('pomodoro');

    $session = FocusSession::query()->where('user_id', $this->user->id)->inProgress()->first();
    expect($session)->not->toBeNull()
        ->and($session->focus_mode_type->value)->toBe('pomodoro')
        ->and(($session->payload['focus_mode_type'] ?? null))->toBe('pomodoro');
});

test('workspace index abandonFocusSession ends session and dispatches toast', function (): void {
    $this->actingAs($this->user);
    $task = Task::factory()->for($this->user)->create();
    $payload = [
        'type' => 'work',
        'duration_seconds' => 1500,
        'started_at' => now()->toIso8601String(),
        'sequence_number' => 1,
    ];

    Livewire::test('pages::workspace.index')
        ->call('startFocusSession', $task->id, $payload);

    $session = FocusSession::query()->forUser($this->user->id)->inProgress()->first();
    expect($session)->not->toBeNull();

    Livewire::test('pages::workspace.index')
        ->call('abandonFocusSession', $session->id)
        ->assertDispatched('toast', type: 'info', message: __('Focus session stopped.'));

    $session->refresh();
    expect($session->ended_at)->not->toBeNull()
        ->and($session->completed)->toBeFalse();
});

test('workspace index completeFocusSession updates session and dispatches toast', function (): void {
    $this->actingAs($this->user);
    $task = Task::factory()->for($this->user)->create();
    $startPayload = [
        'type' => 'work',
        'duration_seconds' => 1500,
        'started_at' => now()->toIso8601String(),
        'sequence_number' => 1,
    ];

    $component = Livewire::test('pages::workspace.index')
        ->call('startFocusSession', $task->id, $startPayload);

    $session = FocusSession::query()->forUser($this->user->id)->inProgress()->first();
    expect($session)->not->toBeNull();

    $completePayload = [
        'ended_at' => now()->toIso8601String(),
        'completed' => true,
        'paused_seconds' => 0,
    ];

    $component->call('completeFocusSession', $session->id, $completePayload)
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

test('workspace index pauseFocusSession sets paused_at on session', function (): void {
    $this->actingAs($this->user);
    $task = Task::factory()->for($this->user)->create();
    $payload = [
        'type' => 'work',
        'duration_seconds' => 1500,
        'started_at' => now()->toIso8601String(),
        'sequence_number' => 1,
    ];

    $component = Livewire::test('pages::workspace.index')
        ->call('startFocusSession', $task->id, $payload);

    $session = FocusSession::query()->forUser($this->user->id)->inProgress()->first();
    expect($session)->not->toBeNull();

    $component->call('pauseFocusSession', $session->id)
        ->assertOk();

    $session->refresh();
    expect($session->paused_at)->not->toBeNull();
});

test('workspace index resumeFocusSession clears paused_at and adds segment to paused_seconds', function (): void {
    $this->actingAs($this->user);
    $task = Task::factory()->for($this->user)->create();
    $payload = [
        'type' => 'work',
        'duration_seconds' => 1500,
        'started_at' => now()->toIso8601String(),
        'sequence_number' => 1,
    ];

    $component = Livewire::test('pages::workspace.index')
        ->call('startFocusSession', $task->id, $payload);

    $session = FocusSession::query()->forUser($this->user->id)->inProgress()->first();
    expect($session)->not->toBeNull();

    $component->call('pauseFocusSession', $session->id)
        ->assertOk();

    $session->refresh();
    expect($session->paused_at)->not->toBeNull();

    $component->call('resumeFocusSession', $session->id)
        ->assertOk();

    $session->refresh();
    expect($session->paused_at)->toBeNull()
        ->and($session->paused_seconds)->toBeGreaterThanOrEqual(0);
});

test('workspace index pauseFocusSession returns false when session is already ended', function (): void {
    $this->actingAs($this->user);
    $session = FocusSession::factory()->for($this->user)->create([
        'ended_at' => now(),
        'completed' => false,
    ]);

    $component = Livewire::test('pages::workspace.index')
        ->call('pauseFocusSession', $session->id);

    expect($component->get('activeFocusSession'))->toBeNull();

    $session->refresh();
    expect($session->paused_at)->toBeNull();
});

test('workspace index mount ends any active focus session on load so focus does not persist across reload', function (): void {
    $this->actingAs($this->user);
    $task = Task::factory()->for($this->user)->create();
    $session = FocusSession::factory()->for($this->user)->inProgress()->create([
        'focusable_type' => Task::class,
        'focusable_id' => $task->id,
        'started_at' => now()->subMinutes(5),
    ]);

    Livewire::test('pages::workspace.index')
        ->assertSet('activeFocusSession', null);

    $session->refresh();
    expect($session->ended_at)->not->toBeNull()
        ->and($session->completed)->toBeFalse();
});

test('workspace index startBreakSession with task_id stores focusable_type and focusable_id for short break', function (): void {
    $this->actingAs($this->user);
    $task = Task::factory()->for($this->user)->create();
    $payload = [
        'type' => 'short_break',
        'duration_seconds' => 300,
        'started_at' => now()->toIso8601String(),
        'sequence_number' => 1,
        'task_id' => $task->id,
    ];

    Livewire::test('pages::workspace.index')
        ->call('startBreakSession', $payload)
        ->assertNotDispatched('toast', type: 'error');

    $session = FocusSession::query()->where('user_id', $this->user->id)->inProgress()->first();
    expect($session)->not->toBeNull()
        ->and($session->focusable_type)->toBe(Task::class)
        ->and($session->focusable_id)->toBe($task->id)
        ->and($session->type->value)->toBe('short_break');
});

test('workspace index startBreakSession with task_id stores focusable_type and focusable_id for long break', function (): void {
    $this->actingAs($this->user);
    $task = Task::factory()->for($this->user)->create();
    $payload = [
        'type' => 'long_break',
        'duration_seconds' => 900,
        'started_at' => now()->toIso8601String(),
        'sequence_number' => 2,
        'task_id' => $task->id,
    ];

    Livewire::test('pages::workspace.index')
        ->call('startBreakSession', $payload)
        ->assertNotDispatched('toast', type: 'error');

    $session = FocusSession::query()->where('user_id', $this->user->id)->inProgress()->first();
    expect($session)->not->toBeNull()
        ->and($session->focusable_type)->toBe(Task::class)
        ->and($session->focusable_id)->toBe($task->id)
        ->and($session->type->value)->toBe('long_break');
});
