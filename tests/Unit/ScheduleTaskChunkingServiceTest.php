<?php

use App\Services\LLM\Scheduling\ScheduleTaskChunkingService;

it('returns single chunk when total is within max focus', function (): void {
    config([
        'task-assistant.schedule.chunking' => [
            'max_focus_minutes' => 90,
            'min_chunk_minutes' => 15,
            'preferred_chunk_sizes' => [90, 60, 45],
        ],
    ]);

    $svc = new ScheduleTaskChunkingService;
    expect($svc->chunkTaskMinutes(45))->toBe([45]);
    expect($svc->chunkTaskMinutes(90))->toBe([90]);
});

it('partitions oversized work using preferred sizes and merges trailing sliver into previous chunk', function (): void {
    config([
        'task-assistant.schedule.chunking' => [
            'max_focus_minutes' => 90,
            'min_chunk_minutes' => 15,
            'preferred_chunk_sizes' => [90, 60, 45, 30],
        ],
    ]);

    $svc = new ScheduleTaskChunkingService;
    expect($svc->chunkTaskMinutes(300))->toBe([90, 90, 90, 30]);
});

it('returns empty list for non-positive input', function (): void {
    $svc = new ScheduleTaskChunkingService;
    expect($svc->chunkTaskMinutes(0))->toBe([]);
    expect($svc->chunkTaskMinutes(-5))->toBe([]);
});
