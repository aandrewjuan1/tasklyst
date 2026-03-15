<?php

use App\Models\User;
use App\Services\TaskAssistantPromptData;

test('task assistant system prompt view is renderable with builder data', function () {
    $user = User::factory()->create();
    $data = (new TaskAssistantPromptData)->forUser($user);
    $output = view('prompts.task-assistant-system', $data)->render();

    expect($output)
        ->toContain('Hermes 3:3B')
        ->toContain((string) $user->id)
        ->toContain('create_task')
        ->toContain('Create a new task');
});
