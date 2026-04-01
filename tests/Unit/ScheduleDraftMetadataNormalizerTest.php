<?php

use App\Services\LLM\Scheduling\ScheduleDraftMetadataNormalizer;

it('normalizes legacy daily schedule payload into canonical metadata', function (): void {
    $normalizer = app(ScheduleDraftMetadataNormalizer::class);

    $result = $normalizer->normalizeAndValidate([
        'daily_schedule' => [
            'proposals' => [
                [
                    'proposal_id' => 'legacy-a',
                    'status' => 'pending',
                    'entity_type' => 'task',
                    'entity_id' => 11,
                    'title' => 'Legacy task',
                    'start_datetime' => '2026-04-01T10:00:00+08:00',
                    'duration_minutes' => 45,
                ],
            ],
        ],
    ]);

    expect($result['valid'])->toBeTrue();
    expect(data_get($result, 'canonical_metadata.schedule.schema_version'))->toBe(2);
    expect(data_get($result, 'canonical_metadata.schedule.proposals.0.proposal_uuid'))->toBe('legacy-a');
    expect(data_get($result, 'canonical_metadata.schedule.proposals.0.display_order'))->toBe(0);
});

it('returns typed reason when schedule metadata is missing', function (): void {
    $normalizer = app(ScheduleDraftMetadataNormalizer::class);

    $result = $normalizer->normalizeAndValidate([]);

    expect($result['valid'])->toBeFalse();
    expect($result['reason_code'])->toBe('missing_schedule_payload');
});
