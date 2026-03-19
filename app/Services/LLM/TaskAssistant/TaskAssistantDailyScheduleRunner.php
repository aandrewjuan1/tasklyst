<?php

namespace App\Services\LLM\TaskAssistant;

use App\Models\TaskAssistantThread;
use App\Support\LLM\TaskAssistantSchemas;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class TaskAssistantDailyScheduleRunner
{
    public function __construct(
        private readonly TaskAssistantPromptData $promptData,
        private readonly TaskAssistantSnapshotService $snapshotService,
        private readonly TaskAssistantContextAnalyzer $contextAnalyzer,
    ) {}

    /**
     * Run the validate → retry → fallback loop for the daily schedule flow.
     * Uses context-aware approach for user-responsive scheduling.
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

        Log::info('task-assistant.snapshot', [
            'user_id' => $user->id,
            'thread_id' => $thread->id,
            'snapshot' => $snapshot,
        ]);

        // Step 1: Analyze user context and intent for scheduling
        $context = $this->contextAnalyzer->analyzeUserContext($userMessageContent, $snapshot);

        Log::info('task-assistant.daily_schedule.context_analysis', [
            'user_id' => $user->id,
            'thread_id' => $thread->id,
            'context' => $context,
        ]);

        // Step 2: Apply context-aware filtering to tasks for scheduling
        $contextualSnapshot = $this->applyContextToSnapshot($snapshot, $context);

        $promptData['snapshot'] = $contextualSnapshot;
        $promptData['user_context'] = $context;
        $timeout = (int) config('prism.request_timeout', 120);
        $schema = TaskAssistantSchemas::dailyScheduleSchema();

        $baseMessages = $historyMessages->values();
        $baseMessages->push(new UserMessage($userMessageContent));

        $maxRetries = 2;
        $attempt = 0;
        $lastErrors = [];

        while ($attempt <= $maxRetries) {
            $attemptMessages = clone $baseMessages;

            if ($attempt > 0 && $lastErrors !== []) {
                $correction = $this->buildContextAwareCorrectionMessage($lastErrors, $contextualSnapshot, $context);
                $attemptMessages->push(new UserMessage($correction));
                Log::info('task-assistant.daily-schedule.retry', [
                    'user_id' => $user->id,
                    'thread_id' => $thread->id,
                    'attempt' => $attempt,
                ]);
            }

            $structuredResponse = Prism::structured()
                ->using(Provider::Ollama, (string) config('task-assistant.model', 'hermes3:3b'))
                ->withSystemPrompt(view('prompts.task-assistant-system', $promptData))
                ->withMessages($attemptMessages->all())
                ->withTools($tools)
                ->withSchema($schema)
                ->withClientOptions(['timeout' => $timeout])
                ->asStructured();

            $payload = $structuredResponse->structured ?? [];
            if (! is_array($payload)) {
                $payload = [];
            }

            $result = $this->validate($payload, $contextualSnapshot);

            if ($result['valid']) {
                Log::info('task-assistant.daily_schedule.context_aware_success', [
                    'user_id' => $user->id,
                    'thread_id' => $thread->id,
                    'context' => $context,
                    'blocks_count' => count($payload['blocks'] ?? []),
                ]);

                return $result;
            }

            $lastErrors = $result['errors'];

            Log::warning('task-assistant.daily-schedule.validation_failed', [
                'user_id' => $user->id,
                'thread_id' => $thread->id,
                'attempt' => $attempt,
                'errors' => $lastErrors,
                'context' => $context,
            ]);

            $attempt++;
        }

        $fallback = $this->buildContextAwareFallbackPayload($contextualSnapshot, $context);

        Log::info('task-assistant.daily-schedule.context_aware_fallback_used', [
            'user_id' => $user->id,
            'thread_id' => $thread->id,
            'context' => $context,
        ]);

        return [
            'valid' => true,
            'data' => $fallback,
            'errors' => $lastErrors,
        ];
    }

    /**
     * Apply context filtering to snapshot for scheduling.
     */
    private function applyContextToSnapshot(array $snapshot, array $context): array
    {
        $contextualSnapshot = $snapshot;

        // Apply task filtering based on context
        if (! empty($context['priority_filters'])) {
            $contextualSnapshot['tasks'] = collect($snapshot['tasks'] ?? [])
                ->filter(function (array $task) use ($context) {
                    return in_array($task['priority'] ?? 'medium', $context['priority_filters']);
                })
                ->values()
                ->all();
        }

        if (! empty($context['task_keywords'])) {
            $contextualSnapshot['tasks'] = collect($contextualSnapshot['tasks'] ?? [])
                ->filter(function (array $task) use ($context) {
                    $title = strtolower($task['title'] ?? '');
                    foreach ($context['task_keywords'] as $keyword) {
                        if (str_contains($title, strtolower($keyword))) {
                            return true;
                        }
                    }

                    return false;
                })
                ->values()
                ->all();
        }

        if (! empty($context['time_constraint']) && $context['time_constraint'] === 'today') {
            $today = new \DateTime;
            $contextualSnapshot['tasks'] = collect($contextualSnapshot['tasks'] ?? [])
                ->filter(function (array $task) use ($today) {
                    if (! isset($task['ends_at']) || $task['ends_at'] === null) {
                        return false;
                    }
                    try {
                        $deadline = new \DateTime($task['ends_at']);

                        return $deadline->format('Y-m-d') === $today->format('Y-m-d');
                    } catch (\Exception $e) {
                        return false;
                    }
                })
                ->values()
                ->all();
        }

        return $contextualSnapshot;
    }

    /**
     * Build context-aware correction message.
     */
    private function buildContextAwareCorrectionMessage(array $validationErrors, array $snapshot, array $context): string
    {
        $primaryReason = $validationErrors[0] ?? 'The daily_schedule JSON did not match the required fields.';

        $taskIds = Arr::pluck($snapshot['tasks'] ?? [], 'id');
        $taskIds = array_values(array_filter(array_map('intval', $taskIds)));

        $eventIds = Arr::pluck($snapshot['events'] ?? [], 'id');
        $eventIds = array_values(array_filter(array_map('intval', $eventIds)));

        $parts = [
            'Your previous daily_schedule JSON was invalid: '.$primaryReason,
            'Retry the same request.',
        ];

        // Add context-aware guidance
        if (! empty($context['priority_filters'])) {
            $parts[] = 'Remember to focus on '.implode(', ', $context['priority_filters']).' priority tasks only.';
        }

        if (! empty($context['task_keywords'])) {
            $parts[] = 'Remember to focus on tasks related to: '.implode(', ', $context['task_keywords']).'.';
        }

        if ($taskIds !== []) {
            $parts[] = 'When you include task_id in a block, it must be one of: ['.implode(',', $taskIds).'].';
        }

        if ($eventIds !== []) {
            $parts[] = 'When you include event_id in a block, it must be one of: ['.implode(',', $eventIds).'].';
        }

        $parts[] = 'Respond with only the daily_schedule JSON object that matches the schema (no extra text).';

        return implode(' ', $parts);
    }

    /**
     * Build context-aware fallback payload.
     */
    private function buildContextAwareFallbackPayload(array $snapshot, array $context): array
    {
        $tasks = collect($snapshot['tasks'] ?? []);

        // Apply context-aware prioritization for fallback
        $focusedTasks = $tasks
            ->sort(function (array $a, array $b): int {
                $priorityOrder = [
                    'urgent' => 1,
                    'high' => 2,
                    'medium' => 3,
                    'low' => 4,
                ];

                $aPriority = $priorityOrder[$a['priority'] ?? 'medium'] ?? 5;
                $bPriority = $priorityOrder[$b['priority'] ?? 'medium'] ?? 5;

                if ($aPriority !== $bPriority) {
                    return $aPriority <=> $bPriority;
                }

                $aEnds = $a['ends_at'] ?? null;
                $bEnds = $b['ends_at'] ?? null;

                if ($aEnds === null && $bEnds !== null) {
                    return 1;
                }

                if ($aEnds !== null && $bEnds === null) {
                    return -1;
                }

                if ($aEnds !== null && $bEnds !== null && $aEnds !== $bEnds) {
                    return strcmp($aEnds, $bEnds);
                }

                return (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
            })
            ->values()
            ->take(2);

        $blocks = [];
        $startHour = 9;

        foreach ($focusedTasks as $task) {
            $blocks[] = [
                'start_time' => sprintf('%02d:00', $startHour),
                'end_time' => sprintf('%02d:30', $startHour),
                'task_id' => $task['id'] ?? null,
                'event_id' => null,
                'label' => $task['title'] ?? null,
                'note' => 'Focus block based on your request for '.implode(', ', $context['priority_filters'] ?? ['tasks']),
            ];
            $startHour += 1;
        }

        if ($blocks === []) {
            $blocks[] = [
                'start_time' => '09:00',
                'end_time' => '09:30',
                'task_id' => null,
                'event_id' => null,
                'label' => 'Choose any task from your list',
                'note' => 'No tasks matched your specific criteria, so this is a generic focus block.',
            ];
        }

        $summary = 'A focused schedule based on your request';
        if (! empty($context['priority_filters'])) {
            $summary .= ' for '.implode(', ', $context['priority_filters']).' priority tasks';
        }
        if (! empty($context['task_keywords'])) {
            $summary .= ' related to '.implode(', ', $context['task_keywords']);
        }

        return [
            'blocks' => $blocks,
            'summary' => $summary.'.',
        ];
    }

    /**
     * @param  array<int, string>  $validationErrors
     * @param  array<string, mixed>  $snapshot
     */
    private function buildCorrectionMessage(array $validationErrors, array $snapshot): string
    {
        $primaryReason = $validationErrors[0] ?? 'The daily_schedule JSON did not match the required fields.';

        $taskIds = Arr::pluck($snapshot['tasks'] ?? [], 'id');
        $taskIds = array_values(array_filter(array_map('intval', $taskIds)));

        $eventIds = Arr::pluck($snapshot['events'] ?? [], 'id');
        $eventIds = array_values(array_filter(array_map('intval', $eventIds)));

        $parts = [
            'Your previous daily_schedule JSON was invalid: '.$primaryReason,
            'Retry the same request.',
        ];

        if ($taskIds !== []) {
            $parts[] = 'When you include task_id in a block, it must be one of: ['.implode(',', $taskIds).'].';
        }

        if ($eventIds !== []) {
            $parts[] = 'When you include event_id in a block, it must be one of: ['.implode(',', $eventIds).'].';
        }

        $parts[] = 'Respond with only the daily_schedule JSON object that matches the schema (no extra text).';

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $snapshot
     * @return array{valid: bool, data: array<string, mixed>, errors: array<int, string>}
     */
    private function validate(array $payload, array $snapshot): array
    {
        $rules = [
            'blocks' => ['required', 'array', 'min:1', 'max:24'],
            'blocks.*.start_time' => ['required', 'string', 'max:20'],
            'blocks.*.end_time' => ['required', 'string', 'max:20'],
            'blocks.*.task_id' => ['nullable', 'integer'],
            'blocks.*.event_id' => ['nullable', 'integer'],
            'blocks.*.label' => ['nullable', 'string', 'max:160'],
            'blocks.*.note' => ['nullable', 'string', 'max:300'],
            'summary' => ['nullable', 'string', 'max:500'],
        ];

        $validator = Validator::make($payload, $rules);

        if ($validator->fails()) {
            return [
                'valid' => false,
                'data' => [],
                'errors' => $validator->errors()->all(),
            ];
        }

        $tasks = collect(Arr::get($snapshot, 'tasks', []))
            ->map(fn (array $task): int => (int) ($task['id'] ?? 0))
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $events = collect(Arr::get($snapshot, 'events', []))
            ->map(fn (array $event): int => (int) ($event['id'] ?? 0))
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $errors = [];

        foreach ($payload['blocks'] as $index => $block) {
            $blockTaskId = $block['task_id'] ?? null;
            if ($blockTaskId !== null && ! in_array((int) $blockTaskId, $tasks, true)) {
                $errors[] = "blocks.$index.task_id must be null or one of the IDs from snapshot.tasks.";
            }

            $blockEventId = $block['event_id'] ?? null;
            if ($blockEventId !== null && ! in_array((int) $blockEventId, $events, true)) {
                $errors[] = "blocks.$index.event_id must be null or one of the IDs from snapshot.events.";
            }
        }

        if ($errors !== []) {
            return [
                'valid' => false,
                'data' => [],
                'errors' => $errors,
            ];
        }

        return [
            'valid' => true,
            'data' => [
                'blocks' => $payload['blocks'],
                'summary' => $payload['summary'] ?? null,
            ],
            'errors' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function buildFallbackPayload(array $snapshot): array
    {
        // Simple deterministic fallback: suggest one or two focused blocks based on urgent / high priority tasks.
        $tasks = collect($snapshot['tasks'] ?? []);

        $focusedTasks = $tasks
            ->sort(function (array $a, array $b): int {
                $priorityOrder = [
                    'urgent' => 1,
                    'high' => 2,
                    'medium' => 3,
                    'low' => 4,
                ];

                $aPriority = $priorityOrder[$a['priority'] ?? 'medium'] ?? 5;
                $bPriority = $priorityOrder[$b['priority'] ?? 'medium'] ?? 5;

                if ($aPriority !== $bPriority) {
                    return $aPriority <=> $bPriority;
                }

                $aEnds = $a['ends_at'] ?? null;
                $bEnds = $b['ends_at'] ?? null;

                if ($aEnds === null && $bEnds !== null) {
                    return 1;
                }

                if ($aEnds !== null && $bEnds === null) {
                    return -1;
                }

                if ($aEnds !== null && $bEnds !== null && $aEnds !== $bEnds) {
                    return strcmp($aEnds, $bEnds);
                }

                return (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
            })
            ->values()
            ->take(2);

        $blocks = [];
        $startHour = 9;

        foreach ($focusedTasks as $task) {
            $blocks[] = [
                'start_time' => sprintf('%02d:00', $startHour),
                'end_time' => sprintf('%02d:30', $startHour),
                'task_id' => $task['id'] ?? null,
                'event_id' => null,
                'label' => $task['title'] ?? null,
                'reason' => 'Focus block for a high-priority task from your list.',
            ];
            $startHour += 1;
        }

        if ($blocks === []) {
            $blocks[] = [
                'start_time' => '09:00',
                'end_time' => '09:30',
                'task_id' => null,
                'event_id' => null,
                'label' => 'Choose any task from your list',
                'reason' => 'There were no specific tasks to schedule, so this is a generic focus block.',
            ];
        }

        return [
            'blocks' => $blocks,
            'summary' => 'A simple set of focus blocks for your day based on your current tasks.',
        ];
    }
}
