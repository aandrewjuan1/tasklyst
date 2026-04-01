<?php

namespace App\Services\LLM\Scheduling;

final class ScheduleEditUnderstandingPipeline
{
    public function __construct(
        private readonly ScheduleEditLexicon $lexicon,
        private readonly ScheduleEditTargetResolver $targetResolver,
        private readonly ScheduleEditTemporalParser $temporalParser,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     * @return array{
     *   operations: list<array<string, mixed>>,
     *   clarification_required: bool,
     *   clarification_message: string|null,
     *   reasons: list<string>
     * }
     */
    public function resolve(string $userMessage, array $proposals, string $timezone): array
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $userMessage) ?? $userMessage));
        if ($normalized === '') {
            return $this->clarify('Please describe the schedule change you want to make.');
        }

        $target = $this->targetResolver->resolvePrimaryTarget($normalized, $proposals);
        $wantsReorder = $this->lexicon->looksLikeReorder($normalized);

        if (($target['ambiguous'] ?? true) && ! $wantsReorder) {
            return $this->clarify((string) ($target['reason'] ?? 'Please specify which item to edit.'));
        }

        if (($target['confidence'] ?? 'low') === 'low' && ! $wantsReorder) {
            $candidates = is_array($target['candidate_titles'] ?? null) ? $target['candidate_titles'] : [];
            $candidateText = $candidates !== [] ? ' Possible matches: '.implode(', ', $candidates).'.' : '';

            return $this->clarify('I am not fully sure which schedule item you mean.'.$candidateText.' Please mention first/second/last or part of the title.');
        }

        $ops = [];
        $targetIndex = $target['index'];
        $targetUuid = $target['proposal_uuid'] ?? null;
        if (preg_match('/\b(\d+)\s*(min|mins|minute|minutes)\b[^.]*\b(later|after|forward)\b/u', $normalized, $m) === 1) {
            $ops[] = ['op' => 'shift_minutes', 'proposal_index' => $targetIndex, 'proposal_uuid' => $targetUuid, 'delta_minutes' => (int) $m[1]];
        } elseif (preg_match('/\b(\d+)\s*(min|mins|minute|minutes)\b[^.]*\b(earlier|before|back)\b/u', $normalized, $m) === 1) {
            $ops[] = ['op' => 'shift_minutes', 'proposal_index' => $targetIndex, 'proposal_uuid' => $targetUuid, 'delta_minutes' => -1 * (int) $m[1]];
        }

        if (preg_match('/\b(make|set)\b[^.]*\b(\d+)\s*(min|mins|minute|minutes)\b/u', $normalized, $m) === 1) {
            $ops[] = ['op' => 'set_duration_minutes', 'proposal_index' => $targetIndex, 'proposal_uuid' => $targetUuid, 'duration_minutes' => (int) $m[2]];
        }

        $time = $this->temporalParser->parseLocalTime($normalized);
        if ($time !== null) {
            $ops[] = ['op' => 'set_local_time_hhmm', 'proposal_index' => $targetIndex, 'proposal_uuid' => $targetUuid, 'local_time_hhmm' => $time];
        }

        $date = $this->temporalParser->parseLocalDateYmd($normalized, $timezone);
        if ($date !== null) {
            $ops[] = ['op' => 'set_local_date_ymd', 'proposal_index' => $targetIndex, 'proposal_uuid' => $targetUuid, 'local_date_ymd' => $date];
        }

        $reorder = $this->resolveReorderOperation($normalized, $proposals);
        if ($reorder !== null) {
            $ops[] = $reorder;
        }

        if ($ops === []) {
            return $this->clarify('I could not map that to a concrete edit yet. Tell me item + change, like "move second to 8 pm".');
        }

        foreach ($ops as $op) {
            if (in_array((string) ($op['op'] ?? ''), ['reorder_before', 'reorder_after', 'move_to_position'], true)) {
                continue;
            }
            if (! isset($op['proposal_index']) || ! is_int($op['proposal_index'])) {
                return $this->clarify((string) ($target['reason'] ?? 'Please specify which listed item to edit.'));
            }
        }

        return [
            'operations' => $ops,
            'clarification_required' => false,
            'clarification_message' => null,
            'reasons' => [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     * @return array<string, mixed>|null
     */
    private function resolveReorderOperation(string $normalized, array $proposals): ?array
    {
        $count = count($proposals);
        if ($count < 2 || ! $this->lexicon->looksLikeReorder($normalized)) {
            return null;
        }

        $targetPattern = '(?:the\s+)?(?:first|1st|second|2nd|third|3rd|last|item\s*#?\d+)(?:\s+one)?';

        if (preg_match('/\bmove\s+('.$targetPattern.')\b[^.]*\bto\s+first\b/u', $normalized, $m) === 1) {
            $source = $this->targetResolver->resolvePrimaryTarget((string) $m[1], $proposals);
            if (($source['index'] ?? null) !== null) {
                return ['op' => 'move_to_position', 'proposal_index' => (int) $source['index'], 'proposal_uuid' => $source['proposal_uuid'] ?? null, 'target_index' => 0];
            }
        }

        if (preg_match('/\bmove\s+('.$targetPattern.')\b[^.]*\bto\s+last\b/u', $normalized, $m) === 1) {
            $source = $this->targetResolver->resolvePrimaryTarget((string) $m[1], $proposals);
            if (($source['index'] ?? null) !== null) {
                return ['op' => 'move_to_position', 'proposal_index' => (int) $source['index'], 'proposal_uuid' => $source['proposal_uuid'] ?? null, 'target_index' => $count - 1];
            }
        }

        if (preg_match('/\bmove\s+('.$targetPattern.')\b[^.]*\bbefore\b[^.]*\b('.$targetPattern.')\b/u', $normalized, $m) === 1) {
            $source = $this->targetResolver->resolvePrimaryTarget((string) $m[1], $proposals);
            $anchor = $this->targetResolver->resolvePrimaryTarget((string) $m[2], $proposals);
            if (($source['index'] ?? null) !== null && ($anchor['index'] ?? null) !== null) {
                return [
                    'op' => 'reorder_before',
                    'proposal_index' => (int) $source['index'],
                    'proposal_uuid' => $source['proposal_uuid'] ?? null,
                    'anchor_index' => (int) $anchor['index'],
                    'anchor_proposal_uuid' => $anchor['proposal_uuid'] ?? null,
                ];
            }
        }

        if (preg_match('/\bmove\s+('.$targetPattern.')\b[^.]*\bafter\b[^.]*\b('.$targetPattern.')\b/u', $normalized, $m) === 1) {
            $source = $this->targetResolver->resolvePrimaryTarget((string) $m[1], $proposals);
            $anchor = $this->targetResolver->resolvePrimaryTarget((string) $m[2], $proposals);
            if (($source['index'] ?? null) !== null && ($anchor['index'] ?? null) !== null) {
                return [
                    'op' => 'reorder_after',
                    'proposal_index' => (int) $source['index'],
                    'proposal_uuid' => $source['proposal_uuid'] ?? null,
                    'anchor_index' => (int) $anchor['index'],
                    'anchor_proposal_uuid' => $anchor['proposal_uuid'] ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * @return array{operations: list<array<string, mixed>>, clarification_required: bool, clarification_message: string, reasons: list<string>}
     */
    private function clarify(string $message): array
    {
        return [
            'operations' => [],
            'clarification_required' => true,
            'clarification_message' => $message,
            'reasons' => [$message],
        ];
    }
}
