<?php

namespace App\Services\LLM\Scheduling;

use Carbon\CarbonImmutable;

/**
 * Chooses between deterministic mutation ops vs full spill re-placement for schedule refinement.
 */
final class ScheduleRefinementPlacementRouter
{
    public function __construct(
        private readonly SchedulingIntentInterpreter $intentInterpreter,
        private readonly ScheduleEditTemporalParser $temporalParser,
        private readonly ScheduleEditUnderstandingPipeline $understandingPipeline,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     */
    public function shouldUseSpillForRefinement(
        string $userMessage,
        array $proposals,
        ?int $resolvedTargetIndex,
        string $timezone,
    ): bool {
        if ($resolvedTargetIndex === null) {
            return false;
        }

        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $userMessage) ?? $userMessage));
        if ($normalized === '') {
            return false;
        }

        if ($this->temporalParser->parseLocalTime($normalized) !== null) {
            return false;
        }

        if (preg_match('/\b(\d+)\s*(min|mins|minute|minutes)\b[^.]*\b(later|after|forward|earlier|before|back)\b/u', $normalized) === 1) {
            return false;
        }

        if (preg_match('/\b(make|set)\b[^.]*\b(\d+)\s*(min|mins|minute|minutes)\b/u', $normalized) === 1) {
            return false;
        }

        if ($this->understandingPipeline->wouldReorder($normalized, $proposals)) {
            return false;
        }

        return $this->hasVagueTemporalIntent($userMessage, $timezone);
    }

    private function hasVagueTemporalIntent(string $userMessage, string $timezone): bool
    {
        $tz = $timezone !== '' ? $timezone : (string) config('app.timezone', 'Asia/Manila');
        $now = CarbonImmutable::now($tz);
        $intent = $this->intentInterpreter->interpret($userMessage, $tz, $now);
        $tw = $intent['time_window'] ?? [];
        $default = ['start' => '08:00', 'end' => '22:00'];
        $windowDiffers = ! is_array($tw)
            || ! isset($tw['start'], $tw['end'])
            || (string) $tw['start'] !== $default['start']
            || (string) $tw['end'] !== $default['end'];

        $flags = is_array($intent['intent_flags'] ?? null) ? $intent['intent_flags'] : [];
        $namedTime = (bool) ($flags['has_morning'] ?? false)
            || (bool) ($flags['has_afternoon'] ?? false)
            || (bool) ($flags['has_evening'] ?? false)
            || (bool) ($flags['has_later'] ?? false);

        $lower = mb_strtolower($userMessage);
        $dateCue = preg_match(
            '/\b(tomorrow|today|tonight|weekend|monday|tuesday|wednesday|thursday|friday|saturday|sunday|next week|this week)\b/u',
            $lower
        ) === 1;

        return $windowDiffers || $namedTime || $dateCue;
    }
}
