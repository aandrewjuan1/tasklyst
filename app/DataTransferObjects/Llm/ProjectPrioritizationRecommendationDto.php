<?php

namespace App\DataTransferObjects\Llm;

final readonly class ProjectPrioritizationRecommendationDto
{
    /**
     * @param  array<int, array{rank:int,name:string,end_datetime?:string|null}>  $rankedProjects
     */
    public function __construct(
        public array $rankedProjects,
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

        $ranked = $structured['ranked_projects'] ?? null;
        if (! is_array($ranked) || $ranked === []) {
            return null;
        }

        $normalized = [];

        foreach ($ranked as $item) {
            if (! is_array($item)) {
                return null;
            }

            if (! array_key_exists('rank', $item) || ! is_numeric($item['rank'])) {
                return null;
            }

            $rank = (int) $item['rank'];
            if ($rank <= 0) {
                return null;
            }

            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                return null;
            }

            $normalized[] = [
                'rank' => $rank,
                'name' => $name,
                'end_datetime' => $item['end_datetime'] ?? null,
            ];
        }

        if ($normalized === []) {
            return null;
        }

        return new self(
            rankedProjects: $normalized,
            reasoning: $reasoning,
        );
    }
}
