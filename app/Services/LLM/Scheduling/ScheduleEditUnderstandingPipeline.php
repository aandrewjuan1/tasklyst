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
     */
    public function wouldReorder(string $normalizedMessage, array $proposals): bool
    {
        return $this->resolveReorderOperation($normalizedMessage, $proposals) !== null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     * @param  list<string>  $lastReferencedProposalUuids
     * @return array{
     *   operations: list<array<string, mixed>>,
     *   clarification_required: bool,
     *   clarification_message: string|null,
     *   reasons: list<string>,
     *   clarification_context?: array<string, mixed>
     * }
     */
    public function resolve(
        string $userMessage,
        array $proposals,
        string $timezone,
        array $lastReferencedProposalUuids = [],
    ): array {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $userMessage) ?? $userMessage));
        if ($normalized === '') {
            return $this->clarify('Please describe the schedule change you want to make.');
        }

        return $this->resolveNormalizedClause($normalized, $proposals, $timezone, $lastReferencedProposalUuids);
    }

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     * @param  list<string>  $lastReferencedProposalUuids
     * @return array{
     *   operations: list<array<string, mixed>>,
     *   clarification_required: bool,
     *   clarification_message: string|null,
     *   reasons: list<string>,
     *   clarification_context?: array<string, mixed>
     * }
     */
    public function resolveClause(
        string $normalizedClause,
        array $proposals,
        string $timezone,
        array $lastReferencedProposalUuids = [],
    ): array {
        return $this->resolveNormalizedClause($normalizedClause, $proposals, $timezone, $lastReferencedProposalUuids);
    }

    /**
     * @param  array<int, array<string, mixed>>  $proposals
     * @param  list<string>  $lastReferencedProposalUuids
     * @return array{
     *   operations: list<array<string, mixed>>,
     *   clarification_required: bool,
     *   clarification_message: string|null,
     *   reasons: list<string>,
     *   clarification_context?: array<string, mixed>
     * }
     */
    public function resolveNormalizedClause(
        string $normalized,
        array $proposals,
        string $timezone,
        array $lastReferencedProposalUuids = [],
    ): array {
        $target = $this->targetResolver->resolvePrimaryTarget(
            $normalized,
            $proposals,
            $lastReferencedProposalUuids,
        );
        $wantsReorder = $this->lexicon->looksLikeReorder($normalized);
        $parsedTime = $this->temporalParser->parseLocalTime($normalized);
        $parsedPartOfDay = $parsedTime === null ? $this->temporalParser->parsePartOfDayAnchorHhmm($normalized) : null;
        $parsedDate = $this->temporalParser->parseLocalDateYmd($normalized, $timezone);
        $clarificationContext = [
            'normalized_clause' => $normalized,
            'target_summary' => $this->summarizeTarget($target),
            'candidate_titles' => is_array($target['candidate_titles'] ?? null) ? $target['candidate_titles'] : [],
            'parsed_time_hhmm' => $parsedTime,
            'parsed_part_of_day_hhmm' => $parsedPartOfDay,
            'parsed_date_ymd' => $parsedDate,
            'wants_reorder' => $wantsReorder,
        ];

        if (($target['ambiguous'] ?? true) && ! $wantsReorder) {
            return $this->clarify(
                (string) ($target['reason'] ?? 'Please specify which item to edit.'),
                ['target_ambiguous'],
                $clarificationContext
            );
        }

        if (($target['confidence'] ?? 'low') === 'low' && ! $wantsReorder) {
            $candidates = is_array($target['candidate_titles'] ?? null) ? $target['candidate_titles'] : [];
            $candidateText = $candidates !== [] ? ' Possible matches: '.implode(', ', $candidates).'.' : '';

            return $this->clarify(
                'I am not fully sure which schedule item you mean.'.$candidateText.' Please mention first/second/last, #number, or part of the title.',
                ['target_low_confidence'],
                $clarificationContext
            );
        }

        $ops = [];
        $targetIndex = $target['index'];
        $targetUuid = $target['proposal_uuid'] ?? null;
        if (preg_match('/\b(\d+)\s*(min|mins|minute|minutes)\b[^.]*\b(later|after|forward)\b/u', $normalized, $m) === 1) {
            $ops[] = ['op' => 'shift_minutes', 'proposal_index' => $targetIndex, 'proposal_uuid' => $targetUuid, 'delta_minutes' => (int) $m[1]];
        } elseif (preg_match('/\b(\d+)\s*(min|mins|minute|minutes)\b[^.]*\b(earlier|before|back)\b/u', $normalized, $m) === 1) {
            $ops[] = ['op' => 'shift_minutes', 'proposal_index' => $targetIndex, 'proposal_uuid' => $targetUuid, 'delta_minutes' => -1 * (int) $m[1]];
        }

        if ($ops === [] && $targetIndex !== null && is_int($targetIndex) && $this->looksLikeBareLaterOrEarlierRefinement($normalized)) {
            $deltaMinutes = str_contains($normalized, 'earlier') ? -30 : 30;
            $ops[] = [
                'op' => 'shift_minutes',
                'proposal_index' => $targetIndex,
                'proposal_uuid' => $targetUuid,
                'delta_minutes' => $deltaMinutes,
            ];
        }

        if (preg_match('/\b(make|set)\b[^.]*\b(\d+)\s*(min|mins|minute|minutes)\b/u', $normalized, $m) === 1) {
            $ops[] = ['op' => 'set_duration_minutes', 'proposal_index' => $targetIndex, 'proposal_uuid' => $targetUuid, 'duration_minutes' => (int) $m[2]];
        }

        $time = $parsedTime;
        if ($time !== null) {
            $ops[] = ['op' => 'set_local_time_hhmm', 'proposal_index' => $targetIndex, 'proposal_uuid' => $targetUuid, 'local_time_hhmm' => $time];
        } else {
            $partOfDay = $parsedPartOfDay;
            if ($partOfDay !== null) {
                $ops[] = ['op' => 'set_local_time_hhmm', 'proposal_index' => $targetIndex, 'proposal_uuid' => $targetUuid, 'local_time_hhmm' => $partOfDay];
            }
        }

        $date = $parsedDate;
        if ($date !== null) {
            $ops[] = ['op' => 'set_local_date_ymd', 'proposal_index' => $targetIndex, 'proposal_uuid' => $targetUuid, 'local_date_ymd' => $date];
        }

        $reorder = $this->resolveReorderOperation($normalized, $proposals);
        if ($reorder !== null) {
            $ops[] = $reorder;
        }

        if ($ops === []) {
            return $this->clarify(
                'I could not map that to a concrete edit yet. Tell me item + change, like "move second to 8 pm".',
                ['no_concrete_operation'],
                $clarificationContext
            );
        }

        foreach ($ops as $op) {
            if (in_array((string) ($op['op'] ?? ''), ['reorder_before', 'reorder_after', 'move_to_position'], true)) {
                continue;
            }
            if (! isset($op['proposal_index']) || ! is_int($op['proposal_index'])) {
                return $this->clarify(
                    (string) ($target['reason'] ?? 'Please specify which listed item to edit.'),
                    [],
                    $clarificationContext
                );
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
     * @param  list<array<string, mixed>>  $operations
     * @param  array<int, array<string, mixed>>  $proposals
     * @return list<array<string, mixed>>
     */
    public function enrichOperationsWithProposalUuids(array $operations, array $proposals): array
    {
        $out = [];
        foreach ($operations as $op) {
            if (! is_array($op)) {
                continue;
            }
            $copy = $op;
            $idx = $copy['proposal_index'] ?? null;
            if (is_int($idx) && $idx >= 0 && $idx < count($proposals)) {
                $row = $proposals[$idx];
                if (is_array($row) && trim((string) ($copy['proposal_uuid'] ?? '')) === '') {
                    $uuid = trim((string) ($row['proposal_uuid'] ?? $row['proposal_id'] ?? ''));
                    if ($uuid !== '') {
                        $copy['proposal_uuid'] = $uuid;
                    }
                }
            }
            $anchorIdx = $copy['anchor_index'] ?? null;
            if (is_int($anchorIdx) && $anchorIdx >= 0 && $anchorIdx < count($proposals)) {
                $row = $proposals[$anchorIdx];
                if (is_array($row) && trim((string) ($copy['anchor_proposal_uuid'] ?? '')) === '') {
                    $uuid = trim((string) ($row['proposal_uuid'] ?? $row['proposal_id'] ?? ''));
                    if ($uuid !== '') {
                        $copy['anchor_proposal_uuid'] = $uuid;
                    }
                }
            }
            $out[] = $copy;
        }

        return $out;
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

        $targetPattern = $this->lexicon->scheduleDraftReorderTargetPattern();

        if (preg_match('/\b(?:move|drag|slide|bring|pull|drop)\s+('.$targetPattern.')\b[^.]*\bto\s+first\b/u', $normalized, $m) === 1) {
            $source = $this->targetResolver->resolvePrimaryTarget((string) $m[1], $proposals);
            if (($source['index'] ?? null) !== null) {
                return ['op' => 'move_to_position', 'proposal_index' => (int) $source['index'], 'proposal_uuid' => $source['proposal_uuid'] ?? null, 'target_index' => 0];
            }
        }

        if (preg_match('/\b(?:move|drag|slide|bring|pull|drop)\s+('.$targetPattern.')\b[^.]*\bto\s+last\b/u', $normalized, $m) === 1) {
            $source = $this->targetResolver->resolvePrimaryTarget((string) $m[1], $proposals);
            if (($source['index'] ?? null) !== null) {
                return ['op' => 'move_to_position', 'proposal_index' => (int) $source['index'], 'proposal_uuid' => $source['proposal_uuid'] ?? null, 'target_index' => $count - 1];
            }
        }

        if (preg_match('/\b(?:move|drag|slide|bring|pull|drop)\s+('.$targetPattern.')\b[^.]*\bbefore\b[^.]*\b('.$targetPattern.')\b/u', $normalized, $m) === 1) {
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

        if (preg_match('/\b(?:move|drag|slide|bring|pull|drop)\s+('.$targetPattern.')\b[^.]*\bafter\b[^.]*\b('.$targetPattern.')\b/u', $normalized, $m) === 1) {
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
     * @param  array<string, mixed>  $context
     * @return array{operations: list<array<string, mixed>>, clarification_required: bool, clarification_message: string, reasons: list<string>, clarification_context: array<string, mixed>}
     */
    private function clarify(string $message, array $reasons = [], array $context = []): array
    {
        return [
            'operations' => [],
            'clarification_required' => true,
            'clarification_message' => $message,
            'reasons' => $reasons !== [] ? $reasons : [$message],
            'clarification_context' => $context,
        ];
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function summarizeTarget(array $target): string
    {
        $index = $target['index'] ?? null;
        if (is_int($index) && $index >= 0) {
            return 'item #'.($index + 1);
        }

        $matched = trim((string) ($target['matched_title'] ?? ''));
        if ($matched !== '') {
            return $matched;
        }

        return 'unresolved target';
    }

    private function looksLikeBareLaterOrEarlierRefinement(string $normalized): bool
    {
        $hasDirectionCue = preg_match('/\b(later|earlier)\b/u', $normalized) === 1;
        if (! $hasDirectionCue) {
            return false;
        }

        $hasExplicitClockTime = preg_match('/\b(at\s+)?\d{1,2}(:\d{2})?\s*(am|pm)\b/u', $normalized) === 1;
        $hasDaypartCue = preg_match('/\b(morning|afternoon|evening|night|tonight)\b/u', $normalized) === 1;
        $hasExplicitMinutesShift = preg_match('/\b\d+\s*(min|mins|minute|minutes)\b/u', $normalized) === 1;
        $hasExplicitDateCue = preg_match('/\b(today|tomorrow|tmrw|next week)\b/u', $normalized) === 1;

        return ! $hasExplicitClockTime
            && ! $hasDaypartCue
            && ! $hasExplicitMinutesShift
            && ! $hasExplicitDateCue;
    }
}
