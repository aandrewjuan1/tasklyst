<?php

use App\Actions\Event\DeleteEventAction;
use App\Enums\LlmToolCallStatus;
use App\Models\Event;
use App\Models\LlmToolCall;
use App\Models\User;
use App\Tools\TaskAssistant\DeleteEventTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns requires_confirm when confirm is not true', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create(['user_id' => $user->id]);
    $tool = new DeleteEventTool($user, app(DeleteEventAction::class));

    $result = $tool->__invoke(['eventId' => $event->id]);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeFalse();
    expect($decoded['requires_confirm'])->toBeTrue();
    $event->refresh();
    expect($event->trashed())->toBeFalse();
});

it('deletes event and records tool call when confirm is true', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create(['user_id' => $user->id]);
    $tool = new DeleteEventTool($user, app(DeleteEventAction::class));

    $result = $tool->__invoke(['eventId' => $event->id, 'confirm' => true]);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeTrue();
    expect($decoded['event_id'])->toBe($event->id);
    $event->refresh();
    expect($event->trashed())->toBeTrue();
    $call = LlmToolCall::query()->where('tool_name', 'delete_event')->where('user_id', $user->id)->first();
    expect($call)->not->toBeNull();
    expect($call->status)->toBe(LlmToolCallStatus::Success);
});

it('does not delete event when called by another user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $event = Event::factory()->create(['user_id' => $userA->id]);
    $tool = new DeleteEventTool($userB, app(DeleteEventAction::class));

    $result = $tool->__invoke(['eventId' => $event->id, 'confirm' => true]);

    $decoded = json_decode($result, true);
    expect($decoded['ok'])->toBeFalse();
    expect($decoded)->toHaveKey('error');
    $event->refresh();
    expect($event->trashed())->toBeFalse();
});
