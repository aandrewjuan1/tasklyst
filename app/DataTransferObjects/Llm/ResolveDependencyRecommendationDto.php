<?php

namespace App\DataTransferObjects\Llm;

final readonly class ResolveDependencyRecommendationDto
{
    /**
     * @param  array<int, string>  $nextSteps
     * @param  array<int, string>  $blockers
     */
    public function __construct(
        public array $nextSteps,
        public array $blockers,
        public string $reasoning
    ) {}

    /**
     * @param  array<string, mixed>  $structured
     */
    public static function fromStructured(array $structured): ?self
    {
        $reasoning = trim((string) ($structured['reasoning'] ?? ''));
        if ($reasoning === '') {
            return null;
        }

        $steps = $structured['next_steps'] ?? null;
        if (! is_array($steps) || $steps === []) {
            return null;
        }

        $nextSteps = [];

        foreach ($steps as $step) {
            if (! is_string($step)) {
                return null;
            }

            $trimmed = trim($step);
            if ($trimmed === '') {
                return null;
            }

            $nextSteps[] = $trimmed;
        }

        if (count($nextSteps) < 2) {
            return null;
        }

        $blockersRaw = $structured['blockers'] ?? [];
        if ($blockersRaw !== null && ! is_array($blockersRaw)) {
            return null;
        }

        $blockers = [];

        foreach ((array) $blockersRaw as $blocker) {
            if (! is_string($blocker)) {
                return null;
            }

            $trimmed = trim($blocker);
            if ($trimmed !== '') {
                $blockers[] = $trimmed;
            }
        }

        return new self(
            nextSteps: $nextSteps,
            blockers: $blockers,
            reasoning: $reasoning
        );
    }
}
