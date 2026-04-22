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

test('schedule top task resolves first listing item', function (): void {
    $resolver = new TaskAssistantListingReferenceResolver;
    $listing = sampleLastListing();

    $targets = $resolver->resolveForSchedule('schedule the top task for later today', $listing, 'schedule');

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

test('single deictic reference after prioritize listing resolves full ranked set', function (): void {
    $resolver = new TaskAssistantListingReferenceResolver;
    $listing = sampleLastListing();

    $targets = $resolver->resolveForSchedule('schedule it for later today', $listing, 'schedule');

    expect($targets)->toHaveCount(3);
    expect($targets[0]['entity_id'])->toBe(10);
    expect($targets[1]['entity_id'])->toBe(20);
    expect($targets[2]['entity_id'])->toBe(30);
});

test('single deictic reference after schedule listing stays single target', function (): void {
    $resolver = new TaskAssistantListingReferenceResolver;
    $listing = sampleLastListing();
    $listing['source_flow'] = 'schedule';

    $targets = $resolver->resolveForSchedule('schedule it for later today', $listing, 'schedule');

    expect($targets)->toHaveCount(1);
    expect($targets[0]['entity_id'])->toBe(10);
});

test('temporal phrase this week is not treated as single deictic item reference', function (): void {
    $resolver = new TaskAssistantListingReferenceResolver;
    $listing = sampleLastListing();
    $listing['source_flow'] = 'schedule';

    $targets = $resolver->resolveForSchedule('pick another time this week', $listing, 'schedule');

    expect($targets)->toBe([]);
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

/**
 * @return array{
 *   source_flow: string,
 *   items: list<array{entity_type: string, entity_id: int, title: string, position: int}>
 * }
 */
function sampleMixedListing(): array
{
    return [
        'source_flow' => 'prioritize',
        'items' => [
            ['entity_type' => 'task', 'entity_id' => 10, 'title' => 'A', 'position' => 0],
            ['entity_type' => 'event', 'entity_id' => 99, 'title' => 'E', 'position' => 1],
            ['entity_type' => 'task', 'entity_id' => 20, 'title' => 'B', 'position' => 2],
            ['entity_type' => 'task', 'entity_id' => 30, 'title' => 'C', 'position' => 3],
        ],
    ];
}

/**
 * @return array{
 *   source_flow: string,
 *   items: list<array{entity_type: string, entity_id: int, title: string, position: int}>
 * }
 */
function sampleEventFirstMixedListing(): array
{
    return [
        'source_flow' => 'prioritize',
        'items' => [
            ['entity_type' => 'event', 'entity_id' => 99, 'title' => 'E', 'position' => 0],
            ['entity_type' => 'task', 'entity_id' => 10, 'title' => 'A', 'position' => 1],
            ['entity_type' => 'task', 'entity_id' => 20, 'title' => 'B', 'position' => 2],
        ],
    ];
}

test('schedule the two tasks picks first two tasks (word count)', function (): void {
    $resolver = new TaskAssistantListingReferenceResolver;
    $listing = sampleMixedListing();

    $targets = $resolver->resolveForSchedule('schedule the two tasks for later', $listing, 'schedule');

    expect($targets)->toHaveCount(2);
    expect($targets[0]['entity_id'])->toBe(10);
    expect($targets[1]['entity_id'])->toBe(20);
});

test('schedule last 2 tasks picks trailing tasks (word count)', function (): void {
    $resolver = new TaskAssistantListingReferenceResolver;
    $listing = sampleMixedListing();

    $targets = $resolver->resolveForSchedule('put last 2 tasks in the evening', $listing, 'schedule');

    expect($targets)->toHaveCount(2);
    expect($targets[0]['entity_id'])->toBe(20);
    expect($targets[1]['entity_id'])->toBe(30);
});

test('schedule only the first one picks first item (no explicit type)', function (): void {
    $resolver = new TaskAssistantListingReferenceResolver;
    $listing = sampleEventFirstMixedListing();

    $targets = $resolver->resolveForSchedule('schedule only the first one for later', $listing, 'schedule');

    expect($targets)->toHaveCount(1);
    expect($targets[0]['entity_id'])->toBe(99);
});

test('schedule only the first one tasks picks first task (mixed listing)', function (): void {
    $resolver = new TaskAssistantListingReferenceResolver;
    $listing = sampleEventFirstMixedListing();

    $targets = $resolver->resolveForSchedule('schedule only the first one tasks for later', $listing, 'schedule');

    expect($targets)->toHaveCount(1);
    expect($targets[0]['entity_id'])->toBe(10);
});

test('schedule only the last one tasks picks last task from trailing (mixed listing)', function (): void {
    $resolver = new TaskAssistantListingReferenceResolver;
    $listing = sampleEventFirstMixedListing();

    $targets = $resolver->resolveForSchedule('put last one tasks in the evening', $listing, 'schedule');

    expect($targets)->toHaveCount(1);
    expect($targets[0]['entity_id'])->toBe(20);
});

test('schedule second picks second item overall (mixed listing)', function (): void {
    $resolver = new TaskAssistantListingReferenceResolver;
    $listing = sampleMixedListing();

    $targets = $resolver->resolveForSchedule('schedule second for later', $listing, 'schedule');

    expect($targets)->toHaveCount(1);
    expect($targets[0]['entity_id'])->toBe(99);
});

test('schedule second task picks second task among task items (mixed listing)', function (): void {
    $resolver = new TaskAssistantListingReferenceResolver;
    $listing = sampleMixedListing();

    $targets = $resolver->resolveForSchedule('schedule second task for later', $listing, 'schedule');

    expect($targets)->toHaveCount(1);
    expect($targets[0]['entity_id'])->toBe(20);
});
