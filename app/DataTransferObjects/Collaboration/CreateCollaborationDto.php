<?php

namespace App\DataTransferObjects\Collaboration;

use App\Enums\CollaborationPermission;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;

final readonly class CreateCollaborationDto
{
    public function __construct(
        public string $collaboratableType,
        public int $collaboratableId,
        public int $userId,
        public CollaborationPermission $permission,
    ) {}

    /**
     * Create from validated collaborationPayload array.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $permission = CollaborationPermission::tryFrom((string) ($validated['permission'] ?? 'edit'))
            ?? CollaborationPermission::Edit;

        return new self(
            collaboratableType: (string) $validated['collaboratableType'],
            collaboratableId: (int) $validated['collaboratableId'],
            userId: (int) $validated['userId'],
            permission: $permission,
        );
    }

    /**
     * Get the morph class for the collaboratable type.
     */
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
            'user_id' => $this->userId,
            'permission' => $this->permission,
        ];
    }
}
