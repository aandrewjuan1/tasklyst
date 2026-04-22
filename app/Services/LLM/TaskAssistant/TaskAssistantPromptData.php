<?php

namespace App\Services\LLM\TaskAssistant;

use App\Models\User;

class TaskAssistantPromptData
{
    /**
     * Build user context for the task-assistant system prompt.
     *
     * @return array{userContext: array{id: int, timezone: string, date_format: string}}
     */
    public function forUser(User $user): array
    {
        return [
            'userContext' => [
                'id' => $user->id,
                // Provide a stable display name so the LLM doesn't guess one.
                'name' => (string) $user->name,
                'timezone' => config('app.timezone'),
                'date_format' => 'Y-m-d H:i',
            ],
        ];
    }
}
