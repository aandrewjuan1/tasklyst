<?php

namespace App\Services;

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
            'chosen_task_id' => ['nullable', 'integer'],
            'chosen_task_title' => ['nullable', 'string', 'max:200'],
            'summary' => ['required', 'string', 'max:500'],
            'reason' => ['required', 'string', 'max:500'],
            'suggested_next_steps' => ['required', 'array', 'min:1', 'max:20'],
            'suggested_next_steps.*' => ['string', 'max:200'],
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

        $allowedTaskIds = $tasks->pluck('id')->all();
        $errors = [];

        $chosenTaskId = $payload['chosen_task_id'] ?? null;
        $chosenTaskTitle = $payload['chosen_task_title'] ?? null;

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
                'chosen_task_id' => $chosenTaskId,
                'chosen_task_title' => $chosenTaskTitle,
                'summary' => $payload['summary'],
                'reason' => $payload['reason'],
                'suggested_next_steps' => $payload['suggested_next_steps'],
            ],
            'errors' => [],
        ];
    }
}
