<?php

namespace App\Services\LLM\Scheduling;

use Illuminate\Support\Facades\Log;

/**
 * Deterministic scheduling context extracted from the user message (no LLM).
 * Output shape matches {@see TaskAssistantStructuredFlowGenerator::applyContextToSnapshot} expectations.
 */
final class TaskAssistantScheduleContextBuilder
{
    private const ALLOWED_PRIORITIES = ['urgent', 'high', 'medium', 'low'];

    private const ALLOWED_TIME_CONSTRAINTS = ['today', 'this_week', 'none'];

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{
     *   intent_type: string,
     *   priority_filters: list<string>,
     *   task_keywords: list<string>,
     *   time_constraint: string,
     *   comparison_focus: string|null
     * }
     */
    public function build(string $userMessage, array $snapshot): array
    {
        $analysis = $this->analyzeFromMessage(mb_strtolower($userMessage));
        $normalized = $this->normalizeAnalysis($analysis);

        Log::info('task-assistant.schedule_context_deterministic', [
            'user_message_length' => mb_strlen($userMessage),
            'analysis' => $normalized,
            'snapshot_task_count' => is_array($snapshot['tasks'] ?? null) ? count($snapshot['tasks']) : 0,
        ]);

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function analyzeFromMessage(string $message): array
    {
        $analysis = [
            'intent_type' => 'general',
            'priority_filters' => [],
            'task_keywords' => [],
            'time_constraint' => null,
            'comparison_focus' => null,
        ];

        foreach (self::ALLOWED_PRIORITIES as $priority) {
            if (preg_match('/\b'.preg_quote($priority, '/').'\b/', $message) === 1) {
                $analysis['priority_filters'][] = $priority;
            }
        }

        if (in_array('urgent', $analysis['priority_filters'], true)) {
            $analysis['intent_type'] = 'urgent_focus';
        }

        $domainHints = ['coding', 'math', 'reading', 'study', 'science', 'writing', 'physics', 'chemistry'];
        foreach ($domainHints as $hint) {
            if (str_contains($message, $hint)) {
                $analysis['task_keywords'][] = $hint;
            }
        }

        if (str_contains($message, 'this week') || str_contains($message, 'this_week')) {
            $analysis['time_constraint'] = 'this_week';
        } elseif (str_contains($message, 'today')) {
            $analysis['time_constraint'] = 'today';
        }

        if (str_contains($message, 'between') && str_contains($message, 'and')) {
            $analysis['intent_type'] = 'specific_comparison';
            $analysis['comparison_focus'] = 'user_specified_choice';
        }

        return $analysis;
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array{
     *   intent_type: string,
     *   priority_filters: list<string>,
     *   task_keywords: list<string>,
     *   time_constraint: string,
     *   comparison_focus: string|null
     * }
     */
    private function normalizeAnalysis(array $analysis): array
    {
        $intentType = is_string($analysis['intent_type'] ?? null)
            ? trim((string) $analysis['intent_type'])
            : 'general';

        $rawPriorities = $analysis['priority_filters'] ?? [];
        $priorities = [];
        if (is_array($rawPriorities)) {
            foreach ($rawPriorities as $priority) {
                $candidate = strtolower(trim((string) $priority));
                foreach (self::ALLOWED_PRIORITIES as $allowed) {
                    if (preg_match('/\b'.preg_quote($allowed, '/').'\b/i', $candidate) === 1) {
                        $priorities[] = $allowed;
                    }
                }
            }
        }
        $priorities = array_values(array_unique($priorities));

        $rawKeywords = $analysis['task_keywords'] ?? [];
        $keywords = [];
        $genericKeywords = ['schedule', 'day', 'today', 'tasks', 'task', 'plan'];
        if (is_array($rawKeywords)) {
            foreach ($rawKeywords as $keyword) {
                $candidate = strtolower(trim((string) $keyword));
                if ($candidate === '' || in_array($candidate, $genericKeywords, true)) {
                    continue;
                }
                $keywords[] = $candidate;
            }
        }
        $keywords = array_values(array_unique($keywords));

        $timeConstraintRaw = strtolower(trim((string) ($analysis['time_constraint'] ?? 'none')));
        $timeConstraint = 'none';
        if (str_contains($timeConstraintRaw, 'today')) {
            $timeConstraint = 'today';
        } elseif (str_contains($timeConstraintRaw, 'week')) {
            $timeConstraint = 'this_week';
        }
        if (! in_array($timeConstraint, self::ALLOWED_TIME_CONSTRAINTS, true)) {
            $timeConstraint = 'none';
        }

        $comparisonFocus = is_string($analysis['comparison_focus'] ?? null)
            ? trim((string) $analysis['comparison_focus'])
            : null;

        return [
            'intent_type' => $intentType === '' ? 'general' : $intentType,
            'priority_filters' => $priorities,
            'task_keywords' => $keywords,
            'time_constraint' => $timeConstraint,
            'comparison_focus' => $comparisonFocus !== '' ? $comparisonFocus : null,
        ];
    }
}
