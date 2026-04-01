<?php

namespace App\Services\LLM\Scheduling;

final class ScheduleRefinementIntentResolver
{
    public function __construct(
        private readonly ScheduleEditUnderstandingPipeline $pipeline,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     * @return list<array<string, mixed>>
     */
    public function resolve(string $userMessage, array $proposals, string $userTimezone): array
    {
        $resolved = $this->resolveDetailed($userMessage, $proposals, $userTimezone);

        return $resolved['operations'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     * @return array{
     *   operations: list<array<string, mixed>>,
     *   clarification_required: bool,
     *   clarification_message: string|null,
     *   reasons: list<string>
     * }
     */
    public function resolveDetailed(string $userMessage, array $proposals, string $userTimezone): array
    {
        return $this->pipeline->resolve($userMessage, $proposals, $userTimezone);
    }
}
