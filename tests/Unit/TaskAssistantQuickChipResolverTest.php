<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantQuickChipResolver;
use Carbon\CarbonImmutable;

test('resolver anchors morning chips with plan today and returns four chips', function (): void {
    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-20 08:30:00', $timezone));

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->for($user)->create();

    $chips = app(TaskAssistantQuickChipResolver::class)->resolveForEmptyState($user, $thread, 4);

    expect($chips)->toHaveCount(4);
    expect($chips[0] ?? null)->toBe('Create a plan for today');

    CarbonImmutable::setTestNow();
});

test('resolver includes both evening chips when pending schedule fallback exists', function (): void {
    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-20 20:45:00', $timezone));

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->for($user)->create([
        'metadata' => [
            'conversation_state' => [
                'pending_schedule_fallback' => [
                    'schedule_data' => ['confirmation_required' => true],
                ],
            ],
        ],
    ]);

    Task::factory()->for($user)->create([
        'status' => TaskStatus::ToDo,
        'priority' => TaskPriority::Urgent,
        'start_datetime' => null,
        'end_datetime' => now()->subHour(),
        'completed_at' => null,
    ]);

    $chips = app(TaskAssistantQuickChipResolver::class)->resolveForEmptyState($user, $thread, 4);

    expect($chips)->toHaveCount(4);
    expect($chips)->toContain('Create a plan for tomorrow');
    expect($chips)->toContain('Schedule top 1 for later');
    expect($chips)->not->toContain('Create a plan for today');
    expect($chips)->not->toContain('Continue my pending schedule draft');
    expect($chips[0] ?? null)->toBe('Create a plan for tomorrow');

    CarbonImmutable::setTestNow();
});

test('resolver avoids tomorrow planning chip in morning', function (): void {
    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-20 09:00:00', $timezone));

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->for($user)->create();

    $chips = app(TaskAssistantQuickChipResolver::class)->resolveForEmptyState($user, $thread, 4);

    expect($chips)->toHaveCount(4);
    expect($chips)->not->toContain('Create a plan for tomorrow');
    expect($chips)->toContain('Create a plan for today');

    CarbonImmutable::setTestNow();
});

test('resolver uses afternoon semantics right before evening cutoff', function (): void {
    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-20 16:59:00', $timezone));

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->for($user)->create();

    $chips = app(TaskAssistantQuickChipResolver::class)->resolveForEmptyState($user, $thread, 4);

    expect($chips)->toHaveCount(4);
    expect($chips)->toContain('Create a plan for today');
    expect($chips)->not->toContain('Create a plan for tomorrow');

    CarbonImmutable::setTestNow();
});

test('resolver switches to evening semantics at cutoff hour', function (): void {
    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-20 17:00:00', $timezone));

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->for($user)->create();

    $chips = app(TaskAssistantQuickChipResolver::class)->resolveForEmptyState($user, $thread, 4);

    expect($chips)->toHaveCount(4);
    expect($chips)->toContain('Create a plan for tomorrow');
    expect($chips)->not->toContain('Create a plan for today');

    CarbonImmutable::setTestNow();
});

test('resolver late night still avoids plan today and keeps tomorrow intent', function (): void {
    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-20 23:30:00', $timezone));

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->for($user)->create();

    $chips = app(TaskAssistantQuickChipResolver::class)->resolveForEmptyState($user, $thread, 4);

    expect($chips)->toHaveCount(4);
    expect($chips)->toContain('Create a plan for tomorrow');
    expect($chips)->not->toContain('Create a plan for today');

    CarbonImmutable::setTestNow();
});

test('resolver morning avoids later-window and reprioritize chips', function (): void {
    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-20 07:30:00', $timezone));

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->for($user)->create();

    $chips = app(TaskAssistantQuickChipResolver::class)->resolveForEmptyState($user, $thread, 6);

    expect($chips)->not->toContain('Schedule top 1 for later');
    expect($chips)->not->toContain('Re-prioritize my remaining tasks');
    expect($chips)->toContain('Create a plan for today');

    CarbonImmutable::setTestNow();
});

test('resolver late night avoids schedule most important and reprioritize', function (): void {
    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-20 23:10:00', $timezone));

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->for($user)->create();

    $chips = app(TaskAssistantQuickChipResolver::class)->resolveForEmptyState($user, $thread, 6);

    expect($chips)->not->toContain('Schedule my most important task');
    expect($chips)->not->toContain('Re-prioritize my remaining tasks');
    expect($chips)->toContain('Create a plan for tomorrow');

    CarbonImmutable::setTestNow();
});

test('resolver evening allows reprioritize and later scheduling chips', function (): void {
    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-20 19:00:00', $timezone));

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->for($user)->create();

    $chips = app(TaskAssistantQuickChipResolver::class)->resolveForEmptyState($user, $thread, 6);

    expect($chips)->toContain('Re-prioritize my remaining tasks');
    expect($chips)->toContain('Schedule top 1 for later');
    expect($chips)->toContain('Create a plan for tomorrow');

    CarbonImmutable::setTestNow();
});

test('resolver sunday late night avoids plan today and prefers tomorrow planning', function (): void {
    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-19 22:30:00', $timezone)); // Sunday

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->for($user)->create();

    $chips = app(TaskAssistantQuickChipResolver::class)->resolveForEmptyState($user, $thread, 6);

    expect($chips)->not->toContain('Create a plan for today');
    expect($chips)->toContain('Create a plan for tomorrow');

    CarbonImmutable::setTestNow();
});

test('resolver friday evening avoids plan tomorrow chip', function (): void {
    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-17 19:15:00', $timezone)); // Friday

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->for($user)->create();

    $chips = app(TaskAssistantQuickChipResolver::class)->resolveForEmptyState($user, $thread, 6);

    expect($chips)->not->toContain('Create a plan for tomorrow');
    expect($chips)->toContain('Create a plan for next week');

    CarbonImmutable::setTestNow();
});

test('resolver saturday evening avoids plan tomorrow chip', function (): void {
    $timezone = (string) config('app.timezone', 'UTC');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-18 18:45:00', $timezone)); // Saturday

    $user = User::factory()->create();
    $thread = TaskAssistantThread::factory()->for($user)->create();

    $chips = app(TaskAssistantQuickChipResolver::class)->resolveForEmptyState($user, $thread, 6);

    expect($chips)->not->toContain('Create a plan for tomorrow');
    expect($chips)->toContain('Create a plan for next week');

    CarbonImmutable::setTestNow();
});
