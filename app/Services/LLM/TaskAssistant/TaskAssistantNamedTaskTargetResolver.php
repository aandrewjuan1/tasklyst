<?php

namespace App\Services\LLM\TaskAssistant;

use App\Models\Task;
use App\Models\TaskAssistantThread;

final class TaskAssistantNamedTaskTargetResolver
{
    /**
     * @return array{
     *   status: 'none'|'single'|'ambiguous',
     *   matched_phrase: string|null,
     *   target_entity: array{entity_type: string, entity_id: int, title: string}|null,
     *   candidates: list<array{entity_type: string, entity_id: int, title: string}>,
     *   clarification_question: string|null
     * }
     */
    public function resolve(TaskAssistantThread $thread, string $content): array
    {
        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $content) ?? $content));
        if ($normalized === '' || ! $this->containsScheduleCue($normalized)) {
            return $this->none();
        }

        $phraseCandidates = $this->extractTaskPhraseCandidates($content, $normalized);
        foreach ($phraseCandidates as $phrase) {
            $targets = $this->matchTargetsForPhrase($thread, $phrase);
            if ($targets === []) {
                continue;
            }

            if (count($targets) === 1) {
                return [
                    'status' => 'single',
                    'matched_phrase' => $phrase,
                    'target_entity' => $targets[0],
                    'candidates' => $targets,
                    'clarification_question' => null,
                ];
            }

            $targets = $this->sortTargetsForClarification($targets, $phrase);
            $titles = array_map(
                static fn (array $target): string => $target['title'],
                array_slice($targets, 0, 4)
            );

            return [
                'status' => 'ambiguous',
                'matched_phrase' => $phrase,
                'target_entity' => null,
                'candidates' => $targets,
                'clarification_question' => $this->buildClarificationQuestion($titles),
            ];
        }

        return $this->none();
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
                $tail = $this->stripSchedulingWindowTail($tail);
                $phrase = $this->normalizeTaskPhrase($tail);
                if ($this->isViableTaskPhrase($phrase)) {
                    $candidates[] = $phrase;
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

        return array_values(array_filter($candidates, fn (string $phrase): bool => mb_strlen($phrase) >= 3));
    }

    private function normalizeTaskPhrase(string $raw): string
    {
        $phrase = mb_strtolower(trim($raw));
        $phrase = trim((string) preg_replace('/^[\s\p{P}]+|[\s\p{P}]+$/u', '', $phrase));
        $phrase = preg_replace('/\s+/u', ' ', $phrase) ?? $phrase;
        $phrase = preg_replace('/^(my|the)\s+/u', '', $phrase) ?? $phrase;
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
     * @param  list<string>  $titles
     */
    private function buildClarificationQuestion(array $titles): string
    {
        $titles = array_values(array_filter(array_map(
            static fn (string $title): string => trim($title),
            $titles
        ), static fn (string $title): bool => $title !== ''));
        $titles = array_slice($titles, 0, 3);

        if ($titles === []) {
            return 'I found multiple tasks with similar names. Which one should I schedule?';
        }

        return 'I found multiple matching tasks: '.implode(', ', $titles).'. Which one should I schedule?';
    }

    /**
     * @return array{
     *   status: 'none',
     *   matched_phrase: null,
     *   target_entity: null,
     *   candidates: list<array{entity_type: string, entity_id: int, title: string}>,
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
            'clarification_question' => null,
        ];
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
