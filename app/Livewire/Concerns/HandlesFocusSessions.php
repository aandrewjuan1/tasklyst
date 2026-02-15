<?php

namespace App\Livewire\Concerns;

use App\Actions\FocusSession\AbandonFocusSessionAction;
use App\Actions\FocusSession\CompleteFocusSessionAction;
use App\Actions\FocusSession\GetActiveFocusSessionAction;
use App\Actions\FocusSession\PauseFocusSessionAction;
use App\Actions\FocusSession\ResumeFocusSessionAction;
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
 * - PauseFocusSessionAction $pauseFocusSessionAction
 * - ResumeFocusSessionAction $resumeFocusSessionAction
 *
 * Optionally, the component may define public ?array $activeFocusSession = null.
 * When present, the trait keeps it in sync and dispatches 'focus-session-updated' on start.
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
            $sessionPayload,
            $validated['occurrence_date'] ?? null
        );

        $result = [
            'id' => $session->id,
            'started_at' => $session->started_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'duration_seconds' => $session->duration_seconds,
            'type' => $session->type->value,
            'task_id' => $task->id,
            'sequence_number' => $session->sequence_number,
        ];

        if (property_exists($this, 'activeFocusSession')) {
            $this->activeFocusSession = $result;
            $this->dispatch('focus-session-updated', session: $result);
        }

        return $result;
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

        if (property_exists($this, 'activeFocusSession')) {
            $this->activeFocusSession = null;
        }

        return true;
    }

    /**
     * Abandon the current focus session (Stop or Exit without completing).
     *
     * @param  array<string, mixed>  $payload  Optional: paused_seconds (int)
     */
    public function abandonFocusSession(int $sessionId, array $payload = []): bool
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

        $pausedSeconds = isset($payload['paused_seconds'])
            ? max(0, (int) $payload['paused_seconds'])
            : 0;

        $this->abandonFocusSessionAction->execute($session, $pausedSeconds);
        $this->dispatch('toast', type: 'info', message: __('Focus session stopped.'));

        if (property_exists($this, 'activeFocusSession')) {
            $this->activeFocusSession = null;
        }

        return true;
    }

    /**
     * Pause the current focus session (persists paused_at so reload can restore state).
     */
    public function pauseFocusSession(int $sessionId): bool
    {
        $user = $this->requireAuth(__('You must be logged in to pause a focus session.'));
        if ($user === null) {
            return false;
        }

        $session = FocusSession::query()->forUser($user->id)->find($sessionId);
        if ($session === null) {
            $this->dispatch('toast', type: 'error', message: __('Focus session not found.'));

            return false;
        }

        $this->authorize('update', $session);

        if ($session->ended_at !== null) {
            return false;
        }

        $this->pauseFocusSessionAction->execute($session);

        return true;
    }

    /**
     * Resume the current focus session (flushes paused_at into paused_seconds).
     */
    public function resumeFocusSession(int $sessionId): bool
    {
        $user = $this->requireAuth(__('You must be logged in to resume a focus session.'));
        if ($user === null) {
            return false;
        }

        $session = FocusSession::query()->forUser($user->id)->find($sessionId);
        if ($session === null) {
            $this->dispatch('toast', type: 'error', message: __('Focus session not found.'));

            return false;
        }

        $this->authorize('update', $session);

        if ($session->ended_at !== null) {
            return false;
        }

        $this->resumeFocusSessionAction->execute($session);

        return true;
    }

    /**
     * Return the current user's in-progress focus session as array for the frontend.
     * The API supports resume-after-refresh; consuming components may choose to clear
     * the session on load (e.g. workspace index abandons any active session in mount).
     *
     * @return array{id: int, started_at: string, duration_seconds: int, type: string, task_id: int|null, sequence_number: int, paused_seconds: int, paused_at: string|null, payload: array}|null
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
            'started_at' => $session->started_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'duration_seconds' => $session->duration_seconds,
            'type' => $session->type->value,
            'task_id' => $session->focusable_id,
            'sequence_number' => $session->sequence_number,
            'paused_seconds' => (int) ($session->paused_seconds ?? 0),
            'paused_at' => $session->paused_at?->utc()->format('Y-m-d\TH:i:s.v\Z'),
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
            'started_at' => $session->started_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'duration_seconds' => $session->duration_seconds,
            'type' => $session->type->value,
            'task_id' => null,
            'sequence_number' => $session->sequence_number,
        ];
    }
}
