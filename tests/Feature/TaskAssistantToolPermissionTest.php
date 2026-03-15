<?php

use App\Actions\Task\DeleteTaskAction;
use App\Actions\Task\UpdateTaskPropertyAction;
use App\Models\Task;
use App\Models\User;
use App\Tools\TaskAssistant\DeleteTaskTool;
use App\Tools\TaskAssistant\UpdateTaskTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('tool call for another user resource fails and returns error', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $task = Task::factory()->create(['user_id' => $owner->id, 'title' => 'Owner only']);

    $updateTool = new UpdateTaskTool($otherUser, app(UpdateTaskPropertyAction::class));
    $updateResult = $updateTool->__invoke([
        'taskId' => $task->id,
        'property' => 'title',
        'value' => 'Hacked',
    ]);

    $updateDecoded = json_decode($updateResult, true);
    expect($updateDecoded['ok'])->toBeFalse()
        ->and($updateDecoded)->toHaveKey('error');
    $task->refresh();
    expect($task->title)->toBe('Owner only');
});

test('delete tool call for another user resource fails and does not delete', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $task = Task::factory()->create(['user_id' => $owner->id]);

    $deleteTool = new DeleteTaskTool($otherUser, app(DeleteTaskAction::class));
    $result = $deleteTool->__invoke(['taskId' => $task->id, 'confirm' => true]);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeFalse()
        ->and($decoded)->toHaveKey('error');
    $task->refresh();
    expect($task->trashed())->toBeFalse();
});
