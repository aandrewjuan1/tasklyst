<?php

namespace App\Services;

use App\Models\TaskAssistantThread;
use App\Support\TaskAssistantSchemas;
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
    ) {}

    /**
     * Run the validate → retry → fallback loop for the task-choice flow.
     *
     * @param  Collection<int, \Prism\Prism\ValueObjects\Messages\Message>  $historyMessages
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

        $promptData['snapshot'] = $snapshot;
        $timeout = (int) config('prism.request_timeout', 120);
        $schema = TaskAssistantSchemas::taskChoiceSchema();

        $baseMessages = $historyMessages->values();
        $baseMessages->push(new UserMessage($userMessageContent));

        $maxRetries = 2;
        $attempt = 0;
        $lastErrors = [];

        while ($attempt <= $maxRetries) {
            $attemptMessages = clone $baseMessages;

            if ($attempt > 0 && $lastErrors !== []) {
                $correction = $this->buildCorrectionMessage($lastErrors, $snapshot);
                $attemptMessages->push(new UserMessage($correction));
                Log::info('task-assistant.task-choice.retry', [
                    'user_id' => $user->id,
                    'thread_id' => $thread->id,
                    'attempt' => $attempt,
                ]);
            }

            $structuredResponse = Prism::structured()
                ->using(Provider::Ollama, 'hermes3:3b')
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

            $result = $this->validator->validateTaskChoice($payload, $snapshot);

            if ($result['valid']) {
                return $result;
            }

            $lastErrors = $result['errors'];

            Log::warning('task-assistant.task-choice.validation_failed', [
                'user_id' => $user->id,
                'thread_id' => $thread->id,
                'attempt' => $attempt,
                'errors' => $lastErrors,
                'allowed_task_ids_count' => count(Arr::pluck($snapshot['tasks'] ?? [], 'id')),
            ]);

            $attempt++;
        }

        $fallback = $this->buildFallbackPayload($snapshot);

        Log::info('task-assistant.task-choice.fallback_used', [
            'user_id' => $user->id,
            'thread_id' => $thread->id,
            'chosen_task_id' => $fallback['chosen_task_id'],
        ]);

        return [
            'valid' => true,
            'data' => $fallback,
            'errors' => $lastErrors,
        ];
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
        $tasks = collect($snapshot['tasks'] ?? []);

        if ($tasks->isEmpty()) {
            return [
                'chosen_task_id' => null,
                'chosen_task_title' => null,
                'summary' => 'No tasks are available in your snapshot. Pick or create a task you want to focus on.',
                'reason' => 'There were no tasks to choose from, so no specific task was selected.',
                'suggested_next_steps' => [
                    'Add one or two tasks you care about most.',
                    'Choose one task and block 25–30 minutes to work on it.',
                ],
            ];
        }

        $ranked = $tasks->sort(function (array $a, array $b): int {
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

            $aDuration = $a['duration_minutes'] ?? null;
            $bDuration = $b['duration_minutes'] ?? null;

            if ($aDuration === null && $bDuration !== null) {
                return 1;
            }

            if ($aDuration !== null && $bDuration === null) {
                return -1;
            }

            if ($aDuration !== null && $bDuration !== null && $aDuration !== $bDuration) {
                return $aDuration <=> $bDuration;
            }

            return (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
        })->values();

        $chosen = $ranked->first();

        return [
            'chosen_task_id' => $chosen['id'] ?? null,
            'chosen_task_title' => $chosen['title'] ?? null,
            'summary' => 'Focus on “'.($chosen['title'] ?? 'your next task').'” next.',
            'reason' => 'It is a sensible next step based on your current snapshot (due date, priority, and duration).',
            'suggested_next_steps' => [
                'Open the task and read it once slowly.',
                'Block 25–30 minutes on your calendar to work on it.',
                'Decide the very first tiny action and start on it.',
            ],
        ];
    }
}
