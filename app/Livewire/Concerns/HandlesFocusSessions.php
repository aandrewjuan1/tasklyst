<?php

namespace App\Livewire\Concerns;

use App\Actions\FocusSession\AbandonFocusSessionAction;
use App\Actions\FocusSession\CompleteFocusSessionAction;
use App\Actions\FocusSession\GetActiveFocusSessionAction;
use App\Actions\FocusSession\StartFocusSessionAction;
use App\Enums\FocusSessionType;
use App\Models\FocusSession;
use App\Models\Task;
use App\Support\Validation\FocusSessionCompleteValidation;
use App\Support\Validation\FocusSessionStartValidation;
use Illuminate\Support\Facades\Validator;

/**
 * Trait for Livewire components that start, complete, abandon, and resume focus sessions.
 *
 * Requires the component to define:
 * - StartFocusSessionAction $startFocusSessionAction
 * - CompleteFocusSessionAction $completeFocusSessionAction
 * - AbandonFocusSessionAction $abandonFocusSessionAction
 * - GetActiveFocusSessionAction $getActiveFocusSessionAction
 */
trait HandlesFocusSessions
{
    /**
     * Start a focus session for a task. Payload: type, duration_seconds, started_at, optional sequence_number, optional used_task_duration.
     *
     * @param  array<string, mixed>  $payload
     * @return array{id: int, started_at: string, duration_seconds: int, type: string, task_id: int, sequence_number: int}|array{error: string}
     */
    public function startFocusSession(int $taskId, array $payload): array
    {
        $user = $this->requireAuth(__('You must be logged in to start a focus session.'));
        if ($user === null) {
            return ['error' => __('You must be logged in to start a focus session.')];
        }

        $data = array_merge($payload, ['task_id' => $taskId]);
        $validator = Validator::make($data, FocusSessionStartValidation::rules());
        if ($validator->fails()) {
            $this->dispatch('toast', type: 'error', message: __('Invalid focus session data.'));

            return ['error' => $validator->errors()->first()];
        }

        $task = Task::query()->forUser($user->id)->find($taskId);
        if ($task === null) {
            $this->dispatch('toast', type: 'error', message: __('Task not found.'));

            return ['error' => __('Task not found.')];
        }

        $this->authorize('update', $task);

        $validated = $validator->validated();
        $type = FocusSessionType::from($validated['type']);
        if ($type !== FocusSessionType::Work) {
            $this->dispatch('toast', type: 'error', message: __('Focus session for a task must be a work session.'));

            return ['error' => __('Invalid session type.')];
        }

        $sessionPayload = [];
        if (! empty($validated['payload']['used_task_duration'])) {
            $sessionPayload['used_task_duration'] = true;
        } else {
            $sessionPayload['used_default_duration'] = true;
        }

        $session = $this->startFocusSessionAction->execute(
            $user,
            $task,
            $type,
            (int) $validated['duration_seconds'],
            $validated['started_at'],
            (int) ($validated['sequence_number'] ?? 1),
            $sessionPayload
        );

        return [
            'id' => $session->id,
            'started_at' => $session->started_at->toIso8601String(),
            'duration_seconds' => $session->duration_seconds,
            'type' => $session->type->value,
            'task_id' => $task->id,
            'sequence_number' => $session->sequence_number,
        ];
    }

    /**
     * Complete or abandon a focus session (timer reached 0 or user stopped). Payload: ended_at, completed, paused_seconds.
     *
     * @param  array<string, mixed>  $payload
     */
    public function completeFocusSession(int $sessionId, array $payload): bool
    {
        $user = $this->requireAuth(__('You must be logged in to complete a focus session.'));
        if ($user === null) {
            return false;
        }

        $validator = Validator::make(
            array_merge($payload, ['focus_session_id' => $sessionId]),
            FocusSessionCompleteValidation::rules()
        );
        if ($validator->fails()) {
            $this->dispatch('toast', type: 'error', message: __('Invalid completion data.'));

            return false;
        }

        $session = FocusSession::query()->forUser($user->id)->find($sessionId);
        if ($session === null) {
            $this->dispatch('toast', type: 'error', message: __('Focus session not found.'));

            return false;
        }

        $this->authorize('update', $session);

        $validated = $validator->validated();
        $this->completeFocusSessionAction->execute(
            $session,
            $validated['ended_at'],
            (bool) $validated['completed'],
            (int) $validated['paused_seconds'],
            $validated['mark_task_status'] ?? null
        );
        $this->dispatch('toast', type: 'success', message: __('Focus session saved.'));

        return true;
    }

    /**
     * Abandon the current focus session (Stop or Exit without completing).
     */
    public function abandonFocusSession(int $sessionId): bool
    {
        $user = $this->requireAuth(__('You must be logged in to stop a focus session.'));
        if ($user === null) {
            return false;
        }

        $session = FocusSession::query()->forUser($user->id)->find($sessionId);
        if ($session === null) {
            $this->dispatch('toast', type: 'error', message: __('Focus session not found.'));

            return false;
        }

        $this->authorize('update', $session);

        $this->abandonFocusSessionAction->execute($session);
        $this->dispatch('toast', type: 'info', message: __('Focus session stopped.'));

        return true;
    }

    /**
     * Return the current user's in-progress focus session as array for the frontend (resume after refresh).
     *
     * @return array{id: int, started_at: string, duration_seconds: int, type: string, task_id: int|null, sequence_number: int, payload: array}|null
     */
    public function getActiveFocusSession(): ?array
    {
        $user = $this->requireAuth(__('You must be logged in to view focus session.'));
        if ($user === null) {
            return null;
        }

        $session = $this->getActiveFocusSessionAction->execute($user);
        if ($session === null) {
            return null;
        }

        $this->authorize('view', $session);

        return [
            'id' => $session->id,
            'started_at' => $session->started_at->toIso8601String(),
            'duration_seconds' => $session->duration_seconds,
            'type' => $session->type->value,
            'task_id' => $session->focusable_id,
            'sequence_number' => $session->sequence_number,
            'payload' => $session->payload ?? [],
        ];
    }

    /**
     * Start a break session (no task). Payload: type (short_break|long_break), duration_seconds, started_at, sequence_number.
     *
     * @param  array<string, mixed>  $payload
     * @return array{id: int, started_at: string, duration_seconds: int, type: string, task_id: null, sequence_number: int}|array{error: string}
     */
    public function startBreakSession(array $payload): array
    {
        $user = $this->requireAuth(__('You must be logged in to start a break.'));
        if ($user === null) {
            return ['error' => __('You must be logged in to start a break.')];
        }

        $data = array_merge($payload, ['task_id' => null]);
        $validator = Validator::make($data, FocusSessionStartValidation::rules());
        if ($validator->fails()) {
            $this->dispatch('toast', type: 'error', message: __('Invalid break session data.'));

            return ['error' => $validator->errors()->first()];
        }

        $validated = $validator->validated();
        $type = FocusSessionType::from($validated['type']);
        if ($type === FocusSessionType::Work) {
            $this->dispatch('toast', type: 'error', message: __('Use start focus for work sessions.'));

            return ['error' => __('Invalid session type.')];
        }

        $session = $this->startFocusSessionAction->execute(
            $user,
            null,
            $type,
            (int) $validated['duration_seconds'],
            $validated['started_at'],
            (int) ($validated['sequence_number'] ?? 1),
            []
        );

        return [
            'id' => $session->id,
            'started_at' => $session->started_at->toIso8601String(),
            'duration_seconds' => $session->duration_seconds,
            'type' => $session->type->value,
            'task_id' => null,
            'sequence_number' => $session->sequence_number,
        ];
    }
}
