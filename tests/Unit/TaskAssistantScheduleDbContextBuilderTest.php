<?php

use App\Models\User;
use App\Services\LLM\Scheduling\TaskAssistantScheduleDbContextBuilder;
use Carbon\CarbonImmutable;

it('uses user timezone when building scheduling snapshot day metadata', function (): void {
    CarbonImmutable::setTestNow('2026-04-10T17:30:00+00:00');

    $user = User::factory()->create([
        'timezone' => 'Asia/Manila',
    ]);

    $builder = app(TaskAssistantScheduleDbContextBuilder::class);
    $result = $builder->buildForUser($user, 'schedule my tasks');
    $snapshot = $result['snapshot'] ?? [];

    expect((string) ($snapshot['timezone'] ?? ''))->toBe('Asia/Manila');
    expect((string) ($snapshot['today'] ?? ''))->toBe('2026-04-11');
});
