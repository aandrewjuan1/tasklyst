<?php

namespace App\Services\LLM\TaskAssistant;

use App\Models\TaskAssistantThread;
use App\Services\LLM\Prioritization\TaskPrioritizationService;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class TaskAssistantTaskChoiceRunner
{
    public function __construct(
        private readonly TaskAssistantPromptData $promptData,
        private readonly TaskAssistantSnapshotService $snapshotService,
        private readonly TaskAssistantResponseValidator $validator,
        private readonly TaskPrioritizationService $prioritizationService,
        private readonly TaskAssistantTaskChoiceConstraintsExtractor $constraintsExtractor,
    ) {}

    /**
     * Run validate → retry → fallback loop for task-choice flow.
     * Uses context-aware deterministic prioritization with LLM explanation.
     *
     * @param  Collection<int, \Prism\Prism\ValueObjects\Messages\UserMessage>  $historyMessages
     * @param  array<string, mixed>  $tools
     * @return array{valid: bool, data: array<string, mixed>, errors: array<int, string>}
     */
    public function run(TaskAssistantThread $thread, string $userMessageContent, Collection $historyMessages, array $tools): array
    {
        $user = $thread->user;
        $promptData = $this->promptData->forUser($user);
        $snapshot = $this->snapshotService->buildForUser($user);
        $scopedSnapshot = $this->applyUserRequestScopeToSnapshot($snapshot, $userMessageContent);

        Log::info('task-assistant.snapshot', [
            'user_id' => $user->id,
            'thread_id' => $thread->id,
            'snapshot' => $snapshot,
        ]);

        // Step 1: Extract deterministic constraints from user text.
        $context = $this->constraintsExtractor->extract($userMessageContent);

        // Step 2: Apply context-aware deterministic prioritization across tasks + events + projects
        $today = (string) ($snapshot['today'] ?? date('Y-m-d'));
        $timezone = (string) ($snapshot['timezone'] ?? config('app.timezone', 'UTC'));
        $now = new \DateTimeImmutable('now', new \DateTimeZone($timezone));

        Log::info('task-assistant.context_analysis', [
            'user_id' => $user->id,
            'thread_id' => $thread->id,
            'context' => $context,
        ]);

        $focusRanking = $this->prioritizationService->prioritizeFocus($scopedSnapshot, $context);
        $restrictToTasksOnly = ! $this->userExplicitlyRequestsEventsOrCalendar($userMessageContent);

        if ($restrictToTasksOnly) {
            $topTask = $this->prioritizationService->getTopTask($scopedSnapshot['tasks'] ?? [], (string) ($scopedSnapshot['today'] ?? $today), $context);
            if ($topTask === null) {
                return [
                    'valid' => true,
                    'data' => $this->buildNoTasksResponse($context),
                    'errors' => [],
                ];
            }

            $topFocus = [
                'type' => 'task',
                'id' => (int) ($topTask['id'] ?? 0),
                'title' => (string) ($topTask['title'] ?? ''),
                'score' => (int) (
                    ($topTask['deadline_score'] ?? 0) +
                    ($topTask['priority_score'] ?? 0) +
                    ($topTask['duration_score'] ?? 0)
                ),
                'reasoning' => (string) ($topTask['reasoning'] ?? 'Selected task'),
                'raw' => $topTask,
            ];
        } else {
            $topFocus = $this->prioritizationService->getTopFocus($scopedSnapshot, $context);
        }

        // Log context-aware deterministic decision
        Log::info('task-assistant.context_aware.prioritization', [
            'user_id' => $user->id,
            'thread_id' => $thread->id,
            'top_focus' => $topFocus,
            'total_tasks' => count($scopedSnapshot['tasks'] ?? []),
            'total_events' => count($scopedSnapshot['events'] ?? []),
            'total_projects' => count($scopedSnapshot['projects'] ?? []),
            'ranked_candidates' => count($focusRanking),
            'context' => $context,
            'reasoning' => $topFocus['reasoning'] ?? 'No reasoning available',
        ]);

        // If no tasks available after filtering, return empty response
        if (! $topFocus) {
            return [
                'valid' => true,
                'data' => $this->buildNoTasksResponse($context),
                'errors' => [],
            ];
        }

        // Step 3: Use LLM ONLY for natural language explanation (focus item already selected deterministically)
        $focusItem = $topFocus;
        $focusLabel = (string) ($focusItem['title'] ?? 'your next focus');

        // Inject deterministic selection into the prompt so the LLM can only explain WHY,
        // not re-select a different task.
        $promptData['preselected_task'] = [
            'id' => $focusItem['id'] ?? null,
            'title' => $focusLabel,
            'reasoning' => (string) ($topFocus['reasoning'] ?? 'No reasoning available'),
        ];

        $taskContext = [
            'id' => $focusItem['id'],
            'title' => $focusLabel,
            'priority' => $focusItem['raw']['priority'] ?? null,
            'ends_at' => $focusItem['raw']['ends_at'] ?? ($focusItem['raw']['end_at'] ?? null),
            'duration_minutes' => $focusItem['raw']['duration_minutes'] ?? null,
        ];
        $explanation = $this->generateContextAwareExplanation(
            $taskContext,
            $userMessageContent,
            $historyMessages,
            $promptData,
            $snapshot,
            $context
        );
        $deterministicSteps = $this->buildDeterministicSteps($taskContext, $now);

        // Build context-aware response with LLM explanation
        $response = [
            'chosen_type' => $focusItem['type'],
            'chosen_id' => $focusItem['id'],
            'chosen_title' => $focusLabel,
            'chosen_task_id' => $focusItem['type'] === 'task' ? $focusItem['id'] : null,
            'chosen_task_title' => $focusItem['type'] === 'task' ? $focusLabel : null,
            'suggestion' => $explanation['suggestion'],
            'reason' => $explanation['reason'],
            'steps' => $deterministicSteps,
            'estimated_minutes' => $focusItem['raw']['duration_minutes'] ?? null,
            'priority' => $focusItem['raw']['priority'] ?? null,
            'context_applied' => $context,
        ];

        Log::info('task-assistant.context_aware.final_response', [
            'user_id' => $user->id,
            'thread_id' => $thread->id,
            'chosen_type' => $focusItem['type'],
            'chosen_id' => $focusItem['id'],
            'chosen_title' => $focusLabel,
            'deterministic_reasoning' => $topFocus['reasoning'] ?? 'No reasoning available',
            'context' => $context,
            'llm_explanation_used' => true,
        ]);

        return [
            'valid' => true,
            'data' => $response,
            'errors' => [],
        ];
    }

    /**
     * Enforce that the model follows the user's request.
     *
     * For TaskPrioritization (task_choice flow), default to tasks-only unless the user explicitly
     * mentions event/meeting/calendar keywords.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function applyUserRequestScopeToSnapshot(array $snapshot, string $userMessageContent): array
    {
        if ($this->userExplicitlyRequestsEventsOrCalendar($userMessageContent)) {
            return $snapshot;
        }

        // Tasks-only by default: prevents "next task" requests from returning an event.
        $snapshot['events'] = [];
        $snapshot['projects'] = [];

        return $snapshot;
    }

    private function userExplicitlyRequestsEventsOrCalendar(string $userMessageContent): bool
    {
        return preg_match('/\b(event|events|meeting|calendar|schedule|appointment|appointments|class|lecture|interview|exam|presentation)\b/i', $userMessageContent) === 1;
    }

    /**
     * @param  array<int, string>  $validationErrors
     * @param  array<string, mixed>  $snapshot
     */
    private function buildCorrectionMessage(array $validationErrors, array $snapshot): string
    {
        $primaryReason = $validationErrors[0] ?? 'The JSON did not match the required fields.';

        $taskIds = Arr::pluck($snapshot['tasks'] ?? [], 'id');
        $taskIds = array_values(array_filter(array_map('intval', $taskIds)));

        $idList = $taskIds !== [] ? implode(',', $taskIds) : '';

        $parts = [
            'Your previous task_choice JSON was invalid: '.$primaryReason,
            'Retry the same request.',
        ];

        if ($idList !== '') {
            $parts[] = 'chosen_task_id must be null or one of: ['.$idList.'].';
        }

        $parts[] = 'Respond with only the task_choice JSON object that matches the schema (no extra text).';

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function buildFallbackPayload(array $snapshot): array
    {
        $tasks = $snapshot['tasks'] ?? [];
        $today = $snapshot['today'] ?? date('Y-m-d');

        if (empty($tasks)) {
            return [
                'chosen_type' => null,
                'chosen_id' => null,
                'chosen_title' => null,
                'chosen_task_id' => null,
                'chosen_task_title' => null,
                'suggestion' => 'No tasks are available in your snapshot. Pick or create a task you want to focus on.',
                'reason' => 'There were no tasks to choose from, so no specific task was selected.',
                'steps' => [
                    'Add one or two tasks you care about most.',
                    'Choose one task and block 25–30 minutes to work on it.',
                ],
            ];
        }

        // Use deterministic prioritization service for fallback
        $chosen = $this->prioritizationService->getTopTask($tasks, $today);

        if (! $chosen) {
            return [
                'chosen_type' => null,
                'chosen_id' => null,
                'chosen_title' => null,
                'chosen_task_id' => null,
                'chosen_task_title' => null,
                'suggestion' => 'Unable to determine the best task from your current list.',
                'reason' => 'No valid tasks could be prioritized with the available information.',
                'steps' => [
                    'Review your task list for completeness.',
                    'Add or update tasks with clear deadlines and priorities.',
                ],
            ];
        }

        // Log deterministic fallback usage
        Log::info('task-assistant.deterministic.fallback', [
            'user_id' => $snapshot['user_id'] ?? null,
            'thread_id' => $snapshot['thread_id'] ?? null,
            'fallback_reason' => 'LLM validation failed, using deterministic prioritization',
            'chosen_task' => $chosen,
            'reasoning' => $chosen['reasoning'] ?? 'No reasoning available',
        ]);

        $priorityText = ucfirst($chosen['priority'] ?? 'medium');
        $deadlineText = '';

        if ($chosen['ends_at']) {
            $deadline = new \DateTime($chosen['ends_at']);
            $deadlineText = ' due '.$deadline->format('M j').$deadline->format(' at g:i A');
        }

        return [
            'chosen_type' => 'task',
            'chosen_id' => $chosen['id'] ?? null,
            'chosen_title' => $chosen['title'] ?? null,
            'chosen_task_id' => $chosen['id'] ?? null,
            'chosen_task_title' => $chosen['title'] ?? null,
            'suggestion' => 'Focus on "'.$chosen['title'].'" next. This '.$priorityText.' priority task'.$deadlineText.' needs your attention.',
            'reason' => 'This task is selected based on deterministic deadline-aware prioritization to ensure you meet your commitments.',
            'steps' => [
                'Review task details and requirements carefully.',
                'Block dedicated time on your calendar to work on it.',
                'Start with the most important sub-task first.',
            ],
        ];
    }

    /**
     * Generate natural language explanation using LLM for the pre-selected task.
     * LLM only provides explanation, not task selection.
     *
     * @param  array<string, mixed>  $topTask
     * @param  Collection<int, \Prism\Prism\ValueObjects\Messages\UserMessage>  $historyMessages
     * @param  array<string, mixed>  $promptData
     * @param  array<string, mixed>  $snapshot
     * @return array{suggestion: string, reason: string}
     */
    private function generateExplanationWithLLM(
        array $topTask,
        string $userMessageContent,
        Collection $historyMessages,
        array $promptData,
        array $snapshot
    ): array {
        $timeout = (int) config('prism.request_timeout', 120);

        // Build focused prompt for explanation only
        $explanationPrompt = $this->buildExplanationPrompt($topTask, $userMessageContent);

        try {
            $response = Prism::structured()
                ->using(Provider::Ollama, (string) config('task-assistant.model', 'hermes3:3b'))
                ->withSystemPrompt(view('prompts.task-assistant-system', $promptData))
                ->withMessages([
                    new UserMessage($userMessageContent),
                    new UserMessage($explanationPrompt),
                ])
                ->withSchema($this->explanationSchema())
                ->withClientOptions(['timeout' => $timeout])
                ->asStructured();

            $payload = $response->structured ?? [];

            Log::info('task-assistant.llm.explanation', [
                'user_id' => $snapshot['user_id'] ?? null,
                'thread_id' => $snapshot['thread_id'] ?? null,
                'task_id' => $topTask['id'],
                'explanation_payload' => $payload,
            ]);

            return [
                'suggestion' => $this->sanitizeNarrativeText((string) ($payload['suggestion'] ?? "Focus on '{$topTask['title']}' next."), 300),
                'reason' => $this->sanitizeNarrativeText((string) ($payload['reason'] ?? 'This task has been selected as your priority.'), 500),
            ];

        } catch (\Throwable $e) {
            Log::warning('task-assistant.llm.explanation_failed', [
                'user_id' => $snapshot['user_id'] ?? null,
                'thread_id' => $snapshot['thread_id'] ?? null,
                'task_id' => $topTask['id'],
                'error' => $e->getMessage(),
            ]);

            // Fallback to simple explanation
            return $this->generateFallbackExplanation($topTask);
        }
    }

    /**
     * Build focused prompt for task explanation only.
     */
    private function buildExplanationPrompt(array $topTask, string $userMessageContent): string
    {
        $priority = ucfirst($topTask['priority'] ?? 'medium');
        $deadlineText = '';

        if (isset($topTask['ends_at']) && $topTask['ends_at']) {
            try {
                $deadline = new \DateTime($topTask['ends_at']);
                $today = new \DateTime;
                $interval = $today->diff($deadline);

                if ($deadline < $today) {
                    $deadlineText = 'overdue';
                } elseif ($interval->days === 0) {
                    $deadlineText = 'due today';
                } elseif ($interval->days === 1) {
                    $deadlineText = 'due tomorrow';
                } elseif ($interval->days <= 7) {
                    $deadlineText = 'due this week';
                } else {
                    $deadlineText = 'due later';
                }
            } catch (\Exception $e) {
                $deadlineText = 'with unknown deadline';
            }
        } else {
            $deadlineText = 'with no deadline';
        }

        return "Explain why '{$topTask['title']}' ({$priority} priority, {$deadlineText}) is the best task to work on. Provide:
1. A clear suggestion focusing on this specific task
2. The reasoning based on deadline and priority
3. 3-4 concrete next steps

IMPORTANT RULES FOR NEXT STEPS:
- ONLY use info available in the snapshot fields: task title, priority, due status (due today/tomorrow/this week/overdue), and duration (if present).
- Do NOT invent project requirements, checklists, or milestones that are not present in the snapshot.
- Do NOT guess any personal details (like the user's name).

Keep it concise and actionable.";
    }

    /**
     * Simple schema for explanation generation.
     */
    private function explanationSchema(): \Prism\Prism\Schema\ObjectSchema
    {
        return new \Prism\Prism\Schema\ObjectSchema(
            name: 'explanation',
            description: 'Natural language explanation for pre-selected task',
            properties: [
                new \Prism\Prism\Schema\StringSchema(
                    name: 'suggestion',
                    description: 'Clear suggestion to work on the task'
                ),
                new \Prism\Prism\Schema\StringSchema(
                    name: 'reason',
                    description: 'Why this task was chosen'
                ),
            ],
            requiredFields: ['suggestion', 'reason']
        );
    }

    /**
     * Generate fallback explanation when LLM fails.
     */
    private function generateFallbackExplanation(array $topTask): array
    {
        $priority = ucfirst($topTask['priority'] ?? 'medium');
        $deadlineText = '';

        if (isset($topTask['ends_at']) && $topTask['ends_at']) {
            try {
                $deadline = new \DateTime($topTask['ends_at']);
                $today = new \DateTime;
                $interval = $today->diff($deadline);

                if ($deadline < $today) {
                    $deadlineText = 'overdue';
                } elseif ($interval->days === 0) {
                    $deadlineText = 'due today';
                } elseif ($interval->days === 1) {
                    $deadlineText = 'due tomorrow';
                } elseif ($interval->days <= 7) {
                    $deadlineText = 'due this week';
                } else {
                    $deadlineText = 'due later';
                }
            } catch (\Exception $e) {
                $deadlineText = 'with unknown deadline';
            }
        } else {
            $deadlineText = 'with no deadline';
        }

        return [
            'suggestion' => "Focus on '{$topTask['title']}' next. This {$priority} priority task is {$deadlineText} and needs your attention.",
            'reason' => "This task was selected as your priority because it's {$priority} priority and {$deadlineText}.",
        ];
    }

    /**
     * Build response for when no tasks are available.
     */
    private function buildNoTasksResponse(array $context = []): array
    {
        $suggestion = 'No tasks are available. Consider creating some tasks to get started.';
        $reason = 'There are no tasks in your list to prioritize.';

        if (! empty($context['priority_filters'])) {
            $suggestion = 'No '.implode(', ', $context['priority_filters']).' priority tasks found. Try checking other priority levels.';
            $reason = 'No tasks with the specified priority levels were available.';
        }

        if (! empty($context['task_keywords'])) {
            $suggestion = 'No tasks found related to '.implode(', ', $context['task_keywords']).'. Try other keywords or create relevant tasks.';
            $reason = 'No tasks matching your specified keywords were available.';
        }

        return [
            'chosen_type' => null,
            'chosen_id' => null,
            'chosen_title' => null,
            'chosen_task_id' => null,
            'chosen_task_title' => null,
            'suggestion' => $suggestion,
            'reason' => $reason,
            'steps' => [
                'Add one or two tasks you care about most.',
                'Choose one task and block 25–30 minutes to work on it.',
            ],
        ];
    }

    /**
     * Generate context-aware natural language explanation using LLM.
     */
    private function generateContextAwareExplanation(
        array $topTask,
        string $userMessageContent,
        Collection $historyMessages,
        array $promptData,
        array $snapshot,
        array $context
    ): array {
        $timeout = (int) config('prism.request_timeout', 120);

        // Build focused prompt for context-aware explanation
        $explanationPrompt = $this->buildContextAwareExplanationPrompt($topTask, $userMessageContent, $context);

        try {
            $response = Prism::structured()
                ->using(Provider::Ollama, 'hermes3:3b')
                ->withSystemPrompt(view('prompts.task-assistant-system', $promptData))
                ->withMessages([
                    new UserMessage($userMessageContent),
                    new UserMessage($explanationPrompt),
                ])
                ->withSchema($this->explanationSchema())
                ->withClientOptions(['timeout' => $timeout])
                ->asStructured();

            $payload = $response->structured ?? [];

            Log::info('task-assistant.context_aware.llm.explanation', [
                'user_id' => $snapshot['user_id'] ?? null,
                'thread_id' => $snapshot['thread_id'] ?? null,
                'task_id' => $topTask['id'],
                'context' => $context,
                'explanation_payload' => $payload,
            ]);

            return [
                'suggestion' => $this->sanitizeNarrativeText((string) ($payload['suggestion'] ?? "Focus on '{$topTask['title']}' next."), 300),
                'reason' => $this->sanitizeNarrativeText((string) ($payload['reason'] ?? 'This task has been selected as your priority.'), 500),
            ];

        } catch (\Throwable $e) {
            Log::warning('task-assistant.context_aware.llm.explanation_failed', [
                'user_id' => $snapshot['user_id'] ?? null,
                'thread_id' => $snapshot['thread_id'] ?? null,
                'task_id' => $topTask['id'],
                'context' => $context,
                'error' => $e->getMessage(),
            ]);

            // Fallback to context-aware explanation
            return $this->generateContextAwareFallbackExplanation($topTask, $context);
        }
    }

    /**
     * Build focused prompt for context-aware task explanation.
     */
    private function buildContextAwareExplanationPrompt(array $topTask, string $userMessageContent, array $context): string
    {
        $priority = ucfirst($topTask['priority'] ?? 'medium');
        $deadlineText = '';

        if (isset($topTask['ends_at']) && $topTask['ends_at']) {
            try {
                $deadline = new \DateTime($topTask['ends_at']);
                $today = new \DateTime;
                $interval = $today->diff($deadline);

                if ($deadline < $today) {
                    $deadlineText = 'overdue';
                } elseif ($interval->days === 0) {
                    $deadlineText = 'due today';
                } elseif ($interval->days === 1) {
                    $deadlineText = 'due tomorrow';
                } elseif ($interval->days <= 7) {
                    $deadlineText = 'due this week';
                } else {
                    $deadlineText = 'due later';
                }
            } catch (\Exception $e) {
                $deadlineText = 'with unknown deadline';
            }
        } else {
            $deadlineText = 'with no deadline';
        }

        $contextInfo = '';
        if (! empty($context['priority_filters'])) {
            $contextInfo .= 'User specifically asked for '.implode(', ', $context['priority_filters']).' priority tasks. ';
        }
        if (! empty($context['task_keywords'])) {
            $contextInfo .= 'User is interested in tasks related to: '.implode(', ', $context['task_keywords']).'. ';
        }
        if (! empty($context['comparison_focus'])) {
            $contextInfo .= 'User is making a specific comparison between tasks. ';
        }

        return "Explain why '{$topTask['title']}' ({$priority} priority, {$deadlineText}) is the best choice for the user's request.

User's original request: \"{$userMessageContent}\"
Context analysis: {$contextInfo}

Provide:
1. A clear suggestion that acknowledges the user's specific request
2. Reasoning that explains why this task matches their criteria
3. 3-4 concrete next steps

IMPORTANT RULES FOR NEXT STEPS:
- ONLY use info available in the snapshot fields: task title, priority, due status (due today/tomorrow/this week/overdue), and duration (if present).
- Do NOT invent project requirements, checklists, or milestones that are not present in the snapshot.
- Do NOT guess any personal details (like the user's name).

Keep it conversational and acknowledge their specific needs.";
    }

    /**
     * Generate context-aware fallback explanation when LLM fails.
     */
    private function generateContextAwareFallbackExplanation(array $topTask, array $context): array
    {
        $priority = ucfirst($topTask['priority'] ?? 'medium');
        $deadlineText = '';

        if (isset($topTask['ends_at']) && $topTask['ends_at']) {
            try {
                $deadline = new \DateTime($topTask['ends_at']);
                $today = new \DateTime;
                $interval = $today->diff($deadline);

                if ($deadline < $today) {
                    $deadlineText = 'overdue';
                } elseif ($interval->days === 0) {
                    $deadlineText = 'due today';
                } elseif ($interval->days === 1) {
                    $deadlineText = 'due tomorrow';
                } elseif ($interval->days <= 7) {
                    $deadlineText = 'due this week';
                } else {
                    $deadlineText = 'due later';
                }
            } catch (\Exception $e) {
                $deadlineText = 'with unknown deadline';
            }
        } else {
            $deadlineText = 'with no deadline';
        }

        $reasoning = "This task was selected because it's {$priority} priority and {$deadlineText}";

        if (! empty($context['priority_filters'])) {
            $reasoning .= ', and it matches your request for '.implode(', ', $context['priority_filters']).' priority tasks';
        }

        if (! empty($context['task_keywords'])) {
            $reasoning .= ', and it relates to your interest in '.implode(', ', $context['task_keywords']);
        }

        return [
            'suggestion' => "Focus on '{$topTask['title']}' next. This {$priority} priority task is {$deadlineText} and matches your specific request.",
            'reason' => $reasoning.'.',
        ];
    }

    /**
     * @param  array{id: mixed, title: mixed, priority: mixed, ends_at: mixed, duration_minutes: mixed}  $taskContext
     * @return array<int, string>
     */
    private function buildDeterministicSteps(array $taskContext, \DateTimeImmutable $now): array
    {
        $title = (string) ($taskContext['title'] ?? 'this task');
        $duration = (int) ($taskContext['duration_minutes'] ?? 0);
        $priority = strtolower((string) ($taskContext['priority'] ?? 'medium'));
        $dueBucket = $this->resolveDueBucket($taskContext['ends_at'] ?? null, $now);

        $sessionMinutes = match (true) {
            $duration > 0 && $duration <= 30 => 20,
            $duration > 0 && $duration <= 90 => 30,
            $duration > 0 && $duration <= 180 => 45,
            default => 60,
        };

        $urgencyStep = match ($dueBucket) {
            'overdue' => "Start with '{$title}' now and aim to complete the first meaningful chunk within {$sessionMinutes} minutes",
            'today' => "Block {$sessionMinutes} focused minutes for '{$title}' before the end of today",
            'tomorrow' => "Start '{$title}' today with a {$sessionMinutes}-minute kickoff so tomorrow is easier",
            'this_week' => "Schedule '{$title}' in your next available {$sessionMinutes}-minute focus block this week",
            default => "Schedule '{$title}' in your next available {$sessionMinutes}-minute focus block",
        };

        $priorityStep = match ($priority) {
            'urgent' => 'Treat this as your top priority and delay lower-priority tasks until this block is done',
            'high' => 'Handle this before medium/low tasks to keep your deadlines under control',
            default => 'Finish this focus block first, then reassess what to do next',
        };

        return [
            "Open '{$title}' and list the first 2-3 concrete actions you can do right away",
            $urgencyStep,
            $priorityStep,
            "After the block, record progress and decide the next step for '{$title}'",
        ];
    }

    private function resolveDueBucket(mixed $endsAt, \DateTimeImmutable $now): string
    {
        if (! is_string($endsAt) || $endsAt === '') {
            return 'none';
        }

        try {
            $deadline = new \DateTimeImmutable($endsAt);
            if ($deadline < $now) {
                return 'overdue';
            }

            // Bucket by calendar day in the snapshot timezone (not by 24h intervals).
            $nowTz = $now->getTimezone();
            $deadlineLocal = $deadline->setTimezone($nowTz);

            $nowDate = $now->format('Y-m-d');
            $deadlineDate = $deadlineLocal->format('Y-m-d');

            if ($deadlineDate === $nowDate) {
                return 'today';
            }

            $nowMidnight = new \DateTimeImmutable($nowDate.' 00:00:00', $nowTz);
            $deadlineMidnight = new \DateTimeImmutable($deadlineDate.' 00:00:00', $nowTz);
            $daysUntil = (int) $nowMidnight->diff($deadlineMidnight)->days;

            if ($daysUntil === 1) {
                return 'tomorrow';
            }
            if ($daysUntil <= 7) {
                return 'this_week';
            }
        } catch (\Throwable) {
            return 'none';
        }

        return 'later';
    }

    private function sanitizeNarrativeText(string $text, int $maxLength): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $maxLength - 1)).'…';
    }
}
