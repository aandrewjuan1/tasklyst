<?php

use App\DataTransferObjects\Llm\TaskScheduleRecommendationDto;

it('builds dto from valid structured payload with start and duration only', function (): void {
    $structured = [
        'reasoning' => 'Schedule before the deadline.',
        'start_datetime' => now()->addDay()->setTime(9, 0)->toIso8601String(),
        'duration' => 60,
        'priority' => 'high',
    ];

    $dto = TaskScheduleRecommendationDto::fromStructured($structured);

    expect($dto)->not->toBeNull()
        ->and($dto->startDatetime)->not->toBeNull()
        ->and($dto->endDatetime)->toBeNull()
        ->and($dto->durationMinutes)->toBe(60)
        ->and($dto->priority)->toBe('high')
        ->and($dto->reasoning)->toBe('Schedule before the deadline.')
        ->and($dto->proposedProperties())->not->toHaveKey('endDatetime')
        ->and($dto->proposedProperties())->toHaveKey('startDatetime')
        ->and($dto->proposedProperties())->toHaveKey('duration');
});

it('builds dto from start only when user asks when to start', function (): void {
    $structured = [
        'reasoning' => 'Start in the morning.',
        'start_datetime' => now()->addDay()->setTime(9, 0)->toIso8601String(),
    ];

    $dto = TaskScheduleRecommendationDto::fromStructured($structured);

    expect($dto)->not->toBeNull()
        ->and($dto->startDatetime)->not->toBeNull()
        ->and($dto->endDatetime)->toBeNull()
        ->and($dto->durationMinutes)->toBeNull()
        ->and($dto->proposedProperties())->not->toHaveKey('endDatetime')
        ->and($dto->proposedProperties())->toHaveKey('startDatetime');
});

it('returns null when reasoning or all actionable fields missing', function (): void {
    $noReasoning = [
        'start_datetime' => now()->addDay()->toIso8601String(),
    ];

    $dtoWithoutReasoning = TaskScheduleRecommendationDto::fromStructured($noReasoning);

    expect($dtoWithoutReasoning)->not->toBeNull()
        ->and($dtoWithoutReasoning->reasoning)->toBe('Schedule suggested by assistant.');

    $noActionableFields = [
        'reasoning' => 'Not enough context.',
    ];

    $dtoWithoutFields = TaskScheduleRecommendationDto::fromStructured($noActionableFields);

    expect($dtoWithoutFields)->toBeNull();
});
