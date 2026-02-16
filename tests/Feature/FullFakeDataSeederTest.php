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

test('full fake data seeder creates exactly five users', function (): void {
    $this->seed(FullFakeDataSeeder::class);

    $fullFakeUsers = User::where('email', 'like', 'fullfake.user%@example.test')->get();

    expect($fullFakeUsers)->toHaveCount(5);
});

test('full fake data seeder users have expected email pattern', function (): void {
    $this->seed(FullFakeDataSeeder::class);

    $emails = User::where('email', 'like', 'fullfake.user%@example.test')
        ->pluck('email')
        ->all();

    expect($emails)->toHaveCount(5);
    foreach (['fullfake.user1@example.test', 'fullfake.user2@example.test', 'fullfake.user3@example.test', 'fullfake.user4@example.test', 'fullfake.user5@example.test'] as $expected) {
        expect($emails)->toContain($expected);
    }
});

test('each user has at least one project task event and tag', function (): void {
    $this->seed(FullFakeDataSeeder::class);

    $users = User::where('email', 'like', 'fullfake.user%@example.test')->get();
    expect($users)->toHaveCount(5);

    foreach ($users as $user) {
        expect(Project::where('user_id', $user->id)->count())->toBeGreaterThan(0);
        expect(Task::where('user_id', $user->id)->count())->toBeGreaterThan(0);
        expect(Event::where('user_id', $user->id)->count())->toBeGreaterThan(0);
        expect(Tag::where('user_id', $user->id)->count())->toBeGreaterThan(0);
    }
});

test('comments exist on at least one task event and project', function (): void {
    $this->seed(FullFakeDataSeeder::class);

    $taskComments = Comment::where('commentable_type', Task::class)->count();
    $eventComments = Comment::where('commentable_type', Event::class)->count();
    $projectComments = Comment::where('commentable_type', Project::class)->count();

    expect($taskComments)->toBeGreaterThan(0);
    expect($eventComments)->toBeGreaterThan(0);
    expect($projectComments)->toBeGreaterThan(0);
});

test('activity logs exist for tasks events and projects', function (): void {
    $this->seed(FullFakeDataSeeder::class);

    $taskLogs = ActivityLog::where('loggable_type', Task::class)->count();
    $eventLogs = ActivityLog::where('loggable_type', Event::class)->count();
    $projectLogs = ActivityLog::where('loggable_type', Project::class)->count();

    expect($taskLogs)->toBeGreaterThan(0);
    expect($eventLogs)->toBeGreaterThan(0);
    expect($projectLogs)->toBeGreaterThan(0);
});

test('collaborations and collaboration invitations exist', function (): void {
    $this->seed(FullFakeDataSeeder::class);

    expect(Collaboration::count())->toBeGreaterThan(0);
    expect(CollaborationInvitation::count())->toBeGreaterThan(0);
});
