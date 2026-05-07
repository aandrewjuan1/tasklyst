<?php

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Services\LLM\Scheduling\FocusSessionSchedulingSignalsBuilder;
use Carbon\CarbonImmutable;
use Database\Seeders\AndrewJuanFocusHistoryAfternoonSeeder;
use Database\Seeders\AndrewJuanFocusHistoryBalancedSeeder;
use Database\Seeders\AndrewJuanFocusHistoryEveningSeeder;
use Database\Seeders\AndrewJuanFocusHistoryMorningSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 14:00:00', 'Asia/Manila'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function seedAndrewJuanDemoUserAndHistoryTasks(): User
{
    $user = User::factory()->create([
        'email' => 'andrew.juan.cvt@eac.edu.ph',
        'timezone' => 'Asia/Manila',
        'schedule_preferences' => null,
    ]);

    foreach (
        [
            'history-completed-thesis-sweep',
            'history-completed-sql-practice-a',
            'history-completed-data-viz-reviewer',
            'history-completed-api-refactor',
            'history-completed-statistics-drill',
            'history-completed-thesis-slides',
        ] as $sourceId
    ) {
        Task::factory()->for($user)->create([
            'title' => 'Demo completed '.$sourceId,
            'status' => TaskStatus::Done,
            'source_id' => $sourceId,
            'completed_at' => now()->subDays(45),
        ]);
    }

    return $user->fresh();
}

function expectFocusHistoryWithinLastWeek(User $user): void
{
    $oldestStartedAt = DB::table('focus_sessions')
        ->where('user_id', $user->id)
        ->min('started_at');

    expect($oldestStartedAt)->not->toBeNull();
    expect(CarbonImmutable::parse((string) $oldestStartedAt, 'Asia/Manila')->gte(CarbonImmutable::now('Asia/Manila')->subWeek()))
        ->toBeTrue();
}

it('infer morning energy bias from AndrewJuanFocusHistoryMorningSeeder', function (): void {
    $user = seedAndrewJuanDemoUserAndHistoryTasks();

    $this->seed(AndrewJuanFocusHistoryMorningSeeder::class);

    $signals = app(FocusSessionSchedulingSignalsBuilder::class)->buildForUser(
        $user,
        'Asia/Manila',
        CarbonImmutable::now('Asia/Manila')
    );

    expect($signals['schedule_preferences_override']['energy_bias'])->toBe('morning')
        ->and((float) $signals['energy_bias_confidence'])->toBeGreaterThanOrEqual(0.6);

    expectFocusHistoryWithinLastWeek($user);
});

it('infer evening energy bias from AndrewJuanFocusHistoryEveningSeeder', function (): void {
    $user = seedAndrewJuanDemoUserAndHistoryTasks();

    $this->seed(AndrewJuanFocusHistoryEveningSeeder::class);

    $signals = app(FocusSessionSchedulingSignalsBuilder::class)->buildForUser(
        $user,
        'Asia/Manila',
        CarbonImmutable::now('Asia/Manila')
    );

    expect($signals['schedule_preferences_override']['energy_bias'])->toBe('evening')
        ->and((float) $signals['energy_bias_confidence'])->toBeGreaterThanOrEqual(0.6);

    expectFocusHistoryWithinLastWeek($user);
});

it('infer balanced energy bias from AndrewJuanFocusHistoryBalancedSeeder', function (): void {
    $user = seedAndrewJuanDemoUserAndHistoryTasks();

    $this->seed(AndrewJuanFocusHistoryBalancedSeeder::class);

    $signals = app(FocusSessionSchedulingSignalsBuilder::class)->buildForUser(
        $user,
        'Asia/Manila',
        CarbonImmutable::now('Asia/Manila')
    );

    expect($signals['schedule_preferences_override']['energy_bias'])->toBe('balanced');

    expectFocusHistoryWithinLastWeek($user);
});

it('infer afternoon energy bias from AndrewJuanFocusHistoryAfternoonSeeder', function (): void {
    $user = seedAndrewJuanDemoUserAndHistoryTasks();

    $this->seed(AndrewJuanFocusHistoryAfternoonSeeder::class);

    $signals = app(FocusSessionSchedulingSignalsBuilder::class)->buildForUser(
        $user,
        'Asia/Manila',
        CarbonImmutable::now('Asia/Manila')
    );

    expect($signals['schedule_preferences_override']['energy_bias'])->toBe('afternoon')
        ->and((float) $signals['energy_bias_confidence'])->toBeGreaterThanOrEqual(0.6);

    expectFocusHistoryWithinLastWeek($user);
});
