<?php

namespace App\Services;

use App\Enums\ActivityLogAction;
use App\Enums\CollaborationInviteNotificationState;
use App\Enums\ReminderStatus;
use App\Enums\ReminderType;
use App\Models\Collaboration;
use App\Models\CollaborationInvitation;
use App\Models\DatabaseNotification;
use App\Models\Reminder;
use App\Models\User;
use App\Notifications\CollaborationInvitationReceivedNotification;
use App\Services\Reminders\ReminderDispatcherService;
use App\Services\Reminders\ReminderSchedulerService;
use Illuminate\Support\Facades\DB;

class CollaborationInvitationService
{
    public function __construct(
        private ActivityLogRecorder $activityLogRecorder,
        private ReminderDispatcherService $reminderDispatcherService,
        private ReminderSchedulerService $reminderSchedulerService,
        private CollaborationInvitationOwnerResponseNotifier $collaborationInvitationOwnerResponseNotifier,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createInvitation(array $attributes): CollaborationInvitation
    {
        return DB::transaction(function () use ($attributes): CollaborationInvitation {
            $lookupAttributes = [
                'collaboratable_type' => $attributes['collaboratable_type'],
                'collaboratable_id' => $attributes['collaboratable_id'],
                'invitee_email' => $attributes['invitee_email'],
                'status' => 'pending',
            ];

            $invitation = CollaborationInvitation::query()->firstOrCreate($lookupAttributes, $attributes);

            if (! $invitation->wasRecentlyCreated) {
                return $invitation;
            }

            $invitation->load(['collaboratable', 'inviter']);

            if ($invitation->collaboratable !== null && $invitation->inviter !== null) {
                $this->activityLogRecorder->record(
                    $invitation->collaboratable,
                    $invitation->inviter,
                    ActivityLogAction::CollaboratorInvited,
                    [
                        'invitee_email' => $invitation->invitee_email,
                        'permission' => $invitation->permission?->value,
                    ]
                );
            }

            $invitee = $invitation->invitee_user_id !== null
                ? User::query()->find((int) $invitation->invitee_user_id)
                : User::query()->where('email', $invitation->invitee_email)->first();

            if ($invitee instanceof User) {
                if ($invitation->invitee_user_id === null) {
                    $invitation->invitee_user_id = $invitee->id;
                    $invitation->save();
                }

                // Persist as a reminder (immediate) so it also appears in the reminders pipeline.
                Reminder::query()->create([
                    'user_id' => $invitee->id,
                    'remindable_type' => $invitation->getMorphClass(),
                    'remindable_id' => $invitation->id,
                    'type' => ReminderType::CollaborationInviteReceived,
                    'scheduled_at' => now(),
                    'status' => ReminderStatus::Pending,
                    'payload' => [
                        'invitation_id' => $invitation->id,
                        'invitee_email' => $invitation->invitee_email,
                        'collaboratable_type' => $invitation->collaboratable_type,
                        'collaboratable_id' => $invitation->collaboratable_id,
                        'permission' => $invitation->permission?->value,
                    ],
                ]);

                $this->reminderDispatcherService->queueProcessDueForRemindable($invitation);
            }

            return $invitation;
        });
    }

    public function markAccepted(CollaborationInvitation $invitation, ?User $invitee = null): Collaboration
    {
        return DB::transaction(function () use ($invitation, $invitee): Collaboration {
            $this->finalizeAcceptedInvitation($invitation, $invitee);

            return Collaboration::query()->firstOrCreate([
                'collaboratable_type' => $invitation->collaboratable_type,
                'collaboratable_id' => $invitation->collaboratable_id,
                'user_id' => $invitation->invitee_user_id ?? $invitee?->id,
            ], [
                'permission' => $invitation->permission,
            ]);
        });
    }

    public function finalizeAcceptedInvitation(CollaborationInvitation $invitation, ?User $invitee = null): void
    {
        DB::transaction(function () use ($invitation, $invitee): void {
            if ($invitee !== null && $invitation->invitee_user_id === null) {
                $invitation->invitee_user_id = $invitee->id;
            }

            $invitation->status = 'accepted';
            $invitation->save();
            $this->reminderSchedulerService->cancelForRemindable($invitation, ReminderType::CollaborationInviteReceived);
            $this->markInviteNotificationsHandled($invitation, CollaborationInviteNotificationState::Accepted);

            $invitation->load('collaboratable');
            if ($invitation->collaboratable !== null) {
                $actor = $invitee ?? $invitation->invitee;
                $this->activityLogRecorder->record(
                    $invitation->collaboratable,
                    $actor,
                    ActivityLogAction::CollaboratorInvitationAccepted,
                    [
                        'invitee_email' => $invitation->invitee_email,
                        'permission' => $invitation->permission?->value,
                    ]
                );
            }

            $this->collaborationInvitationOwnerResponseNotifier->notifyInviterOfResponse($invitation, true, $invitee);
        });
    }

    public function markInviteNotificationsHandled(
        CollaborationInvitation $invitation,
        CollaborationInviteNotificationState $state,
    ): void {
        $inviteeUserId = $invitation->invitee_user_id;
        if ($inviteeUserId === null) {
            return;
        }

        $notifications = DatabaseNotification::query()
            ->where('type', CollaborationInvitationReceivedNotification::class)
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $inviteeUserId)
            ->get();

        foreach ($notifications as $notification) {
            if (! $notification instanceof DatabaseNotification) {
                continue;
            }

            $data = $this->notificationDataAsArray($notification->data);
            if ((int) data_get($data, 'entity.id') !== (int) $invitation->id) {
                continue;
            }

            if ($state === CollaborationInviteNotificationState::Accepted) {
                $data['title'] = __('Collaboration invite accepted');
                $data['message'] = __('You accepted this collaboration invitation.');
            } else {
                $data['title'] = __('Collaboration invite declined');
                $data['message'] = __('You declined this collaboration invitation.');
            }

            $notification->forceFill([
                'data' => $data,
                'collaboration_invite_state' => $state,
                'read_at' => $notification->read_at ?? now(),
            ])->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationDataAsArray(mixed $rawData): array
    {
        if (is_array($rawData)) {
            return $rawData;
        }

        if (is_string($rawData) && $rawData !== '') {
            $decoded = json_decode($rawData, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
