<?php

use App\Enums\TaskSourceType;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\BrightspaceSampleTasksSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds 20 brightspace tasks for the demo user', function (): void {
    $user = User::factory()->create([
        'email' => 'andrew.juan.cvt@eac.edu.ph',
    ]);

    (new BrightspaceSampleTasksSeeder)->run();

    $tasks = Task::query()
        ->where('user_id', $user->id)
        ->where('source_type', TaskSourceType::Brightspace)
        ->get();

    expect($tasks)->toHaveCount(20);

    // Check a couple of representative tasks exist by title.
    $titles = $tasks->pluck('title')->all();

    expect($titles)->toContain('ITCS 101 – Lab 3: Loops');
    expect($titles)->toContain('CS 220 – Lab 5: Linked Lists');

    // Ensure Brightspace-specific fields are set as expected.
    $tasks->each(function (Task $task): void {
        expect($task->source_type)->toBe(TaskSourceType::Brightspace);
        expect($task->calendar_feed_id)->toBeNull();
        expect($task->source_url)->toBeNull();
        expect($task->subject_name)->not->toBeNull();
        expect($task->teacher_name)->not->toBeNull();
    });
});
