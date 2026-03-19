<?php

namespace App\Services\LLM\TaskAssistant;

use App\Models\TaskAssistantThread;
use App\Support\LLM\TaskAssistantSchemas;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;

final class TaskAssistantStructuredFlowGenerator
{
    public function __construct(
        private readonly TaskAssistantPromptData $promptData,
        private readonly TaskAssistantSnapshotService $snapshotService,
        private readonly TaskAssistantContextAnalyzer $contextAnalyzer,
    ) {}

    /**
     * @param  Collection<int, mixed>  $historyMessages
     * @param  array<string, mixed>  $tools
     * @return array{valid: bool, data: array<string, mixed>, errors: array<int, string>}
     */
    public function generateDailySchedule(
        TaskAssistantThread $thread,
        string $userMessageContent,
        Collection $historyMessages,
        array $tools
    ): array {
        $user = $thread->user;

        $promptData = $this->promptData->forUser($user);
        $snapshot = $this->snapshotService->buildForUser($user);

        Log::info('task-assistant.snapshot', [
            'user_id' => $user->id,
            'thread_id' => $thread->id,
            'snapshot' => $snapshot,
        ]);

        $context = $this->contextAnalyzer->analyzeUserContext($userMessageContent, $snapshot);

        $contextualSnapshot = $this->applyContextToSnapshot($snapshot, $context);
        $promptData['snapshot'] = $contextualSnapshot;
        $promptData['user_context'] = $context;

        $timeout = (int) config('prism.request_timeout', 120);

        $blocks = $this->generateDeterministicBlocks($contextualSnapshot, $context);
        $deterministicSummary = $this->buildDeterministicSummary($context);

        // LLM is used only for short narrative refinement (not for block generation).
        $refinementSchema = TaskAssistantSchemas::dailyScheduleRefinementSchema();
        $blocksJson = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $messages = $historyMessages->values();
        $messages->push(new UserMessage($userMessageContent));
        $messages->push(new UserMessage(
            'Here are the proposed schedule blocks (task_id/event_id values are internal and must not be mentioned). '.
            'Refine ONLY the summary and assistant_note to match these blocks. Return JSON only.'."\n\n".
            'BLOCKS_JSON: '.$blocksJson
        ));

        $summary = $deterministicSummary;
        $assistantNote = null;

        try {
            $structuredResponse = Prism::structured()
                ->using(Provider::Ollama, (string) config('task-assistant.model', 'hermes3:3b'))
                ->withSystemPrompt(view('prompts.task-assistant-system', $promptData))
                ->withMessages($messages->all())
                ->withTools([])
                ->withSchema($refinementSchema)
                ->withClientOptions(['timeout' => $timeout])
                ->asStructured();

            $payload = $structuredResponse->structured ?? [];
            $payload = is_array($payload) ? $payload : [];

            if (isset($payload['summary']) && is_string($payload['summary'])) {
                $summary = $payload['summary'] !== '' ? $payload['summary'] : $deterministicSummary;
            }

            if (isset($payload['assistant_note']) && is_string($payload['assistant_note'])) {
                $assistantNote = $payload['assistant_note'] !== '' ? $payload['assistant_note'] : null;
            }
        } catch (\Throwable $e) {
            Log::warning('task-assistant.daily-schedule.refinement_failed', [
                'user_id' => $user->id,
                'thread_id' => $thread->id,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'valid' => true,
            'data' => [
                'blocks' => $blocks,
                'summary' => $summary,
                'assistant_note' => $assistantNote,
            ],
            'errors' => [],
        ];
    }

    /**
     * @param  Collection<int, mixed>  $historyMessages
     * @param  array<string, mixed>  $tools
     * @return array{valid: bool, data: array<string, mixed>, errors: array<int, string>}
     */
    public function generateStudyPlan(
        TaskAssistantThread $thread,
        string $userMessageContent,
        Collection $historyMessages,
        array $tools
    ): array {
        $user = $thread->user;

        $promptData = $this->promptData->forUser($user);
        $snapshot = $this->snapshotService->buildForUser($user);

        Log::info('task-assistant.snapshot', [
            'user_id' => $user->id,
            'thread_id' => $thread->id,
            'snapshot' => $snapshot,
        ]);

        $context = $this->contextAnalyzer->analyzeUserContext($userMessageContent, $snapshot);

        $promptData['snapshot'] = $snapshot;
        $promptData['user_context'] = $context;

        $timeout = (int) config('prism.request_timeout', 120);
        $schema = TaskAssistantSchemas::studyPlanSchema();

        $messages = $historyMessages->values();
        $messages->push(new UserMessage($userMessageContent));

        try {
            $structuredResponse = Prism::structured()
                ->using(Provider::Ollama, (string) config('task-assistant.model', 'hermes3:3b'))
                ->withSystemPrompt(view('prompts.task-assistant-system', $promptData))
                ->withMessages($messages->all())
                ->withTools($tools)
                ->withSchema($schema)
                ->withClientOptions(['timeout' => $timeout])
                ->asStructured();

            $payload = $structuredResponse->structured ?? [];
            $payload = is_array($payload) ? $payload : [];
        } catch (\Throwable $e) {
            Log::warning('task-assistant.study-plan.generation_failed', [
                'user_id' => $user->id,
                'thread_id' => $thread->id,
                'error' => $e->getMessage(),
            ]);

            $payload = [];
        }

        return [
            'valid' => true,
            'data' => $payload,
            'errors' => [],
        ];
    }

    /**
     * @param  Collection<int, mixed>  $historyMessages
     * @param  array<string, mixed>  $tools
     * @return array{valid: bool, data: array<string, mixed>, errors: array<int, string>}
     */
    public function generateReviewSummary(
        TaskAssistantThread $thread,
        string $userMessageContent,
        Collection $historyMessages,
        array $tools
    ): array {
        $user = $thread->user;

        $promptData = $this->promptData->forUser($user);
        $snapshot = $this->snapshotService->buildForUser($user);

        Log::info('task-assistant.snapshot', [
            'user_id' => $user->id,
            'thread_id' => $thread->id,
            'snapshot' => $snapshot,
        ]);

        $context = $this->contextAnalyzer->analyzeUserContext($userMessageContent, $snapshot);

        $promptData['snapshot'] = $snapshot;
        $promptData['user_context'] = $context;

        $timeout = (int) config('prism.request_timeout', 120);
        $schema = TaskAssistantSchemas::reviewSummarySchema();

        $messages = $historyMessages->values();
        $messages->push(new UserMessage($userMessageContent));

        try {
            $structuredResponse = Prism::structured()
                ->using(Provider::Ollama, (string) config('task-assistant.model', 'hermes3:3b'))
                ->withSystemPrompt(view('prompts.task-assistant-system', $promptData))
                ->withMessages($messages->all())
                ->withTools($tools)
                ->withSchema($schema)
                ->withClientOptions(['timeout' => $timeout])
                ->asStructured();

            $payload = $structuredResponse->structured ?? [];
            $payload = is_array($payload) ? $payload : [];
        } catch (\Throwable $e) {
            Log::warning('task-assistant.review-summary.generation_failed', [
                'user_id' => $user->id,
                'thread_id' => $thread->id,
                'error' => $e->getMessage(),
            ]);

            $payload = [];
        }

        return [
            'valid' => true,
            'data' => $payload,
            'errors' => [],
        ];
    }

    /**
     * Apply context filtering to snapshot for scheduling.
     *
     * @return array<string, mixed>
     */
    private function applyContextToSnapshot(array $snapshot, array $context): array
    {
        $contextualSnapshot = $snapshot;

        if (! empty($context['priority_filters'])) {
            $contextualSnapshot['tasks'] = collect($snapshot['tasks'] ?? [])
                ->filter(function (array $task) use ($context): bool {
                    return in_array($task['priority'] ?? 'medium', $context['priority_filters'], true);
                })
                ->values()
                ->all();
        }

        if (! empty($context['task_keywords'])) {
            $contextualSnapshot['tasks'] = collect($contextualSnapshot['tasks'] ?? [])
                ->filter(function (array $task) use ($context): bool {
                    $title = strtolower($task['title'] ?? '');
                    foreach ($context['task_keywords'] as $keyword) {
                        if ($keyword !== null && str_contains($title, strtolower((string) $keyword))) {
                            return true;
                        }
                    }

                    return false;
                })
                ->values()
                ->all();
        }

        if (($context['time_constraint'] ?? null) === 'today') {
            $today = new \DateTime;
            $contextualSnapshot['tasks'] = collect($contextualSnapshot['tasks'] ?? [])
                ->filter(function (array $task) use ($today): bool {
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
     * @return array<int, array<string, mixed>>
     */
    private function generateDeterministicBlocks(array $snapshot, array $context): array
    {
        $tasks = collect($snapshot['tasks'] ?? []);

        $noteContext = '';
        if (! empty($context['priority_filters'])) {
            $noteContext = ' for '.implode(', ', $context['priority_filters']).' priority tasks';
        } elseif (! empty($context['task_keywords'])) {
            $noteContext = ' related to '.implode(', ', $context['task_keywords']);
        }

        if ($tasks->isEmpty()) {
            return [[
                'start_time' => '09:00',
                'end_time' => '09:30',
                'task_id' => null,
                'event_id' => null,
                'label' => 'Choose any task from your list',
                'note' => 'No tasks matched your criteria, so this is a generic focus block'.$noteContext.'.',
            ]];
        }

        $priorityOrder = [
            'urgent' => 1,
            'high' => 2,
            'medium' => 3,
            'low' => 4,
        ];

        $focusedTasks = $tasks
            ->sort(function (array $a, array $b) use ($priorityOrder): int {
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

                if (is_string($aEnds) && is_string($bEnds) && $aEnds !== $bEnds) {
                    return strcmp($aEnds, $bEnds);
                }

                return (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
            })
            ->values()
            ->take(4);

        $blocks = [];
        $startHour = 9;

        foreach ($focusedTasks as $task) {
            $blocks[] = [
                'start_time' => sprintf('%02d:00', $startHour),
                'end_time' => sprintf('%02d:30', $startHour),
                'task_id' => $task['id'] ?? null,
                'event_id' => null,
                'label' => $task['title'] ?? 'Focus time',
                'note' => 'Focus block based on your request'.$noteContext.'.',
            ];

            $startHour += 1;
        }

        return $blocks;
    }

    private function buildDeterministicSummary(array $context): string
    {
        $summary = 'A focused schedule with clear blocks to structure your day';

        if (! empty($context['priority_filters'])) {
            $summary .= ' for '.implode(', ', $context['priority_filters']).' priority tasks';
        }

        if (! empty($context['task_keywords'])) {
            $summary .= ' related to '.implode(', ', $context['task_keywords']);
        }

        return $summary.'.';
    }
}
