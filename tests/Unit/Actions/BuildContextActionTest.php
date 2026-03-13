<?php

use App\Actions\Llm\BuildContextAction;
use App\DataTransferObjects\Llm\ContextDto;
use App\Enums\ChatMessageRole;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Event;
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

    Task::factory()->for($user)->create([
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
    expect($context->recentMessages)->not->toBeEmpty();
});
