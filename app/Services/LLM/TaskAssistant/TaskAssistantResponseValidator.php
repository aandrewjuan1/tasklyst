<?php

namespace App\Services\LLM\TaskAssistant;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

class TaskAssistantResponseValidator
{
    /**
     * Validate the structured payload for the task-choice flow against Laravel rules and the snapshot.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $snapshot
     * @return array{valid: bool, data: array<string, mixed>, errors: array<int, string>}
     */
    public function validateTaskChoice(array $payload, array $snapshot): array
    {
        $rules = [
            'chosen_type' => ['nullable', 'string', 'in:task,event,project'],
            'chosen_id' => ['nullable', 'integer'],
            'chosen_title' => ['nullable', 'string', 'max:200'],
            'chosen_task_id' => ['nullable', 'integer'],
            'chosen_task_title' => ['nullable', 'string', 'max:200'],
            'suggestion' => ['required', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'max:1000'],
            'steps' => ['required', 'array', 'min:1', 'max:20'],
            'steps.*' => ['string', 'max:300'],
        ];

        $validator = Validator::make($payload, $rules);

        if ($validator->fails()) {
            return [
                'valid' => false,
                'data' => [],
                'errors' => $validator->errors()->all(),
            ];
        }

        /** @var Collection<int, array{id:int,title:string}> $tasks */
        $tasks = collect(Arr::get($snapshot, 'tasks', []))
            ->map(function (array $task): array {
                return [
                    'id' => (int) ($task['id'] ?? 0),
                    'title' => (string) ($task['title'] ?? ''),
                ];
            });

        /** @var Collection<int, array{id:int,title:string}> $events */
        $events = collect(Arr::get($snapshot, 'events', []))
            ->map(function (array $event): array {
                return [
                    'id' => (int) ($event['id'] ?? 0),
                    'title' => (string) ($event['title'] ?? ''),
                ];
            });

        /** @var Collection<int, array{id:int,title:string}> $projects */
        $projects = collect(Arr::get($snapshot, 'projects', []))
            ->map(function (array $project): array {
                return [
                    'id' => (int) ($project['id'] ?? 0),
                    'title' => (string) ($project['name'] ?? ''),
                ];
            });

        $allowedTaskIds = $tasks->pluck('id')->all();
        $errors = [];

        $chosenType = $payload['chosen_type'] ?? null;
        $chosenId = $payload['chosen_id'] ?? null;
        $chosenTitle = $payload['chosen_title'] ?? null;

        $chosenTaskId = $payload['chosen_task_id'] ?? null;
        $chosenTaskTitle = $payload['chosen_task_title'] ?? null;

        if ($chosenType !== null && $chosenId !== null) {
            $chosenId = (int) $chosenId;

            $allowedIds = match ((string) $chosenType) {
                'event' => $events->pluck('id')->all(),
                'project' => $projects->pluck('id')->all(),
                default => $allowedTaskIds,
            };

            if (! in_array($chosenId, $allowedIds, true)) {
                $errors[] = 'chosen_id must be null or one of the IDs from the chosen snapshot list.';
            } else {
                if ($chosenTitle !== null) {
                    $expectedTitle = match ((string) $chosenType) {
                        'event' => $events->firstWhere('id', $chosenId)['title'] ?? null,
                        'project' => $projects->firstWhere('id', $chosenId)['title'] ?? null,
                        default => $tasks->firstWhere('id', $chosenId)['title'] ?? null,
                    };

                    if ($expectedTitle !== null && $expectedTitle !== (string) $chosenTitle) {
                        $errors[] = 'chosen_title must match the title/name of the chosen item from the snapshot.';
                    }
                }
            }
        }

        if ($chosenTaskId !== null) {
            $chosenTaskId = (int) $chosenTaskId;

            if (! in_array($chosenTaskId, $allowedTaskIds, true)) {
                $errors[] = 'chosen_task_id must be null or one of the IDs from snapshot.tasks.';
            } else {
                if ($chosenTaskTitle !== null) {
                    $taskTitle = $tasks
                        ->firstWhere('id', $chosenTaskId)['title'] ?? null;

                    if ($taskTitle !== null && $taskTitle !== (string) $chosenTaskTitle) {
                        $errors[] = 'chosen_task_title must match the title of the chosen task from snapshot.tasks.';
                    }
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
                'chosen_type' => $chosenType,
                'chosen_id' => $chosenId !== null ? (int) $chosenId : null,
                'chosen_title' => $chosenTitle,
                'chosen_task_id' => $chosenTaskId,
                'chosen_task_title' => $chosenTaskTitle,
                'suggestion' => $payload['suggestion'],
                'reason' => $payload['reason'],
                'steps' => $payload['steps'],
            ],
            'errors' => [],
        ];
    }
}
