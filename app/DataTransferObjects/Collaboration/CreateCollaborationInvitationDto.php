<?php

namespace App\DataTransferObjects\Collaboration;

use App\Enums\CollaborationPermission;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;

final readonly class CreateCollaborationInvitationDto
{
    public function __construct(
        public string $collaboratableType,
        public int $collaboratableId,
        public int $inviterId,
        public string $inviteeEmail,
        public CollaborationPermission $permission,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated, int $inviterId): self
    {
        $permission = CollaborationPermission::tryFrom((string) ($validated['permission'] ?? 'edit'))
            ?? CollaborationPermission::Edit;

        return new self(
            collaboratableType: (string) $validated['collaboratableType'],
            collaboratableId: (int) $validated['collaboratableId'],
            inviterId: $inviterId,
            inviteeEmail: (string) $validated['email'],
            permission: $permission,
        );
    }

    public function collaboratableMorphClass(): string
    {
        return match ($this->collaboratableType) {
            'task' => Task::class,
            'project' => Project::class,
            'event' => Event::class,
            default => throw new \InvalidArgumentException("Unknown collaboratable type: {$this->collaboratableType}"),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toServiceAttributes(): array
    {
        return [
            'collaboratable_type' => $this->collaboratableMorphClass(),
            'collaboratable_id' => $this->collaboratableId,
            'inviter_id' => $this->inviterId,
            'invitee_email' => $this->inviteeEmail,
            'permission' => $this->permission,
        ];
    }
}
