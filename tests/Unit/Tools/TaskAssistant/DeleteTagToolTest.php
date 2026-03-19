<?php

use App\Actions\Tag\DeleteTagAction;
use App\Enums\LlmToolCallStatus;
use App\Models\LlmToolCall;
use App\Models\Tag;
use App\Models\User;
use App\Tools\LLM\TaskAssistant\DeleteTagTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns requires_confirm when confirm is not true', function () {
    $user = User::factory()->create();
    $tag = Tag::factory()->create(['user_id' => $user->id]);
    $tool = new DeleteTagTool($user, app(DeleteTagAction::class));

    $result = $tool->__invoke(['tagId' => $tag->id]);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeFalse();
    expect($decoded['requires_confirm'])->toBeTrue();
    expect(Tag::find($tag->id))->not->toBeNull();
});

it('deletes tag and records tool call when confirm is true', function () {
    $user = User::factory()->create();
    $tag = Tag::factory()->create(['user_id' => $user->id]);
    $tagId = $tag->id;
    $tool = new DeleteTagTool($user, app(DeleteTagAction::class));

    $result = $tool->__invoke(['tagId' => $tagId, 'confirm' => true]);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeTrue();
    expect($decoded['tag_id'])->toBe($tagId);
    expect(Tag::find($tagId))->toBeNull();
    $call = LlmToolCall::query()->where('tool_name', 'delete_tag')->where('user_id', $user->id)->first();
    expect($call)->not->toBeNull();
    expect($call->status)->toBe(LlmToolCallStatus::Success);
});

it('does not delete tag when called by another user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $tag = Tag::factory()->create(['user_id' => $userA->id]);
    $tool = new DeleteTagTool($userB, app(DeleteTagAction::class));

    $result = $tool->__invoke(['tagId' => $tag->id, 'confirm' => true]);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeFalse();
    expect($decoded)->toHaveKey('error');
    expect(Tag::find($tag->id))->not->toBeNull();
});
