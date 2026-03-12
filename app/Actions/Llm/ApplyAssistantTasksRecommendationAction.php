<?php

namespace App\Actions\Llm;

use App\DataTransferObjects\Llm\TaskUpdatePropertiesRecommendationDto;
use App\Enums\LlmIntent;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class ApplyAssistantTasksRecommendationAction
{
    public function __construct(
        private ApplyTaskPropertiesRecommendationAction $applyTaskProperties,
    ) {}

    /**
     * Apply or reject a multi-task scheduling recommendation (ScheduleTasks).
     * Returns true if at least one task was updated.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function execute(User $user, array $snapshot, string $userAction): bool
    {
        $intentValue = (string) ($snapshot['intent'] ?? '');
        $intent = LlmIntent::tryFrom($intentValue);
        if ($intent !== LlmIntent::ScheduleTasks) {
            Log::info('assistant.tasks_apply.intent_not_supported', [
                'user_id' => $user->id,
                'intent_raw' => $intentValue,
                'user_action' => $userAction,
            ]);

            return false;
        }

        $rawStructured = $snapshot['structured'] ?? [];
        $structured = is_array($rawStructured) ? $rawStructured : (array) $rawStructured;

        $scheduled = $structured['scheduled_tasks'] ?? null;
        if (! is_array($scheduled) || $scheduled === []) {
            Log::info('assistant.tasks_apply.no_scheduled_tasks', [
                'user_id' => $user->id,
                'user_action' => $userAction,
                'structured_keys' => array_keys($structured),
            ]);

            return false;
        }

        $reasoning = trim((string) ($snapshot['reasoning'] ?? $structured['reasoning'] ?? ''));
        if ($reasoning === '') {
            $reasoning = 'Schedule suggested by assistant.';
        }

        $confidence = (float) (
            $snapshot['validation_confidence']
            ?? $snapshot['validationConfidence']
            ?? $structured['validation_confidence']
            ?? $structured['validationConfidence']
            ?? 0.0
        );

        $didUpdateAny = false;

        foreach ($scheduled as $item) {
            if (! is_array($item) || ! isset($item['id']) || ! is_numeric($item['id'])) {
                continue;
            }

            $taskId = (int) $item['id'];

            /** @var Task|null $task */
            $task = Task::query()
                ->where('user_id', $user->id)
                ->whereKey($taskId)
                ->first();

            if (! $task instanceof Task) {
                continue;
            }

            Gate::forUser($user)->authorize('update', $task);

            $properties = [];
            $start = $item['start_datetime'] ?? $item['startDatetime'] ?? null;
            if (is_string($start) && trim($start) !== '') {
                $properties['startDatetime'] = trim($start);
            }
            if (isset($item['duration']) && is_numeric($item['duration']) && (int) $item['duration'] > 0) {
                $properties['duration'] = (int) $item['duration'];
            }

            if ($properties === []) {
                continue;
            }

            $dto = TaskUpdatePropertiesRecommendationDto::fromStructured([
                'reasoning' => $reasoning,
                'confidence' => $confidence,
                'properties' => $properties,
            ]);

            if ($dto === null) {
                continue;
            }

            $didUpdate = $this->applyTaskProperties->execute(
                user: $user,
                task: $task,
                recommendation: $dto,
                intent: $intent,
                userAction: $userAction,
            );

            $didUpdateAny = $didUpdateAny || $didUpdate;
        }

        return $didUpdateAny;
    }
}
