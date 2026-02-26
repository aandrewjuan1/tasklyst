<?php

use App\DataTransferObjects\Llm\TaskScheduleRecommendationDto;

it('builds dto from valid structured payload', function (): void {
    $structured = [
        'reasoning' => 'Schedule before the deadline.',
        'start_datetime' => now()->addDay()->setTime(9, 0)->toIso8601String(),
        'end_datetime' => now()->addDay()->setTime(10, 0)->toIso8601String(),
        'duration' => 60,
        'priority' => 'high',
    ];

    $dto = TaskScheduleRecommendationDto::fromStructured($structured);

    expect($dto)->not->toBeNull()
        ->and($dto->startDatetime)->not->toBeNull()
        ->and($dto->endDatetime)->not->toBeNull()
        ->and($dto->durationMinutes)->toBe(60)
        ->and($dto->priority)->toBe('high')
        ->and($dto->reasoning)->toBe('Schedule before the deadline.');
});

it('returns null when reasoning or all actionable fields missing', function (): void {
    $noReasoning = [
        'start_datetime' => now()->addDay()->toIso8601String(),
    ];

    $dtoWithoutReasoning = TaskScheduleRecommendationDto::fromStructured($noReasoning);

    expect($dtoWithoutReasoning)->toBeNull();

    $noActionableFields = [
        'reasoning' => 'Not enough context.',
    ];

    $dtoWithoutFields = TaskScheduleRecommendationDto::fromStructured($noActionableFields);

    expect($dtoWithoutFields)->toBeNull();
});
