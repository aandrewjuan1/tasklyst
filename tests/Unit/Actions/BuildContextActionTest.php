<?php

use App\Actions\Llm\BuildContextAction;
use App\DataTransferObjects\Llm\ContextDto;
use App\Enums\ChatMessageRole;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;

test('build context action returns expected tasks events and messages', function (): void {
    $user = User::factory()->create();
    $thread = ChatThread::query()->create([
        'user_id' => $user->id,
        'title' => 'Thread',
        'schema_version' => config('llm.schema_version'),
    ]);

    $project = Project::factory()->for($user)->create([
        'name' => 'Capstone Project',
    ]);

    Task::factory()->for($user)->for($project)->create([
        'title' => 'Task 1',
        'completed_at' => null,
    ]);

    Event::factory()->for($user)->create([
        'title' => 'Event 1',
        'start_datetime' => Carbon::now()->addHour(),
        'end_datetime' => Carbon::now()->addHours(2),
    ]);

    ChatMessage::query()->create([
        'thread_id' => $thread->id,
        'role' => ChatMessageRole::User,
        'author_id' => $user->id,
        'content_text' => 'Hello',
    ]);

    $action = new BuildContextAction;

    $context = $action($user, (string) $thread->id, 'Hello');

    expect($context)->toBeInstanceOf(ContextDto::class);
    expect($context->tasks)->not->toBeEmpty();
    expect($context->events)->not->toBeEmpty();
    expect($context->projects)->not->toBeEmpty();
    expect($context->recentMessages)->not->toBeEmpty();
    expect($context->taskSummary)->toBeArray();
    expect($context->taskSummary)->toHaveKeys([
        'total_active_tasks',
        'overdue_count',
        'due_today_count',
        'due_next_7_days_count',
        'high_priority_count',
        'urgent_count',
        'relevant_today_task_ids',
        'next_7_days_task_ids',
        'top_high_priority_task_ids',
    ]);
    expect($context->projectSummary)->toBeArray();
    expect($context->projectSummary)->toHaveKeys([
        'total_projects',
        'projects_with_incomplete_tasks',
        'overdue_projects',
        'upcoming_projects_next_7_days',
        'top_project_ids',
    ]);
    expect($context->userPreferences['default_study_block_minutes'] ?? null)->toBe(60);
    expect($context->lastUserMessage)->toBe('Hello');
});
