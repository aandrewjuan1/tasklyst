<?php

namespace App\Services\LLM\TaskAssistant;

use App\Models\User;
use App\Services\LLM\Scheduling\UserSchedulePreferences;

class TaskAssistantPromptData
{
    /**
     * Build user context for the task-assistant system prompt.
     *
     * @return array{userContext: array{id: int, name: string, timezone: string, date_format: string, schedule_preferences: array<string, mixed>}}
     */
    public function forUser(User $user): array
    {
        return [
            'userContext' => [
                'id' => $user->id,
                // Provide a stable display name so the LLM doesn't guess one.
                'name' => (string) $user->name,
                'timezone' => UserSchedulePreferences::timezoneForUser($user),
                'date_format' => 'Y-m-d H:i',
                'schedule_preferences' => UserSchedulePreferences::normalizedForUser($user),
            ],
        ];
    }
}
