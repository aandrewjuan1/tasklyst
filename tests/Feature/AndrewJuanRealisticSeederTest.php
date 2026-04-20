<?php

use App\Enums\TaskSourceType;
use App\Models\Event;
use App\Models\Project;
use App\Models\RecurringSchoolClass;
use App\Models\SchoolClass;
use App\Models\Task;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\AndrewJuanRealisticSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('fails clearly when the target user is missing', function () {
    expect(fn () => $this->seed(AndrewJuanRealisticSeeder::class))
        ->toThrow(RuntimeException::class, 'target user does not exist');
});

it('seeds realistic student data for andrew with brightspace-like items', function () {
    User::factory()->create([
        'name' => 'Andrew Juan',
        'email' => 'andrew.juan.cvt@eac.edu.ph',
    ]);

    $this->seed(AndrewJuanRealisticSeeder::class);

    $andrew = User::query()->where('email', 'andrew.juan.cvt@eac.edu.ph')->firstOrFail();

    expect(Project::query()->where('user_id', $andrew->id)->count())->toBe(5)
        ->and(Event::query()->where('user_id', $andrew->id)->count())->toBe(8)
        ->and(Task::query()->where('user_id', $andrew->id)->count())->toBe(14)
        ->and(SchoolClass::query()->where('user_id', $andrew->id)->count())->toBe(5)
        ->and(Teacher::query()->where('user_id', $andrew->id)->count())->toBe(5)
        ->and(
            RecurringSchoolClass::query()
                ->whereHas('schoolClass', fn ($query) => $query->where('user_id', $andrew->id))
                ->count()
        )->toBe(3);

    $brightspaceTasks = Task::query()
        ->where('user_id', $andrew->id)
        ->where('source_type', TaskSourceType::Brightspace)
        ->get();

    expect($brightspaceTasks)->toHaveCount(4);

    foreach ($brightspaceTasks as $task) {
        expect($task->teacher_name)->not->toBeNull()
            ->and($task->subject_name)->not->toBeNull()
            ->and($task->source_url)->toBe('https://eac.brightspace.com/d2l/lms/dropbox/user/folder_submit_files.d2l?db=220208&grpid=0&isprv=0&bp=0&ou=112348');
    }

    $manualTasks = Task::query()
        ->where('user_id', $andrew->id)
        ->where('source_type', TaskSourceType::Manual)
        ->get();

    expect($manualTasks)->toHaveCount(10)
        ->and($manualTasks->whereNull('project_id')->count())->toBeGreaterThanOrEqual(5)
        ->and($manualTasks->whereNotNull('project_id')->count())->toBeGreaterThanOrEqual(5)
        ->and($manualTasks->whereNull('event_id')->count())->toBeGreaterThanOrEqual(5)
        ->and($manualTasks->whereNotNull('event_id')->count())->toBeGreaterThanOrEqual(5);

    expect(
        Task::query()
            ->where('user_id', $andrew->id)
            ->whereNotNull('school_class_id')
            ->count()
    )->toBe(9);

    expect(
        Task::query()
            ->where('user_id', $andrew->id)
            ->where('title', 'Basketball conditioning with classmates')
            ->exists()
    )->toBeTrue();

    expect(
        Task::query()
            ->where('user_id', $andrew->id)
            ->where('title', 'Brightspace: DSA Problem Set 3 Submission')
            ->exists()
    )->toBeTrue();

    $todayInManila = Carbon::now('Asia/Manila');

    expect(
        Task::query()
            ->where('user_id', $andrew->id)
            ->relevantForDate($todayInManila)
            ->exists()
    )->toBeTrue()
        ->and(
            Event::query()
                ->where('user_id', $andrew->id)
                ->whereDate('start_datetime', $todayInManila->toDateString())
                ->exists()
        )->toBeTrue();

    $overdueTasks = Task::query()
        ->where('user_id', $andrew->id)
        ->whereNotNull('end_datetime')
        ->where('end_datetime', '<', $todayInManila)
        ->count();

    $overdueEvents = Event::query()
        ->where('user_id', $andrew->id)
        ->whereNotNull('end_datetime')
        ->where('end_datetime', '<', $todayInManila)
        ->count();

    expect($overdueTasks)->toBeLessThanOrEqual(1)
        ->and($overdueEvents)->toBe(0);

    expect(
        SchoolClass::query()
            ->where('user_id', $andrew->id)
            ->whereDate('start_datetime', $todayInManila->toDateString())
            ->exists()
    )->toBeTrue();

    expect(
        SchoolClass::query()
            ->where('user_id', $andrew->id)
            ->whereDoesntHave('recurringSchoolClass')
            ->count()
    )->toBe(2)
        ->and(
            Task::query()
                ->where('user_id', $andrew->id)
                ->whereNotNull('school_class_id')
                ->whereNull('project_id')
                ->count()
        )->toBe(0)
        ->and(
            Task::query()
                ->where('user_id', $andrew->id)
                ->whereNotNull('school_class_id')
                ->whereNull('event_id')
                ->where('source_type', TaskSourceType::Manual)
                ->count()
        )->toBe(0);
});
