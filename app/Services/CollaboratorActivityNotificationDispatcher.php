<?php

namespace App\Services;

use App\Enums\ActivityLogAction;
use App\Models\ActivityLog;
use App\Models\Collaboration;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\CollaboratorActivityOnItemNotification;
use App\Support\WorkspaceNotificationParams;
use Illuminate\Database\Eloquent\Model;

final class CollaboratorActivityNotificationDispatcher
{
    public function __construct(
        private UserNotificationBroadcastService $userNotificationBroadcastService,
    ) {}

    public function dispatchForActivityLog(ActivityLog $activityLog): void
    {
        $activityLog->loadMissing(['user', 'loggable']);

        $actor = $activityLog->user;
        if (! $actor instanceof User) {
            return;
        }

        $loggable = $activityLog->loggable;
        if (! $this->isSupportedLoggable($loggable)) {
            return;
        }

        $ownerId = (int) $loggable->user_id;
        if ($ownerId === 0 || $ownerId === (int) $actor->id) {
            return;
        }

        if (in_array($activityLog->action, [
            ActivityLogAction::CollaboratorInvitationAccepted,
            ActivityLogAction::CollaboratorInvitationDeclined,
        ], true)) {
            return;
        }

        $collaborationExists = Collaboration::query()
            ->where('collaboratable_type', $loggable->getMorphClass())
            ->where('collaboratable_id', $loggable->getKey())
            ->where('user_id', $actor->id)
            ->exists();

        if (! $collaborationExists) {
            return;
        }

        $workspaceParams = WorkspaceNotificationParams::forLoggable($loggable);
        if ($workspaceParams === []) {
            return;
        }

        $itemTitle = WorkspaceNotificationParams::itemTitle($loggable);
        if ($itemTitle === '') {
            $itemTitle = __('Untitled');
        }

        $kind = WorkspaceNotificationParams::itemKindLabel($loggable);
        $title = match ($kind) {
            'task' => __('Task: :title', ['title' => $itemTitle]),
            'event' => __('Event: :title', ['title' => $itemTitle]),
            'project' => __('Project: :title', ['title' => $itemTitle]),
            default => __('Collaboration: :title', ['title' => $itemTitle]),
        };

        $owner = User::query()->find($ownerId);
        if (! $owner instanceof User) {
            return;
        }

        $actorDisplay = $this->collaboratorDisplayLabel($actor);
        $activityDetail = $activityLog->message();
        $message = $activityDetail !== ''
            ? __(':user — :activity', ['user' => $actorDisplay, 'activity' => $activityDetail])
            : __(':user made a change on this item.', ['user' => $actorDisplay]);

        $owner->notify(new CollaboratorActivityOnItemNotification(
            title: $title,
            message: $message,
            workspaceParams: $workspaceParams,
            meta: [
                'activity_log_id' => $activityLog->id,
                'action' => $activityLog->action->value,
                'actor_user_id' => $actor->id,
                'actor_name' => trim((string) $actor->name) !== '' ? (string) $actor->name : null,
                'actor_email' => (string) $actor->email,
                'loggable_type' => $activityLog->loggable_type,
                'loggable_id' => $activityLog->loggable_id,
            ],
        ));

        $this->userNotificationBroadcastService->broadcastInboxUpdated($owner);
    }

    private function collaboratorDisplayLabel(User $user): string
    {
        $name = trim((string) $user->name);

        if ($name !== '') {
            return $name;
        }

        return (string) $user->email;
    }

    private function isSupportedLoggable(?Model $loggable): bool
    {
        return $loggable instanceof Task
            || $loggable instanceof Project
            || $loggable instanceof Event;
    }
}
