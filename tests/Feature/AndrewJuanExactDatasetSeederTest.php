<?php

use App\Models\Event;
use App\Models\FocusSession;
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
        ->and(Event::query()->where('user_id', $user->id)->count())->toBe(4)
        ->and(Task::query()->withTrashed()->where('user_id', $user->id)->count())->toBe(32)
        ->and(Task::query()->where('user_id', $user->id)->count())->toBe(31)
        ->and(SchoolClass::query()->where('user_id', $user->id)->count())->toBe(2)
        ->and(Teacher::query()->where('user_id', $user->id)->count())->toBe(4)
        ->and(Task::query()->where('user_id', $user->id)->where('status', 'done')->count())->toBe(6)
        ->and(FocusSession::query()->where('user_id', $user->id)->count())->toBe(18);

    $electiveClass = SchoolClass::query()
        ->where('user_id', $user->id)
        ->where('subject_name', 'PROFESSIONAL ELECTIVE 3')
        ->firstOrFail();
    $thesisClass = SchoolClass::query()
        ->where('user_id', $user->id)
        ->where('subject_name', 'CS THESIS WRITING 2')
        ->firstOrFail();

    expect($electiveClass->start_time)->toBe('07:30:00')
        ->and($electiveClass->end_time)->toBe('10:30:00')
        ->and($thesisClass->start_time)->toBe('07:00:00')
        ->and($thesisClass->end_time)->toBe('10:00:00');

    $electiveRecurring = RecurringSchoolClass::query()->where('school_class_id', $electiveClass->id)->firstOrFail();
    $thesisRecurring = RecurringSchoolClass::query()->where('school_class_id', $thesisClass->id)->firstOrFail();

    expect($electiveRecurring->recurrence_type?->value)->toBe('weekly')
        ->and($electiveRecurring->interval)->toBe(1)
        ->and($electiveRecurring->days_of_week)->toBe('[3]')
        ->and($thesisRecurring->recurrence_type?->value)->toBe('weekly')
        ->and($thesisRecurring->interval)->toBe(1)
        ->and($thesisRecurring->days_of_week)->toBe('[6]');

    $dailyRunTask = Task::query()->where('user_id', $user->id)->where('title', '5KM RUN DAILY')->firstOrFail();
    $recurringStudyTask = Task::query()->where('user_id', $user->id)->where('title', 'Professional Elective 3 Weekly Review Session')->firstOrFail();
    $oneTimeChoreTask = Task::query()->where('user_id', $user->id)->where('title', 'Pay Utility and Internet Share')->firstOrFail();
    $run5kmDeletedTask = Task::query()->withTrashed()->where('user_id', $user->id)->where('title', 'RUN 5KM')->firstOrFail();
    $resumeReviewEvent = Event::query()->where('user_id', $user->id)->where('title', 'Career Center Resume Review')->firstOrFail();

    expect($dailyRunTask->source_type)->toBeNull()
        ->and($dailyRunTask->duration)->toBe(30)
        ->and($dailyRunTask->start_datetime?->toDateTimeString())->toBe('2026-04-29 17:00:00')
        ->and($recurringStudyTask->duration)->toBe(90)
        ->and($oneTimeChoreTask->end_datetime?->toDateTimeString())->toBe('2026-05-10 17:30:00')
        ->and($resumeReviewEvent->start_datetime?->toDateTimeString())->toBe('2026-05-22 09:30:00')
        ->and($run5kmDeletedTask->trashed())->toBeTrue();

    $recurringTask = RecurringTask::query()->where('task_id', $dailyRunTask->id)->firstOrFail();
    $recurringStudy = RecurringTask::query()->where('task_id', $recurringStudyTask->id)->firstOrFail();
    $completedTask = Task::query()->where('user_id', $user->id)->where('source_id', 'history-completed-api-refactor')->firstOrFail();
    $completedTaskFocusSessions = FocusSession::query()
        ->where('user_id', $user->id)
        ->where('focusable_type', $completedTask->getMorphClass())
        ->where('focusable_id', $completedTask->id)
        ->orderBy('started_at')
        ->get();
    $workSessions = FocusSession::query()
        ->where('user_id', $user->id)
        ->where('type', 'work')
        ->where('completed', true)
        ->get();

    $hourBuckets = $workSessions->reduce(
        function (array $carry, FocusSession $session): array {
            $hour = (int) $session->started_at?->format('H');
            if ($hour >= 8 && $hour <= 13) {
                $carry['morning']++;
            } elseif ($hour >= 14 && $hour <= 17) {
                $carry['afternoon']++;
            } elseif ($hour >= 18 && $hour <= 22) {
                $carry['evening']++;
            }

            return $carry;
        },
        ['morning' => 0, 'afternoon' => 0, 'evening' => 0]
    );

    expect($recurringTask->recurrence_type?->value)->toBe('daily')
        ->and($recurringTask->interval)->toBe(1)
        ->and(RecurringTask::query()->count())->toBe(7)
        ->and($recurringStudy->recurrence_type?->value)->toBe('weekly')
        ->and($recurringStudy->days_of_week)->toBe('[6]')
        ->and($completedTask->status?->value)->toBe('done')
        ->and($completedTask->completed_at)->not->toBeNull()
        ->and($completedTaskFocusSessions->count())->toBeGreaterThan(0)
        ->and($workSessions->count())->toBe(12)
        ->and($hourBuckets['morning'])->toBeGreaterThan(0)
        ->and($hourBuckets['afternoon'])->toBeGreaterThan(0)
        ->and($hourBuckets['evening'])->toBeGreaterThan(0)
        ->and($hourBuckets['afternoon'])->toBeGreaterThanOrEqual(3)
        ->and($hourBuckets['evening'])->toBeGreaterThan($hourBuckets['morning']);
});
