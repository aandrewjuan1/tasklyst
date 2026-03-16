<?php

namespace App\Services;

use App\Models\TaskAssistantThread;
use App\Support\TaskAssistantSchemas;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class TaskAssistantStudyPlanRunner
{
    public function __construct(
        private readonly TaskAssistantPromptData $promptData,
        private readonly TaskAssistantSnapshotService $snapshotService,
    ) {}

    /**
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
        $timeout = (int) config('prism.request_timeout', 60);
        $schema = TaskAssistantSchemas::studyPlanSchema();

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
                Log::info('task-assistant.study-plan.retry', [
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

            $result = $this->validate($payload, $snapshot);

            if ($result['valid']) {
                return $result;
            }

            $lastErrors = $result['errors'];

            Log::warning('task-assistant.study-plan.validation_failed', [
                'user_id' => $user->id,
                'thread_id' => $thread->id,
                'attempt' => $attempt,
                'errors' => $lastErrors,
            ]);

            $attempt++;
        }

        $fallback = $this->buildFallbackPayload($snapshot);

        Log::info('task-assistant.study-plan.fallback_used', [
            'user_id' => $user->id,
            'thread_id' => $thread->id,
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
        $primaryReason = $validationErrors[0] ?? 'The study_plan JSON did not match the required fields.';

        $taskIds = Arr::pluck($snapshot['tasks'] ?? [], 'id');
        $taskIds = array_values(array_filter(array_map('intval', $taskIds)));

        $parts = [
            'Your previous study_plan JSON was invalid: '.$primaryReason,
            'Retry the same request.',
        ];

        if ($taskIds !== []) {
            $parts[] = 'When you include task_id in an item, it must be one of: ['.implode(',', $taskIds).'].';
        }

        $parts[] = 'Respond with only the study_plan JSON object that matches the schema (no extra text).';

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
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.label' => ['required', 'string', 'max:160'],
            'items.*.task_id' => ['nullable', 'integer'],
            'items.*.estimated_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'items.*.reason' => ['nullable', 'string', 'max:300'],
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

        $errors = [];

        foreach ($payload['items'] as $index => $item) {
            $itemTaskId = $item['task_id'] ?? null;
            if ($itemTaskId !== null && ! in_array((int) $itemTaskId, $tasks, true)) {
                $errors[] = "items.$index.task_id must be null or one of the IDs from snapshot.tasks.";
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
                'items' => $payload['items'],
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
        $tasks = collect($snapshot['tasks'] ?? []);

        $ranked = $tasks
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

                return (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0);
            })
            ->values()
            ->take(5);

        if ($ranked->isEmpty()) {
            return [
                'items' => [
                    [
                        'label' => 'Pick any task from your list to review or study.',
                        'task_id' => null,
                        'estimated_minutes' => 25,
                        'reason' => 'There were no specific tasks to base a plan on, so this is a generic study block.',
                    ],
                ],
                'summary' => 'A simple generic study block based on your current tasks.',
            ];
        }

        $items = $ranked->map(function (array $task): array {
            return [
                'label' => $task['title'] ?? 'Study this task',
                'task_id' => $task['id'] ?? null,
                'estimated_minutes' => $task['duration_minutes'] ?? 25,
                'reason' => 'This is a sensible study/revision item based on your current tasks.',
            ];
        })->values()->all();

        return [
            'items' => $items,
            'summary' => 'A short study or revision plan based on your most important tasks.',
        ];
    }
}
