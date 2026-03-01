<?php

use App\Models\ActivityLog;
use App\Models\Collaboration;
use App\Models\CollaborationInvitation;
use App\Models\Comment;
use App\Models\Event;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\FullFakeDataSeeder;

const SEED_TARGET_EMAIL = 'andrew.juan.cvt@eac.edu.ph';

test('full fake data seeder requires target user and creates three extra users', function (): void {
    User::factory()->create(['email' => SEED_TARGET_EMAIL]);

    $this->seed(FullFakeDataSeeder::class);

    expect(User::count())->toBe(4);
    expect(User::where('email', SEED_TARGET_EMAIL)->exists())->toBeTrue();
});

test('full fake data seeder creates data for target user', function (): void {
    $user = User::factory()->create(['email' => SEED_TARGET_EMAIL]);

    $this->seed(FullFakeDataSeeder::class);

    expect(Project::where('user_id', $user->id)->count())->toBeGreaterThan(0);
    expect(Task::where('user_id', $user->id)->count())->toBeGreaterThan(0);
    expect(Event::where('user_id', $user->id)->count())->toBeGreaterThan(0);
    expect(Tag::where('user_id', $user->id)->count())->toBeGreaterThan(0);
});

test('full fake data seeder throws when target user does not exist', function (): void {
    expect(fn () => $this->seed(FullFakeDataSeeder::class))
        ->toThrow(\RuntimeException::class, 'Seed user not found');
});

test('each level seeds without error', function (string $level): void {
    User::factory()->create(['email' => SEED_TARGET_EMAIL]);
    config(['tasklyst.fake_data_level' => $level]);

    $this->seed(FullFakeDataSeeder::class);

    expect(User::where('email', SEED_TARGET_EMAIL)->first()->id)->toBeGreaterThan(0);
    expect(Project::count())->toBeGreaterThan(0);
    expect(Task::count())->toBeGreaterThan(0);
    expect(Event::count())->toBeGreaterThan(0);
})->with(['easy', 'realistic', 'nightmare']);

test('each user has at least one project task event and tag', function (): void {
    $user = User::factory()->create(['email' => SEED_TARGET_EMAIL]);

    $this->seed(FullFakeDataSeeder::class);

    expect(Project::where('user_id', $user->id)->count())->toBeGreaterThan(0);
    expect(Task::where('user_id', $user->id)->count())->toBeGreaterThan(0);
    expect(Event::where('user_id', $user->id)->count())->toBeGreaterThan(0);
    expect(Tag::where('user_id', $user->id)->count())->toBeGreaterThan(0);
});

test('comments exist on at least one task event and project', function (): void {
    User::factory()->create(['email' => SEED_TARGET_EMAIL]);
    $this->seed(FullFakeDataSeeder::class);

    $taskComments = Comment::where('commentable_type', Task::class)->count();
    $eventComments = Comment::where('commentable_type', Event::class)->count();
    $projectComments = Comment::where('commentable_type', Project::class)->count();

    expect($taskComments)->toBeGreaterThan(0);
    expect($eventComments)->toBeGreaterThan(0);
    expect($projectComments)->toBeGreaterThan(0);
});

test('activity logs exist for tasks events and projects', function (): void {
    User::factory()->create(['email' => SEED_TARGET_EMAIL]);
    $this->seed(FullFakeDataSeeder::class);

    $taskLogs = ActivityLog::where('loggable_type', Task::class)->count();
    $eventLogs = ActivityLog::where('loggable_type', Event::class)->count();
    $projectLogs = ActivityLog::where('loggable_type', Project::class)->count();

    expect($taskLogs)->toBeGreaterThan(0);
    expect($eventLogs)->toBeGreaterThan(0);
    expect($projectLogs)->toBeGreaterThan(0);
});

test('collaborations and collaboration invitations exist', function (): void {
    User::factory()->create(['email' => SEED_TARGET_EMAIL]);
    $this->seed(FullFakeDataSeeder::class);

    expect(Collaboration::count())->toBeGreaterThan(0);
    expect(CollaborationInvitation::count())->toBeGreaterThan(0);
});

test('realistic level seeds incomplete and duplicate tasks for LLM stress-test', function (): void {
    $user = User::factory()->create(['email' => SEED_TARGET_EMAIL]);
    config(['tasklyst.fake_data_level' => 'realistic']);
    $this->seed(FullFakeDataSeeder::class);

    $tasksWithNullEnd = Task::where('user_id', $user->id)->whereNull('end_datetime')->count();
    $submitProposalCount = Task::where('user_id', $user->id)->where('title', 'Submit proposal')->count();

    expect($tasksWithNullEnd)->toBeGreaterThan(0);
    expect($submitProposalCount)->toBeGreaterThan(0);
});

test('nightmare level seeds impossible task for LLM stress-test', function (): void {
    $user = User::factory()->create(['email' => SEED_TARGET_EMAIL]);
    config(['tasklyst.fake_data_level' => 'nightmare']);
    $this->seed(FullFakeDataSeeder::class);

    $impossible = Task::where('user_id', $user->id)->where('title', 'Impossible 5h due in 2h')->first();

    expect($impossible)->not->toBeNull();
    expect($impossible->duration)->toBe(300);
});
