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

test('task assistant prompt data uses user timezone and normalized preferences', function () {
    $user = User::factory()->create([
        'timezone' => 'Asia/Manila',
        'schedule_preferences' => [
            'energy_bias' => 'morning',
            'day_bounds' => ['start' => '07:30', 'end' => '20:30'],
            'lunch_block' => ['enabled' => false, 'start' => '12:15', 'end' => '13:15'],
        ],
    ]);

    $data = (new TaskAssistantPromptData)->forUser($user);

    expect(data_get($data, 'userContext.timezone'))->toBe('Asia/Manila')
        ->and(data_get($data, 'userContext.schedule_preferences.energy_bias'))->toBe('morning')
        ->and(data_get($data, 'userContext.schedule_preferences.day_bounds.start'))->toBe('07:30')
        ->and(data_get($data, 'userContext.schedule_preferences.lunch_block.enabled'))->toBeFalse();
});
