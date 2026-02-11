<?php

namespace App\Enums;

enum ActivityLogAction: string
{
    case ItemCreated = 'item_created';
    case ItemUpdated = 'item_updated';
    case ItemDeleted = 'item_deleted';

    case FieldUpdated = 'field_updated';

    case CollaboratorInvited = 'collaborator_invited';
    case CollaboratorInvitationAccepted = 'collaborator_invitation_accepted';
    case CollaboratorInvitationDeclined = 'collaborator_invitation_declined';

    case CollaboratorLeft = 'collaborator_left';
    case CollaboratorRemoved = 'collaborator_removed';
    case CollaboratorPermissionUpdated = 'collaborator_permission_updated';

    public function label(): string
    {
        return match ($this) {
            self::ItemCreated => 'Item created',
            self::ItemUpdated => 'Item updated',
            self::ItemDeleted => 'Item deleted',

            self::FieldUpdated => 'Field updated',

            self::CollaboratorInvited => 'Collaborator invited',
            self::CollaboratorInvitationAccepted => 'Invitation accepted',
            self::CollaboratorInvitationDeclined => 'Invitation declined',

            self::CollaboratorLeft => 'Collaborator left',
            self::CollaboratorRemoved => 'Collaborator removed',
            self::CollaboratorPermissionUpdated => 'Collaborator permission updated',
        };
    }
}
