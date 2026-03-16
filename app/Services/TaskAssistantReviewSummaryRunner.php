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

class TaskAssistantReviewSummaryRunner
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
        $schema = TaskAssistantSchemas::reviewSummarySchema();

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
                Log::info('task-assistant.review-summary.retry', [
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

            Log::warning('task-assistant.review-summary.validation_failed', [
                'user_id' => $user->id,
                'thread_id' => $thread->id,
                'attempt' => $attempt,
                'errors' => $lastErrors,
            ]);

            $attempt++;
        }

        $fallback = $this->buildFallbackPayload($snapshot);

        Log::info('task-assistant.review-summary.fallback_used', [
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
        $primaryReason = $validationErrors[0] ?? 'The review_summary JSON did not match the required fields.';

        $taskIds = Arr::pluck($snapshot['tasks'] ?? [], 'id');
        $taskIds = array_values(array_filter(array_map('intval', $taskIds)));

        $parts = [
            'Your previous review_summary JSON was invalid: '.$primaryReason,
            'Retry the same request.',
        ];

        if ($taskIds !== []) {
            $parts[] = 'When you include task_id in completed or remaining, it must be one of: ['.implode(',', $taskIds).'].';
        }

        $parts[] = 'Respond with only the review_summary JSON object that matches the schema (no extra text).';

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
            'completed' => ['required', 'array', 'max:50'],
            'completed.*.task_id' => ['required', 'integer'],
            'completed.*.title' => ['required', 'string', 'max:160'],
            'remaining' => ['required', 'array', 'max:50'],
            'remaining.*.task_id' => ['required', 'integer'],
            'remaining.*.title' => ['required', 'string', 'max:160'],
            'summary' => ['required', 'string', 'max:500'],
            'next_steps' => ['required', 'array', 'min:1', 'max:20'],
            'next_steps.*' => ['string', 'max:200'],
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

        foreach (['completed', 'remaining'] as $section) {
            foreach ($payload[$section] as $index => $item) {
                $itemTaskId = $item['task_id'] ?? null;
                if ($itemTaskId === null || ! in_array((int) $itemTaskId, $tasks, true)) {
                    $errors[] = "$section.$index.task_id must be one of the IDs from snapshot.tasks.";
                }
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
                'completed' => $payload['completed'],
                'remaining' => $payload['remaining'],
                'summary' => $payload['summary'],
                'next_steps' => $payload['next_steps'],
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

        $completed = $tasks->where('status', 'done')->take(5)->values();
        $remaining = $tasks->reject(fn (array $task): bool => ($task['status'] ?? null) === 'done')
            ->take(5)
            ->values();

        $completedItems = $completed->map(function (array $task): array {
            return [
                'task_id' => $task['id'] ?? 0,
                'title' => $task['title'] ?? 'Completed task',
            ];
        })->values()->all();

        $remainingItems = $remaining->map(function (array $task): array {
            return [
                'task_id' => $task['id'] ?? 0,
                'title' => $task['title'] ?? 'Remaining task',
            ];
        })->values()->all();

        $summaryParts = [];
        if ($completedItems !== []) {
            $summaryParts[] = 'You have completed some tasks recently.';
        }
        if ($remainingItems !== []) {
            $summaryParts[] = 'There are still tasks remaining that you can focus on next.';
        }
        if ($summaryParts === []) {
            $summaryParts[] = 'There are no tasks in your snapshot to review yet.';
        }

        $summary = implode(' ', $summaryParts);

        $nextSteps = [
            'Pick one remaining task and block 25–30 minutes to work on it.',
            'Optionally archive or de-prioritize tasks that no longer matter.',
        ];

        return [
            'completed' => $completedItems,
            'remaining' => $remainingItems,
            'summary' => $summary,
            'next_steps' => $nextSteps,
        ];
    }
}
