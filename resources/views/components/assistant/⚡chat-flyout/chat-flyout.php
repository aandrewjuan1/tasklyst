<?php

use App\Actions\Llm\ApplyAssistantEventCreateRecommendationAction;
use App\Actions\Llm\ApplyAssistantEventRecommendationAction;
use App\Actions\Llm\ApplyAssistantProjectCreateRecommendationAction;
use App\Actions\Llm\ApplyAssistantProjectRecommendationAction;
use App\Actions\Llm\ApplyAssistantTaskCreateRecommendationAction;
use App\Actions\Llm\ApplyAssistantTaskRecommendationAction;
use App\Actions\Llm\ProcessAssistantMessageAction;
use App\Enums\LlmEntityType;
use App\Enums\LlmIntent;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\User;
use App\Services\AssistantConversationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

new class extends Component
{
    public ?int $threadId = null;

    public ?string $currentTraceId = null;

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $messages = [];

    public int $pendingAssistantCount = 0;

    public function mount(?int $threadId = null): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user instanceof User) {
            $this->threadId = null;
            $this->messages = [];

            return;
        }

        /** @var AssistantConversationService $conversation */
        $conversation = app(AssistantConversationService::class);

        if ($threadId !== null) {
            $thread = $conversation->getOrCreateThread($user, $threadId);
        } else {
            $thread = $conversation->getLatestThread($user);

            if (! $thread instanceof AssistantThread) {
                $thread = $conversation->getOrCreateThread($user, null);
            }
        }

        $this->threadId = $thread->id;

        /** @var Collection<int, AssistantMessage> $messages */
        $messages = $thread->messages()->orderBy('created_at')->get();

        $this->messages = $messages
            ->map(static function (AssistantMessage $message): array {
                return [
                    'id' => $message->id,
                    'role' => $message->role,
                    'content' => $message->content,
                    'created_at' => $message->created_at?->toIso8601String(),
                    'metadata' => $message->metadata ?? [],
                ];
            })
            ->values()
            ->all();

        $this->pendingAssistantCount = 0;
        $this->currentTraceId = null;

        $pendingIndex = null;
        $pendingTraceId = null;

        foreach (array_reverse($this->messages, true) as $index => $message) {
            $role = $message['role'] ?? null;
            $metadata = $message['metadata'] ?? [];

            if ($role !== 'user' || ! is_array($metadata)) {
                continue;
            }

            if (($metadata['llm_cancelled'] ?? false) === true) {
                continue;
            }

            $traceId = $metadata['llm_trace_id'] ?? null;

            if (! is_string($traceId) || $traceId === '') {
                continue;
            }

            $pendingIndex = $index;
            $pendingTraceId = $traceId;

            break;
        }

        if ($pendingIndex !== null) {
            $hasAssistantAfter = false;

            foreach ($this->messages as $index => $message) {
                if ($index <= $pendingIndex) {
                    continue;
                }

                if (($message['role'] ?? null) === 'assistant') {
                    $hasAssistantAfter = true;

                    break;
                }
            }

            if (! $hasAssistantAfter) {
                $this->pendingAssistantCount = 1;
                $this->currentTraceId = $pendingTraceId;
            }
        }
    }

    /**
     * Start a brand new assistant thread for the current user.
     *
     * @return array<string, mixed>
     */
    public function newThread(): array
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(403);
        }

        /** @var AssistantConversationService $conversation */
        $conversation = app(AssistantConversationService::class);

        $thread = $conversation->getOrCreateThread($user, null);

        $this->threadId = $thread->id;
        $this->messages = [];
        $this->pendingAssistantCount = 0;

        return [
            'thread_id' => $this->threadId,
            'messages' => $this->messages,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function send(string $content, ?string $clientId = null): array
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(403);
        }

        $trimmed = trim($content);
        if ($trimmed === '') {
            return [
                'client_id' => $clientId,
                'thread_id' => $this->threadId,
                'message' => null,
            ];
        }

        /** @var ProcessAssistantMessageAction $process */
        $process = app(ProcessAssistantMessageAction::class);

        $assistantMessage = $process->execute($user, $trimmed, $this->threadId);

        if ($this->threadId === null) {
            $this->threadId = $assistantMessage->assistant_thread_id;
        }

        return [
            'client_id' => $clientId,
            'thread_id' => $this->threadId,
            'message' => [
                'id' => $assistantMessage->id,
                'role' => $assistantMessage->role,
                'content' => $assistantMessage->content,
                'created_at' => $assistantMessage->created_at?->toIso8601String(),
                'metadata' => $assistantMessage->metadata ?? [],
            ],
        ];
    }

    public function markMessageCancelled(int $messageId): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(403);
        }

        if ($this->threadId === null) {
            return;
        }

        /** @var AssistantMessage|null $message */
        $message = AssistantMessage::query()
            ->where('assistant_thread_id', $this->threadId)
            ->where('id', $messageId)
            ->where('role', 'user')
            ->first();

        if (! $message instanceof AssistantMessage) {
            return;
        }

        $metadata = $message->metadata ?? [];
        if (! is_array($metadata)) {
            $metadata = [];
        }

        $metadata['llm_cancelled'] = true;
        $message->metadata = $metadata;
        $message->save();
    }

    public function cancelInference(string $traceId): void
    {
        $traceId = trim($traceId);
        if ($traceId === '') {
            return;
        }

        Cache::put('tasklyst_llm_cancel:'.$traceId, true, now()->addMinutes(5));
    }

    /**
     * Apply the recommendation and return updated messages so the frontend can sync.
     *
     * @return array{messages: array<int, array<string, mixed>>}
     */
    public function acceptRecommendation(int $assistantMessageId): array
    {
        $this->applyRecommendation($assistantMessageId, 'accept');

        return ['messages' => $this->messages];
    }

    /**
     * Reject the recommendation and return updated messages so the frontend can sync.
     *
     * @return array{messages: array<int, array<string, mixed>>}
     */
    public function rejectRecommendation(int $assistantMessageId): array
    {
        $this->applyRecommendation($assistantMessageId, 'reject');

        return ['messages' => $this->messages];
    }

    public function debugStructuredOutput(int $assistantMessageId): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(403);
        }

        if ($this->threadId === null) {
            abort(404);
        }

        /** @var AssistantMessage|null $message */
        $message = AssistantMessage::query()
            ->where('assistant_thread_id', $this->threadId)
            ->where('id', $assistantMessageId)
            ->where('role', 'assistant')
            ->first();

        if (! $message instanceof AssistantMessage) {
            abort(404);
        }

        $metadata = $message->metadata ?? [];
        if (! is_array($metadata)) {
            $metadata = [];
        }

        $snapshot = $metadata['recommendation_snapshot'] ?? null;
        dd($snapshot);
    }

    /**
     * Apply or reject a recommendation associated with a given assistant message.
     */
    private function applyRecommendation(int $assistantMessageId, string $userAction): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user instanceof User) {
            Log::warning('assistant.apply_recommendation.no_user', [
                'assistant_message_id' => $assistantMessageId,
                'user_action' => $userAction,
            ]);
            abort(403);
        }

        if ($this->threadId === null) {
            Log::warning('assistant.apply_recommendation.no_thread', [
                'assistant_message_id' => $assistantMessageId,
                'user_id' => $user->id,
                'user_action' => $userAction,
            ]);
            return;
        }

        /** @var AssistantMessage|null $message */
        $message = AssistantMessage::query()
            ->where('assistant_thread_id', $this->threadId)
            ->where('id', $assistantMessageId)
            ->where('role', 'assistant')
            ->first();

        if (! $message instanceof AssistantMessage) {
            Log::warning('assistant.apply_recommendation.message_not_found', [
                'assistant_message_id' => $assistantMessageId,
                'thread_id' => $this->threadId,
                'user_id' => $user->id,
                'user_action' => $userAction,
            ]);
            return;
        }

        $metadata = $message->metadata ?? [];
        if (! is_array($metadata)) {
            $metadata = [];
        }

        $snapshot = $metadata['recommendation_snapshot'] ?? null;
        if (! is_array($snapshot)) {
            Log::warning('assistant.apply_recommendation.missing_snapshot', [
                'assistant_message_id' => $assistantMessageId,
                'thread_id' => $this->threadId,
                'user_id' => $user->id,
                'user_action' => $userAction,
                'metadata_keys' => array_keys($metadata),
            ]);
            return;
        }

        $snapshot = $this->normalizeSnapshotKeys($snapshot);

        // Apply/reject uses recommendation_snapshot as the single reference. snapshot.structured is raw LLM output.

        $intentValue = (string) ($snapshot['intent'] ?? '');
        $entityTypeValue = (string) ($snapshot['entity_type'] ?? '');

        $intent = LlmIntent::tryFrom($intentValue);
        $entityType = LlmEntityType::tryFrom($entityTypeValue);

        if (! $intent instanceof LlmIntent || ! $entityType instanceof LlmEntityType) {
            Log::warning('assistant.apply_recommendation.invalid_intent_or_entity', [
                'assistant_message_id' => $assistantMessageId,
                'thread_id' => $this->threadId,
                'user_id' => $user->id,
                'user_action' => $userAction,
                'intent_raw' => $intentValue,
                'entity_type_raw' => $entityTypeValue,
                'snapshot_keys' => array_keys($snapshot),
            ]);
            return;
        }

        $didApply = false;

        if (in_array($intent, [LlmIntent::CreateTask, LlmIntent::CreateEvent, LlmIntent::CreateProject], true)) {
            match ($entityType) {
                LlmEntityType::Task => app(ApplyAssistantTaskCreateRecommendationAction::class)->execute($user, $snapshot, $userAction),
                LlmEntityType::Event => app(ApplyAssistantEventCreateRecommendationAction::class)->execute($user, $snapshot, $userAction),
                LlmEntityType::Project => app(ApplyAssistantProjectCreateRecommendationAction::class)->execute($user, $snapshot, $userAction),
                LlmEntityType::Multiple => null,
            };
            $didApply = true;
        } else {
            $entity = match ($entityType) {
                LlmEntityType::Task => $this->resolveTaskForRecommendation($user, $snapshot),
                LlmEntityType::Event => $this->resolveEventForRecommendation($user, $snapshot),
                LlmEntityType::Project => $this->resolveProjectForRecommendation($user, $snapshot),
                LlmEntityType::Multiple => $this->resolveSingleTaskFromMultipleIntent($user, $snapshot),
            };

            if ($entity === null) {
                Log::warning('assistant.apply_recommendation.entity_not_found', [
                    'assistant_message_id' => $assistantMessageId,
                    'thread_id' => $this->threadId,
                    'user_id' => $user->id,
                    'user_action' => $userAction,
                    'intent' => $intent->value,
                    'entity_type' => $entityType->value,
                    'snapshot_keys' => array_keys($snapshot),
                ]);
                abort(404, __('The referenced item could not be found. The suggestion could not be applied.'));
            }

            $this->authorize('update', $entity);

            if ($entity instanceof Task) {
                $didApply = app(ApplyAssistantTaskRecommendationAction::class)->execute($user, $entity, $snapshot, $userAction);
            } elseif ($entity instanceof Event) {
                $didApply = app(ApplyAssistantEventRecommendationAction::class)->execute($user, $entity, $snapshot, $userAction);
            } elseif ($entity instanceof Project) {
                $didApply = app(ApplyAssistantProjectRecommendationAction::class)->execute($user, $entity, $snapshot, $userAction);
            }
        }

        $snapshot['user_action'] = $userAction;
        $snapshot['applied'] = $userAction === 'accept' && $didApply;
        $metadata['recommendation_snapshot'] = $snapshot;
        $message->metadata = $metadata;
        $message->save();

        $this->refreshMessagesFromThread();

        if (! $didApply && $userAction === 'accept') {
            Log::info('assistant.apply_recommendation.no_changes_applied', [
                'assistant_message_id' => $assistantMessageId,
                'thread_id' => $this->threadId,
                'user_id' => $user->id,
                'intent' => $intent->value,
                'entity_type' => $entityType->value,
                'snapshot_has_appliable_changes' => isset($snapshot['appliable_changes']) && ! empty($snapshot['appliable_changes']),
            ]);
        }
    }

    /**
     * Normalize snapshot keys so we support both snake_case (from DB) and camelCase (from frontend).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function normalizeSnapshotKeys(array $snapshot): array
    {
        $normalized = $snapshot;
        if (isset($snapshot['appliableChanges']) && ! isset($snapshot['appliable_changes'])) {
            $normalized['appliable_changes'] = $snapshot['appliableChanges'];
        }
        if (isset($snapshot['validationConfidence']) && ! isset($snapshot['validation_confidence'])) {
            $normalized['validation_confidence'] = $snapshot['validationConfidence'];
        }
        if (isset($snapshot['recommendedAction']) && ! isset($snapshot['recommended_action'])) {
            $normalized['recommended_action'] = $snapshot['recommendedAction'];
        }
        if (isset($snapshot['fallbackReason']) && ! isset($snapshot['fallback_reason'])) {
            $normalized['fallback_reason'] = $snapshot['fallbackReason'];
        }
        if (isset($snapshot['usedFallback']) && ! isset($snapshot['used_fallback'])) {
            $normalized['used_fallback'] = $snapshot['usedFallback'];
        }

        return $normalized;
    }

    /**
     * Reload messages from the thread so the component state matches the DB (e.g. after apply/reject).
     */
    private function refreshMessagesFromThread(): void
    {
        if ($this->threadId === null) {
            return;
        }

        /** @var AssistantThread|null $thread */
        $thread = AssistantThread::query()->find($this->threadId);
        if (! $thread instanceof AssistantThread) {
            return;
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, AssistantMessage> $messages */
        $messages = $thread->messages()->orderBy('created_at')->get();

        $this->messages = $messages
            ->map(static function (AssistantMessage $message): array {
                return [
                    'id' => $message->id,
                    'role' => $message->role,
                    'content' => $message->content,
                    'created_at' => $message->created_at?->toIso8601String(),
                    'metadata' => $message->metadata ?? [],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Resolve the task to apply when entity_type is task.
     * Uses id from snapshot.structured (raw LLM output) or appliable_changes first; falls back to title match.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function resolveTaskForRecommendation(User $user, array $snapshot): ?Task
    {
        $raw = $snapshot['structured'] ?? [];
        $structured = is_array($raw) ? $raw : (array) $raw;
        if (isset($structured[0]) && is_array($structured[0])) {
            $structured = $structured[0];
        }
        $appliable = $snapshot['appliable_changes'] ?? $snapshot['appliableChanges'] ?? [];
        $appliable = is_array($appliable) ? $appliable : [];

        $query = Task::query()
            ->forUser($user->id)
            ->incomplete()
            ->orderByRaw('CASE WHEN end_datetime IS NULL THEN 1 ELSE 0 END')
            ->orderBy('end_datetime');

        $targetId = null;
        foreach (['target_task_id', 'id', 'targetTaskId'] as $key) {
            if (isset($structured[$key]) && is_numeric($structured[$key])) {
                $targetId = (int) $structured[$key];
                break;
            }
        }
        if (($targetId === null || $targetId <= 0)) {
            foreach (['target_task_id', 'targetTaskId'] as $key) {
                if (isset($appliable[$key]) && is_numeric($appliable[$key])) {
                    $targetId = (int) $appliable[$key];
                    break;
                }
            }
        }
        if ($targetId !== null && $targetId > 0) {
            $task = (clone $query)->where('id', $targetId)->first();

            return $task instanceof Task ? $task : null;
        }

        $rawTitle = isset($structured['target_task_title']) && trim((string) $structured['target_task_title']) !== ''
            ? trim((string) $structured['target_task_title'])
            : (isset($structured['title']) && trim((string) $structured['title']) !== ''
                ? trim((string) $structured['title'])
                : null);
        $targetTitle = $rawTitle !== null ? (string) preg_replace('/\s+/', ' ', $rawTitle) : null;

        if ($targetTitle !== null && $targetTitle !== '') {
            $task = (clone $query)->where('title', $targetTitle)->first();
            if ($task instanceof Task) {
                return $task;
            }
            $normalized = mb_strtolower($targetTitle);
            $all = (clone $query)->get();
            $task = $all->first(function (Task $t) use ($normalized): bool {
                $tTitle = mb_strtolower((string) preg_replace('/\s+/', ' ', trim($t->title ?? '')));

                return $tTitle === $normalized;
            });
            if ($task instanceof Task) {
                return $task;
            }
            $task = $all->first(function (Task $t) use ($normalized): bool {
                $tTitle = mb_strtolower((string) preg_replace('/\s+/', ' ', trim($t->title ?? '')));

                return $tTitle === $normalized
                    || str_starts_with($tTitle, $normalized)
                    || str_starts_with($normalized, $tTitle);
            });
            if ($task instanceof Task) {
                return $task;
            }
            $task = $all->first(function (Task $t) use ($normalized): bool {
                $tTitle = mb_strtolower((string) preg_replace('/\s+/', ' ', trim($t->title ?? '')));

                return mb_strpos($tTitle, $normalized) !== false || mb_strpos($normalized, $tTitle) !== false;
            });

            return $task instanceof Task ? $task : null;
        }

        return null;
    }

    /**
     * Resolve the event to apply when entity_type is event.
     * Uses id from snapshot.structured (raw LLM output) or appliable_changes first; falls back to title match.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function resolveEventForRecommendation(User $user, array $snapshot): ?Event
    {
        $raw = $snapshot['structured'] ?? [];
        $structured = is_array($raw) ? $raw : (array) $raw;
        if (isset($structured[0]) && is_array($structured[0])) {
            $structured = $structured[0];
        }
        $appliable = $snapshot['appliable_changes'] ?? $snapshot['appliableChanges'] ?? [];
        $appliable = is_array($appliable) ? $appliable : [];

        $query = Event::query()
            ->forUser($user->id)
            ->notCancelled()
            ->notCompleted()
            ->orderByStartTime();

        $targetId = null;
        foreach (['target_event_id', 'id', 'targetEventId'] as $key) {
            if (isset($structured[$key]) && is_numeric($structured[$key])) {
                $targetId = (int) $structured[$key];
                break;
            }
        }
        if ($targetId === null || $targetId <= 0) {
            foreach (['target_event_id', 'targetEventId'] as $key) {
                if (isset($appliable[$key]) && is_numeric($appliable[$key])) {
                    $targetId = (int) $appliable[$key];
                    break;
                }
            }
        }
        if ($targetId !== null && $targetId > 0) {
            $event = (clone $query)->where('id', $targetId)->first();

            return $event instanceof Event ? $event : null;
        }

        $rawTitle = isset($structured['target_event_title']) && trim((string) $structured['target_event_title']) !== ''
            ? trim((string) $structured['target_event_title'])
            : (isset($structured['title']) && trim((string) $structured['title']) !== ''
                ? trim((string) $structured['title'])
                : null);
        $targetTitle = $rawTitle !== null ? (string) preg_replace('/\s+/', ' ', $rawTitle) : null;

        if ($targetTitle !== null && $targetTitle !== '') {
            $event = (clone $query)->where('title', $targetTitle)->first();
            if ($event instanceof Event) {
                return $event;
            }
            $normalized = mb_strtolower($targetTitle);
            $all = (clone $query)->get();
            $event = $all->first(function (Event $e) use ($normalized): bool {
                $eTitle = mb_strtolower((string) preg_replace('/\s+/', ' ', trim($e->title ?? '')));

                return $eTitle === $normalized;
            });
            if ($event instanceof Event) {
                return $event;
            }
            $event = $all->first(function (Event $e) use ($normalized): bool {
                $eTitle = mb_strtolower((string) preg_replace('/\s+/', ' ', trim($e->title ?? '')));

                return $eTitle === $normalized
                    || str_starts_with($eTitle, $normalized)
                    || str_starts_with($normalized, $eTitle);
            });
            if ($event instanceof Event) {
                return $event;
            }
            $event = $all->first(function (Event $e) use ($normalized): bool {
                $eTitle = mb_strtolower((string) preg_replace('/\s+/', ' ', trim($e->title ?? '')));

                return mb_strpos($eTitle, $normalized) !== false || mb_strpos($normalized, $eTitle) !== false;
            });

            return $event instanceof Event ? $event : null;
        }

        return null;
    }

    /**
     * Resolve the project to apply when entity_type is project.
     * Uses id from snapshot.structured (raw LLM output) or appliable_changes first; falls back to name match.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function resolveProjectForRecommendation(User $user, array $snapshot): ?Project
    {
        $raw = $snapshot['structured'] ?? [];
        $structured = is_array($raw) ? $raw : (array) $raw;
        if (isset($structured[0]) && is_array($structured[0])) {
            $structured = $structured[0];
        }
        $appliable = $snapshot['appliable_changes'] ?? $snapshot['appliableChanges'] ?? [];
        $appliable = is_array($appliable) ? $appliable : [];

        $query = Project::query()
            ->forUser($user->id)
            ->notArchived()
            ->orderByStartTime()
            ->orderByName();

        $targetId = null;
        foreach (['target_project_id', 'id', 'targetProjectId'] as $key) {
            if (isset($structured[$key]) && is_numeric($structured[$key])) {
                $targetId = (int) $structured[$key];
                break;
            }
        }
        if ($targetId === null || $targetId <= 0) {
            foreach (['target_project_id', 'targetProjectId'] as $key) {
                if (isset($appliable[$key]) && is_numeric($appliable[$key])) {
                    $targetId = (int) $appliable[$key];
                    break;
                }
            }
        }
        if ($targetId !== null && $targetId > 0) {
            $project = (clone $query)->where('id', $targetId)->first();

            return $project instanceof Project ? $project : null;
        }

        $rawName = isset($structured['target_project_name']) && trim((string) $structured['target_project_name']) !== ''
            ? trim((string) $structured['target_project_name'])
            : (isset($structured['name']) && trim((string) $structured['name']) !== ''
                ? trim((string) $structured['name'])
                : (isset($structured['title']) && trim((string) $structured['title']) !== ''
                    ? trim((string) $structured['title'])
                    : null));
        $targetName = $rawName !== null ? (string) preg_replace('/\s+/', ' ', $rawName) : null;

        if ($targetName !== null && $targetName !== '') {
            $project = (clone $query)->where('name', $targetName)->first();
            if ($project instanceof Project) {
                return $project;
            }
            $normalized = mb_strtolower($targetName);
            $all = (clone $query)->get();
            $project = $all->first(function (Project $p) use ($normalized): bool {
                $pName = mb_strtolower((string) preg_replace('/\s+/', ' ', trim($p->name ?? '')));

                return $pName === $normalized;
            });
            if ($project instanceof Project) {
                return $project;
            }
            $project = $all->first(function (Project $p) use ($normalized): bool {
                $pName = mb_strtolower((string) preg_replace('/\s+/', ' ', trim($p->name ?? '')));

                return $pName === $normalized
                    || str_starts_with($pName, $normalized)
                    || str_starts_with($normalized, $pName);
            });
            if ($project instanceof Project) {
                return $project;
            }
            $project = $all->first(function (Project $p) use ($normalized): bool {
                $pName = mb_strtolower((string) preg_replace('/\s+/', ' ', trim($p->name ?? '')));

                return mb_strpos($pName, $normalized) !== false || mb_strpos($normalized, $pName) !== false;
            });

            return $project instanceof Project ? $project : null;
        }

        return null;
    }

    /**
     * When intent is schedule_tasks_and_events or schedule_tasks_and_projects (entity Multiple)
     * but appliable_changes targets a single task, resolve that task so Apply works.
     *
     * @param  array<string, mixed>  $snapshot
     * @return Task|Event|Project|null
     */
    private function resolveSingleTaskFromMultipleIntent(User $user, array $snapshot): Task|Event|Project|null
    {
        $appliable = $snapshot['appliable_changes'] ?? [];
        if (! is_array($appliable) || ($appliable['entity_type'] ?? '') !== 'task') {
            return null;
        }

        return $this->resolveTaskForRecommendation($user, $snapshot);
    }
};
