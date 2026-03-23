<?php

use App\Services\LLM\TaskAssistant\TaskAssistantListingReferenceResolver;

test('resolver returns empty when flow is not schedule', function (): void {
    $resolver = new TaskAssistantListingReferenceResolver;
    $listing = sampleLastListing();

    expect($resolver->resolveForSchedule('schedule top 2', $listing, 'chat'))->toBe([]);
});

test('resolver returns empty when last listing is null', function (): void {
    $resolver = new TaskAssistantListingReferenceResolver;

    expect($resolver->resolveForSchedule('schedule top 2', null, 'schedule'))->toBe([]);
});

test('schedule top N takes first N items', function (): void {
    $resolver = new TaskAssistantListingReferenceResolver;
    $listing = sampleLastListing();

    $targets = $resolver->resolveForSchedule('please schedule top 2 for afternoon', $listing, 'schedule');

    expect($targets)->toHaveCount(2);
    expect($targets[0]['entity_id'])->toBe(10);
    expect($targets[1]['entity_id'])->toBe(20);
});

test('schedule first N is equivalent to top N', function (): void {
    $resolver = new TaskAssistantListingReferenceResolver;
    $listing = sampleLastListing();

    $targets = $resolver->resolveForSchedule('schedule first 1 tasks', $listing, 'schedule');

    expect($targets)->toHaveCount(1);
    expect($targets[0]['entity_id'])->toBe(10);
});

test('schedule last N takes trailing items', function (): void {
    $resolver = new TaskAssistantListingReferenceResolver;
    $listing = sampleLastListing();

    $targets = $resolver->resolveForSchedule('put last 2 in the evening', $listing, 'schedule');

    expect($targets)->toHaveCount(2);
    expect($targets[0]['entity_id'])->toBe(20);
    expect($targets[1]['entity_id'])->toBe(30);
});

test('explicit last wins over those when both appear', function (): void {
    $resolver = new TaskAssistantListingReferenceResolver;
    $listing = sampleLastListing();

    $targets = $resolver->resolveForSchedule('schedule those but last 2 only', $listing, 'schedule');

    expect($targets)->toHaveCount(2);
    expect($targets[0]['entity_id'])->toBe(20);
});

test('those without count returns full listing', function (): void {
    $resolver = new TaskAssistantListingReferenceResolver;
    $listing = sampleLastListing();

    $targets = $resolver->resolveForSchedule('schedule those in the afternoon', $listing, 'schedule');

    expect($targets)->toHaveCount(3);
});

test('those N returns first N items', function (): void {
    $resolver = new TaskAssistantListingReferenceResolver;
    $listing = sampleLastListing();

    $targets = $resolver->resolveForSchedule('schedule those 2 please', $listing, 'schedule');

    expect($targets)->toHaveCount(2);
    expect($targets[0]['entity_id'])->toBe(10);
    expect($targets[1]['entity_id'])->toBe(20);
});

/**
 * @return array{
 *   source_flow: string,
 *   items: list<array{entity_type: string, entity_id: int, title: string, position: int}>,
 * }
 */
function sampleLastListing(): array
{
    return [
        'source_flow' => 'prioritize',
        'items' => [
            ['entity_type' => 'task', 'entity_id' => 10, 'title' => 'A', 'position' => 0],
            ['entity_type' => 'task', 'entity_id' => 20, 'title' => 'B', 'position' => 1],
            ['entity_type' => 'task', 'entity_id' => 30, 'title' => 'C', 'position' => 2],
        ],
    ];
}
