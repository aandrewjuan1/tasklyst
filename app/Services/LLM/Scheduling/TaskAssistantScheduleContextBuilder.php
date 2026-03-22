<?php

namespace App\Services\LLM\Scheduling;

use App\Services\LLM\Prioritization\TaskAssistantTaskChoiceConstraintsExtractor;
use Illuminate\Support\Facades\Log;

/**
 * Deterministic scheduling context extracted from the user message (no LLM).
 * Delegates shared signals to {@see TaskAssistantTaskChoiceConstraintsExtractor} so schedule matches prioritize/browse.
 * Output shape matches {@see TaskAssistantStructuredFlowGenerator::applyContextToSnapshot} expectations.
 */
final class TaskAssistantScheduleContextBuilder
{
    public function __construct(
        private readonly TaskAssistantTaskChoiceConstraintsExtractor $constraintsExtractor,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{
     *   intent_type: string,
     *   priority_filters: list<string>,
     *   task_keywords: list<string>,
     *   time_constraint: string,
     *   comparison_focus: string|null,
     *   recurring_requested: bool
     * }
     */
    public function build(string $userMessage, array $snapshot): array
    {
        $extracted = $this->constraintsExtractor->extract($userMessage);
        $normalized = $this->buildScheduleContext($userMessage, $extracted);

        Log::info('task-assistant.schedule_context_deterministic', [
            'user_message_length' => mb_strlen($userMessage),
            'analysis' => $normalized,
            'snapshot_task_count' => is_array($snapshot['tasks'] ?? null) ? count($snapshot['tasks']) : 0,
        ]);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $extracted
     * @return array{
     *   intent_type: string,
     *   priority_filters: list<string>,
     *   task_keywords: list<string>,
     *   time_constraint: string,
     *   comparison_focus: string|null,
     *   recurring_requested: bool
     * }
     */
    private function buildScheduleContext(string $userMessage, array $extracted): array
    {
        $messageLower = mb_strtolower($userMessage);

        $intentType = 'general';
        $comparisonFocus = null;

        if (str_contains($messageLower, 'between') && str_contains($messageLower, 'and')) {
            $intentType = 'specific_comparison';
            $comparisonFocus = 'user_specified_choice';
        } elseif (in_array('urgent', $extracted['priority_filters'] ?? [], true)) {
            $intentType = 'urgent_focus';
        }

        $timeConstraintRaw = $extracted['time_constraint'] ?? null;
        $timeConstraint = 'none';
        if ($timeConstraintRaw === 'today') {
            $timeConstraint = 'today';
        } elseif ($timeConstraintRaw === 'this_week') {
            $timeConstraint = 'this_week';
        }

        return [
            'intent_type' => $intentType,
            'priority_filters' => array_values(array_unique($extracted['priority_filters'] ?? [])),
            'task_keywords' => array_values(array_unique($extracted['task_keywords'] ?? [])),
            'time_constraint' => $timeConstraint,
            'comparison_focus' => $comparisonFocus,
            'recurring_requested' => (bool) ($extracted['recurring_requested'] ?? false),
        ];
    }
}
