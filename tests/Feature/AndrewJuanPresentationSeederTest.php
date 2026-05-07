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
    $runAnchor = Carbon::parse('2026-06-11 20:00:00', 'Asia/Manila');
    Carbon::setTestNow($runAnchor);

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
        ->and($demoEvents)->toHaveCount(8)
        ->and($demoClasses)->toHaveCount(5)
        ->and($demoTasks)->toHaveCount(31);

    expect(
        RecurringSchoolClass::query()
            ->whereHas('schoolClass', fn ($q) => $q->where('user_id', $andrew->id)->whereIn('subject_name', AndrewJuanPresentationSeeder::SEEDED_CLASS_SUBJECT_NAMES))
            ->count()
    )->toBe(5);

    foreach ($demoClasses as $class) {
        expect($class->end_datetime->gt(Carbon::now('Asia/Manila')))->toBeTrue();
        expect($class->start_time)->not->toBeNull();
        expect($class->end_time)->not->toBeNull();
        expect($class->start_time)->toBeLessThan($class->end_time);
    }

    $expectedClassTimes = [
        'Discrete Structures' => ['07:30:00', '09:00:00'],
        'Data Structures and Algorithms' => ['09:00:00', '10:30:00'],
        'Database Systems Laboratory' => ['13:00:00', '15:00:00'],
        'Web Systems and Technologies' => ['10:00:00', '11:30:00'],
        'Human-Computer Interaction Studio' => ['14:00:00', '15:30:00'],
    ];

    foreach ($expectedClassTimes as $subject => [$expectedStart, $expectedEnd]) {
        $seededClass = $demoClasses->firstWhere('subject_name', $subject);

        expect($seededClass)->not->toBeNull();
        expect($seededClass->start_time)->toBe($expectedStart);
        expect($seededClass->end_time)->toBe($expectedEnd);
    }

    foreach ($demoProjects as $project) {
        expect($project->description)->not->toBeNull()->not->toBe('');
        expect($project->end_datetime->gt(Carbon::now('Asia/Manila')))->toBeTrue();
    }

    foreach ($demoEvents as $event) {
        expect($event->description)->not->toBeNull()->not->toBe('');
        expect($event->end_datetime->gt(Carbon::now('Asia/Manila')))->toBeTrue();
        expect($event->start_datetime->lt($event->end_datetime))->toBeTrue();
    }

    expect($demoEvents->where('all_day', true)->count())->toBeGreaterThanOrEqual(1);

    $expectedEventTimes = [
        'Org fair volunteer sync' => ['10:35:00', '11:10:00'],
        'Peer tutoring (DSA walkthrough)' => ['15:05:00', '15:40:00'],
        'Campus fair booth shift' => ['10:00:00', '11:30:00'],
        'Capstone team stand-up' => ['12:00:00', '12:45:00'],
        'Evening midterm review session' => ['18:30:00', '20:30:00'],
        'Faculty consultation (Web Systems)' => ['11:00:00', '11:45:00'],
        'Pick-up basketball' => ['17:00:00', '18:30:00'],
    ];

    foreach ($expectedEventTimes as $title => [$expectedStart, $expectedEnd]) {
        $seededEvent = $demoEvents->firstWhere('title', $title);

        expect($seededEvent)->not->toBeNull();
        expect($seededEvent->start_datetime->format('H:i:s'))->toBe($expectedStart);
        expect($seededEvent->end_datetime->format('H:i:s'))->toBe($expectedEnd);
    }

    $studyBlackout = $demoEvents->firstWhere('title', 'All-day study blackout (no meetings)');
    expect($studyBlackout)->not->toBeNull();
    expect($studyBlackout->all_day)->toBeTrue();
    expect($studyBlackout->start_datetime->format('H:i:s'))->toBe('00:00:00');
    expect($studyBlackout->end_datetime->format('H:i:s'))->toBe('23:59:00');

    $orgSync = Event::query()
        ->where('user_id', $andrew->id)
        ->where('title', 'Org fair volunteer sync')
        ->firstOrFail();

    expect($orgSync->end_datetime->gt(Carbon::now('Asia/Manila')))->toBeTrue();

    foreach ($demoTasks as $task) {
        expect($task->description)->not->toBeNull()->not->toBe('');
    }

    $overdueDemoTasks = $demoTasks->filter(fn (Task $task): bool => $task->end_datetime !== null
        && $task->end_datetime->lt(Carbon::now('Asia/Manila')));

    expect($overdueDemoTasks->count())->toBe(1);
    expect($overdueDemoTasks->first()?->source_id)->toBe('pres-demo-overdue-reading');

    $noDateDemoTasks = $demoTasks->filter(fn (Task $task): bool => $task->start_datetime === null
        && $task->end_datetime === null);

    expect($noDateDemoTasks->count())->toBeGreaterThanOrEqual(2)
        ->and($noDateDemoTasks->count())->toBeLessThanOrEqual(4);

    $withTags = $demoTasks->filter(fn (Task $task): bool => $task->tags()->exists());

    expect($withTags->count())->toBeGreaterThanOrEqual((int) ceil($demoTasks->count() * 0.6));

    $multiTagCount = $demoTasks->filter(function (Task $task): bool {
        return $task->tags()->count() >= 2;
    })->count();

    expect($multiTagCount)->toBeGreaterThanOrEqual(3);

    $recurringBacked = $demoTasks->filter(fn (Task $task): bool => $task->recurringTask !== null);

    expect($recurringBacked->count())->toBeGreaterThanOrEqual(2);

    $futureOrUndatedTasks = $demoTasks->filter(function (Task $task): bool {
        return $task->end_datetime === null
            || $task->source_id === 'pres-demo-overdue-reading'
            || $task->end_datetime->gt(Carbon::now('Asia/Manila'));
    });

    expect($futureOrUndatedTasks)->toHaveCount($demoTasks->count());

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
