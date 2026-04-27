<?php

use App\Models\User;
use App\Services\LLM\Prioritization\AssistantCandidateProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('assistant candidate snapshot uses user timezone and normalized schedule preferences', function (): void {
    $user = User::factory()->create([
        'timezone' => 'Asia/Manila',
        'schedule_preferences' => [
            'energy_bias' => 'invalid-value',
            'day_bounds' => ['start' => '06:30'],
            'lunch_block' => ['enabled' => true],
        ],
    ]);

    $snapshot = (new AssistantCandidateProvider)->candidatesForUser($user);

    expect($snapshot['timezone'])->toBe('Asia/Manila')
        ->and(data_get($snapshot, 'schedule_preferences.energy_bias'))->toBe('balanced')
        ->and(data_get($snapshot, 'schedule_preferences.day_bounds.start'))->toBe('06:30')
        ->and(data_get($snapshot, 'schedule_preferences.day_bounds.end'))->toBe('22:00')
        ->and(data_get($snapshot, 'schedule_preferences.lunch_block.start'))->toBe('12:00')
        ->and(data_get($snapshot, 'schedule_preferences.lunch_block.end'))->toBe('13:00');
});
