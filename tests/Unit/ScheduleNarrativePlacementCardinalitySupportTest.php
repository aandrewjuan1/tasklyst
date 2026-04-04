<?php

use App\Services\LLM\TaskAssistant\ScheduleNarrativePlacementCardinalitySupport;

test('misrepresentsPlacementCount flags all three with one placed and unplaced', function (): void {
    expect(ScheduleNarrativePlacementCardinalitySupport::misrepresentsPlacementCount(
        'Placing all three of these urgent tasks together will help you stay steady.',
        1,
        2
    ))->toBeTrue();
});

test('misrepresentsPlacementCount flags scheduled your top tasks when one placed and unplaced', function (): void {
    expect(ScheduleNarrativePlacementCardinalitySupport::misrepresentsPlacementCount(
        "I've scheduled your top tasks for later today.",
        1,
        1
    ))->toBeTrue();
});

test('misrepresentsPlacementCount allows valid copy when all placed', function (): void {
    expect(ScheduleNarrativePlacementCardinalitySupport::misrepresentsPlacementCount(
        'Both tasks sit in your evening window so you can focus.',
        2,
        0
    ))->toBeFalse();
});

test('misrepresentsPlacementCount allows mentioning three when not claiming placement', function (): void {
    expect(ScheduleNarrativePlacementCardinalitySupport::misrepresentsPlacementCount(
        'You asked about three tasks; I placed the highest-priority one first.',
        1,
        2
    ))->toBeFalse();
});

test('claimsMultiplePlacedTasksWithSingleBlock flags these two tasks with one block', function (): void {
    expect(ScheduleNarrativePlacementCardinalitySupport::claimsMultiplePlacedTasksWithSingleBlock(
        'Fitting these two tasks into a couple days makes sense.',
        1
    ))->toBeTrue();
});

test('claimsMultiplePlacedTasksWithSingleBlock allows single task language with one block', function (): void {
    expect(ScheduleNarrativePlacementCardinalitySupport::claimsMultiplePlacedTasksWithSingleBlock(
        'This block keeps your top task focused.',
        1
    ))->toBeFalse();
});
