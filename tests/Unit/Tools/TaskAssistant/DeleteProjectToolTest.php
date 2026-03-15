<?php

use App\Actions\Project\DeleteProjectAction;
use App\Enums\LlmToolCallStatus;
use App\Models\LlmToolCall;
use App\Models\Project;
use App\Models\User;
use App\Tools\TaskAssistant\DeleteProjectTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns requires_confirm when confirm is not true', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $tool = new DeleteProjectTool($user, app(DeleteProjectAction::class));

    $result = $tool->__invoke(['projectId' => $project->id]);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeFalse();
    expect($decoded['requires_confirm'])->toBeTrue();
    $project->refresh();
    expect($project->trashed())->toBeFalse();
});

it('deletes project and records tool call when confirm is true', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $tool = new DeleteProjectTool($user, app(DeleteProjectAction::class));

    $result = $tool->__invoke(['projectId' => $project->id, 'confirm' => true]);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeTrue();
    expect($decoded['project_id'])->toBe($project->id);
    $project->refresh();
    expect($project->trashed())->toBeTrue();
    $call = LlmToolCall::query()->where('tool_name', 'delete_project')->where('user_id', $user->id)->first();
    expect($call)->not->toBeNull();
    expect($call->status)->toBe(LlmToolCallStatus::Success);
});

it('does not delete project when called by another user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $userA->id]);
    $tool = new DeleteProjectTool($userB, app(DeleteProjectAction::class));

    $result = $tool->__invoke(['projectId' => $project->id, 'confirm' => true]);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeFalse();
    expect($decoded)->toHaveKey('error');
    $project->refresh();
    expect($project->trashed())->toBeFalse();
});
