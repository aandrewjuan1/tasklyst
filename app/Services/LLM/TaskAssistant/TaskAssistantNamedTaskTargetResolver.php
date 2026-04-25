<?php

namespace App\Services\LLM\TaskAssistant;

use App\Models\Task;
use App\Models\TaskAssistantThread;

final class TaskAssistantNamedTaskTargetResolver
{
    /**
     * @return array{
     *   status: 'none'|'single'|'multi'|'partial'|'ambiguous',
     *   matched_phrase: string|null,
     *   target_entity: array{entity_type: string, entity_id: int, title: string}|null,
     *   candidates: list<array{entity_type: string, entity_id: int, title: string}>,
     *   target_entities: list<array{entity_type: string, entity_id: int, title: string}>,
     *   ambiguous_groups: list<array{
     *     phrase: string,
     *     candidates: list<array{entity_type: string, entity_id: int, title: string}>
     *   }>,
     *   unresolved_phrases: list<string>,
     *   clarification_question: string|null
     * }
     */
    public function resolve(TaskAssistantThread $thread, string $content): array
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $content) ?? $content));
        if ($normalized === '' || ! $this->containsScheduleCue($normalized)) {
            return $this->none();
        }

        $phraseCandidates = array_slice($this->extractTaskPhraseCandidates($content, $normalized), 0, 3);
        if ($phraseCandidates === []) {
            return $this->none();
        }

        $resolvedTargets = [];
        $ambiguousGroups = [];
        $unresolvedPhrases = [];

        foreach ($phraseCandidates as $phrase) {
            $targets = $this->matchTargetsForPhrase($thread, $phrase);
            if ($targets === []) {
                $unresolvedPhrases[] = $phrase;

                continue;
            }

            if (count($targets) === 1) {
                $target = $targets[0];
                $key = $target['entity_type'].'#'.$target['entity_id'];
                $resolvedTargets[$key] = $target;

                continue;
            }

            $ambiguousGroups[] = [
                'phrase' => $phrase,
                'candidates' => $this->sortTargetsForClarification($targets, $phrase),
            ];
        }

        $resolvedTargets = array_values($resolvedTargets);
        if ($resolvedTargets === [] && $ambiguousGroups === []) {
            return $this->none();
        }

        $status = 'none';
        if ($ambiguousGroups !== []) {
            $status = 'ambiguous';
        } elseif (count($resolvedTargets) === 1 && $unresolvedPhrases === []) {
            $status = 'single';
        } elseif (count($resolvedTargets) > 1 && $unresolvedPhrases === []) {
            $status = 'multi';
        } elseif ($resolvedTargets !== []) {
            $status = 'partial';
        }

        /** @var array{entity_type: string, entity_id: int, title: string}|null $firstResolved */
        $firstResolved = $resolvedTargets[0] ?? null;
        $firstAmbiguousCandidates = is_array($ambiguousGroups[0]['candidates'] ?? null)
            ? $ambiguousGroups[0]['candidates']
            : [];

        return [
            'status' => $status,
            'matched_phrase' => $phraseCandidates[0] ?? null,
            'target_entity' => $firstResolved,
            'candidates' => $firstAmbiguousCandidates,
            'target_entities' => array_slice($resolvedTargets, 0, 3),
            'ambiguous_groups' => $ambiguousGroups,
            'unresolved_phrases' => $unresolvedPhrases,
            'clarification_question' => $ambiguousGroups !== []
                ? $this->buildConsolidatedClarificationQuestion($ambiguousGroups)
                : null,
        ];
    }

    private function containsScheduleCue(string $normalized): bool
    {
        return preg_match('/\b(schedule|plan|time[\s-]?block|calendar|slot|put|reschedule)\b/u', $normalized) === 1;
    }

    /**
     * @return list<string>
     */
    private function extractTaskPhraseCandidates(string $original, string $normalized): array
    {
        $candidates = [];

        if (preg_match_all('/["\']([^"\']{3,80})["\']/u', $original, $quotedMatches) === 1) {
            foreach ($quotedMatches[1] as $raw) {
                $phrase = $this->normalizeTaskPhrase((string) $raw);
                if ($this->isViableTaskPhrase($phrase)) {
                    $candidates[] = $phrase;
                }
            }
        }

        if (preg_match('/\b(?:schedule|plan|put|slot)\b\s+(?:my|the)?\s*(.+)$/iu', $normalized, $tailMatch) === 1) {
            $tail = trim((string) ($tailMatch[1] ?? ''));
            if ($tail !== '') {
                foreach ($this->splitTaskPhrases($tail) as $segment) {
                    $segment = $this->stripSchedulingWindowTail($segment);
                    $phrase = $this->normalizeTaskPhrase($segment);
                    if ($this->isViableTaskPhrase($phrase)) {
                        $candidates[] = $phrase;
                    }
                }
            }
        }

        if (preg_match('/\b(?:schedule|plan|reschedule)\b\s+(?:my|the)?\s*task\s+(?:called|named)?\s*(.+)$/iu', $normalized, $taskTailMatch) === 1) {
            $tail = $this->stripSchedulingWindowTail(trim((string) ($taskTailMatch[1] ?? '')));
            $phrase = $this->normalizeTaskPhrase($tail);
            if ($this->isViableTaskPhrase($phrase)) {
                $candidates[] = $phrase;
            }
        }

        $candidates = array_values(array_unique($candidates));

        return array_values(array_slice(array_filter(
            $candidates,
            fn (string $phrase): bool => mb_strlen($phrase) >= 3
        ), 0, 3));
    }

    private function normalizeTaskPhrase(string $raw): string
    {
        $phrase = mb_strtolower(trim($raw));
        $phrase = trim((string) preg_replace('/^[\s\p{P}]+|[\s\p{P}]+$/u', '', $phrase));
        $phrase = preg_replace('/\s+/u', ' ', $phrase) ?? $phrase;
        $phrase = preg_replace('/^(my|the)\s+/u', '', $phrase) ?? $phrase;
        $phrase = preg_replace('/^(and|plus)\s+/u', '', $phrase) ?? $phrase;
        $phrase = preg_replace('/^(task|tasks)\s+(called|named)\s+/u', '', $phrase) ?? $phrase;
        $phrase = preg_replace('/^(task|tasks)\s+/u', '', $phrase) ?? $phrase;
        $phrase = trim((string) preg_replace('/\b(task|tasks)\b$/u', '', $phrase));
        $phrase = trim((string) preg_replace('/\s+/u', ' ', $phrase));

        return $phrase;
    }

    private function isViableTaskPhrase(string $phrase): bool
    {
        if ($phrase === '' || mb_strlen($phrase) < 3) {
            return false;
        }

        return preg_match('/^(tasks?|items?|priorities?|day|today|tomorrow|later)$/u', $phrase) !== 1;
    }

    /**
     * @return list<array{entity_type: string, entity_id: int, title: string}>
     */
    private function matchTargetsForPhrase(TaskAssistantThread $thread, string $phrase): array
    {
        $tasks = Task::query()
            ->where('user_id', $thread->user_id)
            ->where('title', 'like', '%'.$phrase.'%')
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get(['id', 'title']);

        if ($tasks->isEmpty()) {
            return [];
        }

        $normalizedPhrase = mb_strtolower($phrase);
        $exactMatches = $tasks->filter(static function (Task $task) use ($normalizedPhrase): bool {
            return mb_strtolower(trim((string) $task->title)) === $normalizedPhrase;
        })->values();
        if ($exactMatches->count() === 1) {
            $tasks = $exactMatches;
        }

        return $tasks
            ->map(static fn (Task $task): array => [
                'entity_type' => 'task',
                'entity_id' => (int) $task->id,
                'title' => (string) $task->title,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{
     *   phrase: string,
     *   candidates: list<array{entity_type: string, entity_id: int, title: string}>
     * }>  $ambiguousGroups
     */
    private function buildConsolidatedClarificationQuestion(array $ambiguousGroups): string
    {
        $segments = [];
        foreach (array_slice($ambiguousGroups, 0, 3) as $group) {
            $phrase = trim((string) ($group['phrase'] ?? 'that task'));
            $candidates = is_array($group['candidates'] ?? null) ? $group['candidates'] : [];
            $titles = array_values(array_filter(array_map(
                static fn (array $row): string => trim((string) ($row['title'] ?? '')),
                array_slice($candidates, 0, 3)
            ), static fn (string $title): bool => $title !== ''));
            if ($titles === []) {
                continue;
            }
            $segments[] = '"'.$phrase.'": '.implode(', ', $titles);
        }

        if ($segments === []) {
            return 'I found multiple matching tasks. Which one should I schedule?';
        }

        return 'I found multiple matches for these task names. Please reply with the exact title for each: '
            .implode(' | ', $segments);
    }

    /**
     * @return array{
     *   status: 'none',
     *   matched_phrase: null,
     *   target_entity: null,
     *   candidates: list<array{entity_type: string, entity_id: int, title: string}>,
     *   target_entities: list<array{entity_type: string, entity_id: int, title: string}>,
     *   ambiguous_groups: list<array{
     *     phrase: string,
     *     candidates: list<array{entity_type: string, entity_id: int, title: string}>
     *   }>,
     *   unresolved_phrases: list<string>,
     *   clarification_question: null
     * }
     */
    private function none(): array
    {
        return [
            'status' => 'none',
            'matched_phrase' => null,
            'target_entity' => null,
            'candidates' => [],
            'target_entities' => [],
            'ambiguous_groups' => [],
            'unresolved_phrases' => [],
            'clarification_question' => null,
        ];
    }

    /**
     * @return list<string>
     */
    private function splitTaskPhrases(string $tail): array
    {
        $normalized = trim($tail);
        if ($normalized === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:,|\+| and )\s*/iu', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($parts) || $parts === []) {
            return [$normalized];
        }

        return array_values(array_map(
            static fn (string $part): string => trim($part),
            $parts
        ));
    }

    private function stripSchedulingWindowTail(string $tail): string
    {
        return trim((string) preg_replace(
            '/\b(for|on|at|by|in|tomorrow|today|tonight|later|this week|next week|morning|afternoon|evening)\b.*$/iu',
            '',
            $tail
        ));
    }

    /**
     * @param  list<array{entity_type: string, entity_id: int, title: string}>  $targets
     * @return list<array{entity_type: string, entity_id: int, title: string}>
     */
    private function sortTargetsForClarification(array $targets, string $phrase): array
    {
        $normalizedPhrase = mb_strtolower(trim($phrase));

        usort($targets, static function (array $left, array $right) use ($normalizedPhrase): int {
            $leftTitle = mb_strtolower(trim((string) ($left['title'] ?? '')));
            $rightTitle = mb_strtolower(trim((string) ($right['title'] ?? '')));

            $leftExact = $leftTitle === $normalizedPhrase ? 0 : 1;
            $rightExact = $rightTitle === $normalizedPhrase ? 0 : 1;
            if ($leftExact !== $rightExact) {
                return $leftExact <=> $rightExact;
            }

            $leftDistance = abs(mb_strlen($leftTitle) - mb_strlen($normalizedPhrase));
            $rightDistance = abs(mb_strlen($rightTitle) - mb_strlen($normalizedPhrase));
            if ($leftDistance !== $rightDistance) {
                return $leftDistance <=> $rightDistance;
            }

            if ($leftTitle !== $rightTitle) {
                return $leftTitle <=> $rightTitle;
            }

            return ((int) ($left['entity_id'] ?? 0)) <=> ((int) ($right['entity_id'] ?? 0));
        });

        return $targets;
    }
}
