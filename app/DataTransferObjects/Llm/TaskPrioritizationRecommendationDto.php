<?php

namespace App\DataTransferObjects\Llm;

final readonly class TaskPrioritizationRecommendationDto
{
    /**
     * @param  array<int, array{rank:int,title:string,end_datetime?:string|null}>  $rankedTasks
     */
    public function __construct(
        public array $rankedTasks,
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

        $ranked = $structured['ranked_tasks'] ?? null;
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

            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                return null;
            }

            $normalized[] = [
                'rank' => $rank,
                'title' => $title,
                'end_datetime' => $item['end_datetime'] ?? null,
            ];
        }

        if ($normalized === []) {
            return null;
        }

        return new self(
            rankedTasks: $normalized,
            reasoning: $reasoning,
        );
    }
}
