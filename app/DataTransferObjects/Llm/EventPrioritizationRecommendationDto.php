<?php

namespace App\DataTransferObjects\Llm;

final readonly class EventPrioritizationRecommendationDto
{
    /**
     * @param  array<int, array{rank:int,title:string,start_datetime?:string|null,end_datetime?:string|null}>  $rankedEvents
     */
    public function __construct(
        public array $rankedEvents,
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

        $ranked = $structured['ranked_events'] ?? null;
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
                'start_datetime' => $item['start_datetime'] ?? null,
                'end_datetime' => $item['end_datetime'] ?? null,
            ];
        }

        if ($normalized === []) {
            return null;
        }

        return new self(
            rankedEvents: $normalized,
            reasoning: $reasoning,
        );
    }
}
