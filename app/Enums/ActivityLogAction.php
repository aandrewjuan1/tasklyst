<?php

namespace App\Enums;

enum ActivityLogAction: string
{
    case ItemCreated = 'item_created';
    case ItemDeleted = 'item_deleted';
    case ItemRestored = 'item_restored';

    case FieldUpdated = 'field_updated';

    case CollaboratorInvited = 'collaborator_invited';
    case CollaboratorInvitationAccepted = 'collaborator_invitation_accepted';
    case CollaboratorInvitationDeclined = 'collaborator_invitation_declined';

    case CollaboratorLeft = 'collaborator_left';
    case CollaboratorRemoved = 'collaborator_removed';
    case CollaboratorPermissionUpdated = 'collaborator_permission_updated';

    case FocusSessionCompleted = 'focus_session_completed';

    public function label(): string
    {
        return match ($this) {
            self::ItemCreated => 'Item created',
            self::ItemDeleted => 'Item deleted',
            self::ItemRestored => 'Item restored',

            self::FieldUpdated => 'Field updated',

            self::CollaboratorInvited => 'Collaborator invited',
            self::CollaboratorInvitationAccepted => 'Invitation accepted',
            self::CollaboratorInvitationDeclined => 'Invitation declined',

            self::CollaboratorLeft => 'Collaborator left',
            self::CollaboratorRemoved => 'Collaborator removed',
            self::CollaboratorPermissionUpdated => 'Collaborator permission updated',

            self::FocusSessionCompleted => 'Focus session completed',
        };
    }
}
