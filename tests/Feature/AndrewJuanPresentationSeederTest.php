<?php

use App\Models\Event;
use App\Models\Project;
use App\Models\RecurringSchoolClass;
use App\Models\SchoolClass;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\AndrewJuanPresentationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

it('fails clearly when the target user is missing', function () {
    expect(fn () => $this->seed(AndrewJuanPresentationSeeder::class))
        ->toThrow(\RuntimeException::class, 'no user matches the target email');
});

it('resolves the target user when the database email differs only by case', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-05 10:00:00', 'Asia/Manila'));

    User::factory()->create([
        'name' => 'Andrew Juan',
        'email' => 'Andrew.Juan.CVT@eac.edu.ph',
    ]);

    $this->seed(AndrewJuanPresentationSeeder::class);

    expect(
        Task::query()
            ->where('source_id', 'pres-demo-capstone-integration')
            ->whereHas('user', fn ($q) => $q->whereRaw('LOWER(email) = ?', [strtolower('andrew.juan.cvt@eac.edu.ph')]))
            ->exists()
    )->toBeTrue();

    Carbon::setTestNow();
});

it('uses presentation_seeder_target_email when configured', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-05 10:00:00', 'Asia/Manila'));

    $previousTarget = config('tasklyst.presentation_seeder_target_email');
    Config::set('tasklyst.presentation_seeder_target_email', 'demo.presenter@example.test');

    try {
        User::factory()->create([
            'name' => 'Demo Presenter',
            'email' => 'demo.presenter@example.test',
        ]);

        $this->seed(AndrewJuanPresentationSeeder::class);

        expect(
            Task::query()
                ->where('source_id', 'pres-demo-capstone-integration')
                ->whereHas('user', fn ($q) => $q->where('email', 'demo.presenter@example.test'))
                ->exists()
        )->toBeTrue();
    } finally {
        Config::set('tasklyst.presentation_seeder_target_email', $previousTarget);
        Carbon::setTestNow();
    }
});

it('seeds deterministic presentation data for andrew and is idempotent', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-05 10:00:00', 'Asia/Manila'));

    User::factory()->create([
        'name' => 'Andrew Juan',
        'email' => AndrewJuanPresentationSeeder::TARGET_EMAIL,
    ]);

    $this->seed(AndrewJuanPresentationSeeder::class);

    $andrew = User::query()->where('email', AndrewJuanPresentationSeeder::TARGET_EMAIL)->firstOrFail();

    $demoProjects = Project::query()
        ->where('user_id', $andrew->id)
        ->whereIn('name', AndrewJuanPresentationSeeder::SEEDED_PROJECT_NAMES)
        ->get();

    $demoEvents = Event::query()
        ->where('user_id', $andrew->id)
        ->whereIn('title', AndrewJuanPresentationSeeder::SEEDED_EVENT_TITLES)
        ->get();

    $demoClasses = SchoolClass::query()
        ->where('user_id', $andrew->id)
        ->whereIn('subject_name', AndrewJuanPresentationSeeder::SEEDED_CLASS_SUBJECT_NAMES)
        ->get();

    $demoTasks = Task::query()
        ->where('user_id', $andrew->id)
        ->where('source_id', 'like', 'pres-demo-%')
        ->get();

    expect($demoProjects)->toHaveCount(5)
        ->and($demoEvents)->toHaveCount(10)
        ->and($demoClasses)->toHaveCount(5)
        ->and($demoTasks)->toHaveCount(31);

    expect(
        RecurringSchoolClass::query()
            ->whereHas('schoolClass', fn ($q) => $q->where('user_id', $andrew->id)->whereIn('subject_name', AndrewJuanPresentationSeeder::SEEDED_CLASS_SUBJECT_NAMES))
            ->count()
    )->toBe(5);

    foreach ($demoProjects as $project) {
        expect($project->description)->not->toBeNull()->not->toBe('');
    }

    foreach ($demoEvents as $event) {
        expect($event->description)->not->toBeNull()->not->toBe('');
    }

    expect($demoEvents->where('all_day', true)->count())->toBeGreaterThanOrEqual(1);

    $eventsOnMay5 = Event::query()
        ->where('user_id', $andrew->id)
        ->whereIn('title', AndrewJuanPresentationSeeder::SEEDED_EVENT_TITLES)
        ->whereDate('start_datetime', '2026-05-05')
        ->count();

    expect($eventsOnMay5)->toBeGreaterThanOrEqual(3);

    foreach ($demoTasks as $task) {
        expect($task->description)->not->toBeNull()->not->toBe('');
    }

    $overdueDemoTasks = $demoTasks->filter(fn (Task $task): bool => $task->end_datetime !== null
        && $task->end_datetime->lt(Carbon::now('Asia/Manila')));

    expect($overdueDemoTasks->count())->toBe(1);

    $noDateDemoTasks = $demoTasks->filter(fn (Task $task): bool => $task->start_datetime === null
        && $task->end_datetime === null);

    expect($noDateDemoTasks->count())->toBeGreaterThanOrEqual(2)
        ->and($noDateDemoTasks->count())->toBeLessThanOrEqual(4);

    expect($demoTasks->where('status', \App\Enums\TaskStatus::Doing)->count())->toBeGreaterThanOrEqual(1);

    $withTags = $demoTasks->filter(fn (Task $task): bool => $task->tags()->exists());

    expect($withTags->count())->toBeGreaterThanOrEqual((int) ceil($demoTasks->count() * 0.6));

    $multiTagCount = $demoTasks->filter(function (Task $task): bool {
        return $task->tags()->count() >= 2;
    })->count();

    expect($multiTagCount)->toBeGreaterThanOrEqual(3);

    $recurringBacked = $demoTasks->filter(fn (Task $task): bool => $task->recurringTask !== null);

    expect($recurringBacked->count())->toBeGreaterThanOrEqual(2);

    $dueOnPresentationAnchorDay = $demoTasks->filter(function (Task $task): bool {
        return $task->end_datetime !== null
            && $task->end_datetime->isSameDay(Carbon::parse('2026-05-05', 'Asia/Manila'));
    });

    expect($dueOnPresentationAnchorDay)->toHaveCount(2);

    expect(Tag::query()->where('user_id', $andrew->id)->count())->toBeGreaterThanOrEqual(5);

    $countAfterFirstSeed = Task::query()
        ->where('user_id', $andrew->id)
        ->where('source_id', 'like', 'pres-demo-%')
        ->count();

    $this->seed(AndrewJuanPresentationSeeder::class);

    $countAfterSecondSeed = Task::query()
        ->where('user_id', $andrew->id)
        ->where('source_id', 'like', 'pres-demo-%')
        ->count();

    expect($countAfterSecondSeed)->toBe($countAfterFirstSeed);

    Carbon::setTestNow();
});
