<?php

namespace App\Support\Validation;

final class CommentPayloadValidation
{
    /**
     * @return array<string, mixed>
     */
    public static function createDefaults(): array
    {
        return [
            'taskId' => null,
            'content' => '',
        ];
    }

    /**
     * Livewire rules for creating a comment.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function createRules(): array
    {
        return [
            'commentPayload.taskId' => ['required', 'integer', 'exists:tasks,id'],
            'commentPayload.content' => ['required', 'string', 'max:65535', 'regex:/\S/'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function updateDefaults(): array
    {
        return [
            'content' => '',
            'isPinned' => false,
        ];
    }

    /**
     * Livewire rules for updating a comment.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function updateRules(): array
    {
        return [
            'commentPayload.content' => ['required', 'string', 'max:65535', 'regex:/\S/'],
            'commentPayload.isPinned' => ['boolean'],
        ];
    }
}
