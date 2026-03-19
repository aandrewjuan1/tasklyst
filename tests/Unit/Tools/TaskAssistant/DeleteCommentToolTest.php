<?php

use App\Actions\Comment\DeleteCommentAction;
use App\Enums\LlmToolCallStatus;
use App\Models\Comment;
use App\Models\LlmToolCall;
use App\Models\Task;
use App\Models\User;
use App\Tools\LLM\TaskAssistant\DeleteCommentTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns requires_confirm when confirm is not true', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create(['user_id' => $user->id]);
    $comment = Comment::factory()->create(['user_id' => $user->id, 'commentable_id' => $task->id, 'commentable_type' => Task::class]);
    $tool = new DeleteCommentTool($user, app(DeleteCommentAction::class));

    $result = $tool->__invoke(['commentId' => $comment->id]);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeFalse();
    expect($decoded['requires_confirm'])->toBeTrue();
    expect(Comment::find($comment->id))->not->toBeNull();
});

it('deletes comment and records tool call when confirm is true', function () {
    $user = User::factory()->create();
    $task = Task::factory()->create(['user_id' => $user->id]);
    $comment = Comment::factory()->create(['user_id' => $user->id, 'commentable_id' => $task->id, 'commentable_type' => Task::class]);
    $commentId = $comment->id;
    $tool = new DeleteCommentTool($user, app(DeleteCommentAction::class));

    $result = $tool->__invoke(['commentId' => $commentId, 'confirm' => true]);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeTrue();
    expect($decoded['comment_id'])->toBe($commentId);
    expect(Comment::find($commentId))->toBeNull();
    $call = LlmToolCall::query()->where('tool_name', 'delete_comment')->where('user_id', $user->id)->first();
    expect($call)->not->toBeNull();
    expect($call->status)->toBe(LlmToolCallStatus::Success);
});

it('does not delete comment when called by another user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $task = Task::factory()->create(['user_id' => $userA->id]);
    $comment = Comment::factory()->create(['user_id' => $userA->id, 'commentable_id' => $task->id, 'commentable_type' => Task::class]);
    $tool = new DeleteCommentTool($userB, app(DeleteCommentAction::class));

    $result = $tool->__invoke(['commentId' => $comment->id, 'confirm' => true]);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeFalse();
    expect($decoded)->toHaveKey('error');
    expect(Comment::find($comment->id))->not->toBeNull();
});
