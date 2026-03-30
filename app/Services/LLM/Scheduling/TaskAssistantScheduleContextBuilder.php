<?php

namespace App\Services\LLM\Scheduling;

use App\Services\LLM\Prioritization\TaskAssistantTaskChoiceConstraintsExtractor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Deterministic scheduling context extracted from the user message (no LLM).
 * Delegates shared signals to {@see TaskAssistantTaskChoiceConstraintsExtractor} so scheduling matches prioritize ranking context.
 * {@see TaskAssistantScheduleHorizonResolver} sets which calendar day(s) to search; task-level time filters
 * (e.g. due today) remain on {@see TaskAssistantTaskChoiceConstraintsExtractor} output.
 * Output shape matches {@see TaskAssistantStructuredFlowGenerator::applyContextToSnapshot} expectations.
 */
final class TaskAssistantScheduleContextBuilder
{
    public function __construct(
        private readonly TaskAssistantTaskChoiceConstraintsExtractor $constraintsExtractor,
        private readonly TaskAssistantScheduleHorizonResolver $horizonResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{
     *   intent_type: string,
     *   priority_filters: list<string>,
     *   task_keywords: list<string>,
     *   time_constraint: string,
     *   domain_focus: 'school'|'chores'|null,
     *   entity_type_preference: 'task'|'event'|'project'|'mixed',
     *   comparison_focus: string|null,
     *   recurring_requested: bool,
     *   schedule_horizon: array{
     *     mode: 'single_day'|'range',
     *     start_date: string,
     *     end_date: string,
     *     label: string
     *   }
     * }
     */
    public function build(string $userMessage, array $snapshot): array
    {
        $extracted = $this->constraintsExtractor->extract($userMessage);
        $normalized = $this->buildScheduleContext($userMessage, $extracted);

        $timezone = (string) ($snapshot['timezone'] ?? config('app.timezone', 'UTC'));
        $todayStr = (string) ($snapshot['today'] ?? now($timezone)->format('Y-m-d'));
        $now = CarbonImmutable::parse($todayStr.' 12:00:00', $timezone);
        $normalized['schedule_horizon'] = $this->horizonResolver->resolve($userMessage, $timezone, $now);

        Log::info('task-assistant.schedule_context_deterministic', [
            'layer' => 'structured_generation',
            'thread_id' => app()->bound('task_assistant.thread_id') ? app('task_assistant.thread_id') : null,
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
     *   domain_focus: 'school'|'chores'|null,
     *   entity_type_preference: 'task'|'event'|'project'|'mixed',
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

        $domainFocus = $extracted['domain_focus'] ?? null;
        $entityTypePreference = (string) ($extracted['entity_type_preference'] ?? 'mixed');
        if (! in_array($entityTypePreference, ['task', 'event', 'project', 'mixed'], true)) {
            $entityTypePreference = 'mixed';
        }

        return [
            'intent_type' => $intentType,
            'priority_filters' => array_values(array_unique($extracted['priority_filters'] ?? [])),
            'task_keywords' => array_values(array_unique($extracted['task_keywords'] ?? [])),
            'time_constraint' => $timeConstraint,
            'domain_focus' => is_string($domainFocus) ? $domainFocus : null,
            'entity_type_preference' => $entityTypePreference,
            'comparison_focus' => $comparisonFocus,
            'recurring_requested' => (bool) ($extracted['recurring_requested'] ?? false),
        ];
    }
}
