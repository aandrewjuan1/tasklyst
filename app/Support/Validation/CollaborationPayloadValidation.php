<?php

namespace App\Support\Validation;

use App\Enums\CollaborationPermission;
use Illuminate\Validation\Rule;

final class CollaborationPayloadValidation
{
    /**
     * @return array<string, mixed>
     */
    public static function createDefaults(): array
    {
        return [
            'collaboratableType' => null,
            'collaboratableId' => null,
            'email' => '',
            'permission' => CollaborationPermission::Edit->value,
        ];
    }

    /**
     * Livewire rules for creating a collaboration (invite).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function createRules(): array
    {
        return [
            'collaborationPayload.collaboratableType' => [
                'required',
                'string',
                Rule::in(['task', 'project', 'event']),
            ],
            'collaborationPayload.collaboratableId' => ['required', 'integer'],
            'collaborationPayload.email' => ['required', 'email'],
            'collaborationPayload.permission' => [
                'required',
                'string',
                Rule::in(array_map(fn (CollaborationPermission $p) => $p->value, CollaborationPermission::cases())),
            ],
        ];
    }
}
