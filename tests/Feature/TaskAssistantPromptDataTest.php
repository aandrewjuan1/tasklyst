<?php

use App\Models\User;
use App\Services\LLM\TaskAssistant\TaskAssistantPromptData;

test('task assistant system prompt view is renderable with builder data', function () {
    $user = User::factory()->create();
    $data = (new TaskAssistantPromptData)->forUser($user);
    $output = view('prompts.task-assistant-system', $data)->render();

    expect($output)
        ->toContain('Hermes 3:3B')
        ->toContain((string) $user->id)
        ->toContain('GENERAL GUIDANCE RULES')
        ->toContain('Do not rely on tools or functions that are not explicitly available in the current flow.');
});
