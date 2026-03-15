<?php

namespace App\Services;

use App\Models\User;

class TaskAssistantPromptData
{
    /**
     * Build user context and tool manifest for the task-assistant system prompt.
     *
     * @return array{userContext: array{id: int, timezone: string, date_format: string}, toolManifest: list<array{name: string, description: string}>}
     */
    public function forUser(User $user): array
    {
        return [
            'userContext' => [
                'id' => $user->id,
                'timezone' => config('app.timezone'),
                'date_format' => 'Y-m-d H:i',
            ],
            'toolManifest' => $this->buildToolManifest($user),
        ];
    }

    /**
     * @return list<array{name: string, description: string}>
     */
    private function buildToolManifest(User $user): array
    {
        $toolManifest = [];
        $tools = config('prism-tools', []);

        foreach ($tools as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }
            $tool = app()->make($class, ['user' => $user]);
            $toolManifest[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
            ];
        }

        return $toolManifest;
    }
}
