<?php

use App\DataTransferObjects\Llm\ResolveDependencyRecommendationDto;

it('builds dto from valid structured payload', function (): void {
    $structured = [
        'reasoning' => 'Finish the prerequisite first to unblock the rest.',
        'next_steps' => [
            'Complete the research outline.',
            'Draft the first section.',
        ],
        'blockers' => [
            'Waiting on feedback from tutor.',
        ],
    ];

    $dto = ResolveDependencyRecommendationDto::fromStructured($structured);

    expect($dto)->not->toBeNull()
        ->and($dto->nextSteps)->toHaveCount(2)
        ->and($dto->blockers)->toHaveCount(1)
        ->and($dto->reasoning)->toBe('Finish the prerequisite first to unblock the rest.');
});

it('returns null when next_steps missing or invalid', function (): void {
    $missingSteps = [
        'reasoning' => 'Not enough context.',
    ];

    expect(ResolveDependencyRecommendationDto::fromStructured($missingSteps))->toBeNull();

    $oneStepOnly = [
        'reasoning' => 'Do this first.',
        'next_steps' => ['Only one step'],
    ];

    expect(ResolveDependencyRecommendationDto::fromStructured($oneStepOnly))->toBeNull();

    $nonStringStep = [
        'reasoning' => 'Do these.',
        'next_steps' => ['Ok', 123],
    ];

    expect(ResolveDependencyRecommendationDto::fromStructured($nonStringStep))->toBeNull();
});
