<?php

use App\Models\Event;
use App\Models\Project;
use App\Models\RecurringSchoolClass;
use App\Models\RecurringTask;
use App\Models\SchoolClass;
use App\Models\Task;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\AndrewJuanExactDatasetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the exact andrew dataset snapshot', function () {
    $this->seed(AndrewJuanExactDatasetSeeder::class);

    $user = User::query()->where('email', 'andrew.juan.cvt@eac.edu.ph')->firstOrFail();

    expect($user->name)->toBe('ANDREW JUAN')
        ->and(Project::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(Event::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(Task::query()->withTrashed()->where('user_id', $user->id)->count())->toBe(14)
        ->and(Task::query()->where('user_id', $user->id)->count())->toBe(13)
        ->and(SchoolClass::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(Teacher::query()->where('user_id', $user->id)->count())->toBe(1);

    $schoolClass = SchoolClass::query()->where('user_id', $user->id)->firstOrFail();

    expect($schoolClass->subject_name)->toBe('ELECTIVE 3')
        ->and($schoolClass->start_time)->toBe('07:00:00')
        ->and($schoolClass->end_time)->toBe('10:00:00');

    $recurringSchoolClass = RecurringSchoolClass::query()->where('school_class_id', $schoolClass->id)->firstOrFail();

    expect($recurringSchoolClass->recurrence_type?->value)->toBe('weekly')
        ->and($recurringSchoolClass->interval)->toBe(1)
        ->and($recurringSchoolClass->days_of_week)->toBe('[3]');

    $dailyRunTask = Task::query()->where('user_id', $user->id)->where('title', '5KM RUN DAILY')->firstOrFail();
    $run5kmDeletedTask = Task::query()->withTrashed()->where('user_id', $user->id)->where('title', 'RUN 5KM')->firstOrFail();

    expect($dailyRunTask->source_type)->toBeNull()
        ->and($dailyRunTask->duration)->toBe(120)
        ->and($run5kmDeletedTask->trashed())->toBeTrue();

    $recurringTask = RecurringTask::query()->where('task_id', $dailyRunTask->id)->firstOrFail();

    expect($recurringTask->recurrence_type?->value)->toBe('daily')
        ->and($recurringTask->interval)->toBe(1);
});
