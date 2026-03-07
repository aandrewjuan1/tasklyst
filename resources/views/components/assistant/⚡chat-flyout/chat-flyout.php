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

    public function acceptRecommendation(int $assistantMessageId): void
    {
        $this->applyRecommendation($assistantMessageId, 'accept');
    }

    public function rejectRecommendation(int $assistantMessageId): void
    {
        $this->applyRecommendation($assistantMessageId, 'reject');
    }

    /**
     * Apply or reject a recommendation associated with a given assistant message.
     */
    private function applyRecommendation(int $assistantMessageId, string $userAction): void
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
            ->where('id', $assistantMessageId)
            ->where('role', 'assistant')
            ->first();

        if (! $message instanceof AssistantMessage) {
            return;
        }

        $metadata = $message->metadata ?? [];
        if (! is_array($metadata)) {
            $metadata = [];
        }

        $snapshot = $metadata['recommendation_snapshot'] ?? null;
        if (! is_array($snapshot)) {
            return;
        }

        $intentValue = (string) ($snapshot['intent'] ?? '');
        $entityTypeValue = (string) ($snapshot['entity_type'] ?? '');

        $intent = LlmIntent::tryFrom($intentValue);
        $entityType = LlmEntityType::tryFrom($entityTypeValue);

        if (! $intent instanceof LlmIntent || ! $entityType instanceof LlmEntityType) {
            return;
        }

        if (in_array($intent, [LlmIntent::CreateTask, LlmIntent::CreateEvent, LlmIntent::CreateProject], true)) {
            match ($entityType) {
                LlmEntityType::Task => app(ApplyAssistantTaskCreateRecommendationAction::class)->execute($user, $snapshot, $userAction),
                LlmEntityType::Event => app(ApplyAssistantEventCreateRecommendationAction::class)->execute($user, $snapshot, $userAction),
                LlmEntityType::Project => app(ApplyAssistantProjectCreateRecommendationAction::class)->execute($user, $snapshot, $userAction),
                LlmEntityType::Multiple => null,
            };
        } else {
            $entity = match ($entityType) {
                LlmEntityType::Task => $this->resolveTaskForRecommendation($user, $snapshot),
                LlmEntityType::Event => Event::query()
                    ->forUser($user->id)
                    ->notCancelled()
                    ->notCompleted()
                    ->orderByStartTime()
                    ->first(),
                LlmEntityType::Project => Project::query()
                    ->forUser($user->id)
                    ->notArchived()
                    ->orderByStartTime()
                    ->orderByName()
                    ->first(),
                LlmEntityType::Multiple => $this->resolveSingleTaskFromMultipleIntent($user, $snapshot),
            };

            if ($entity === null) {
                abort(404, __('The referenced item could not be found. The suggestion could not be applied.'));
            }

            if ($entity instanceof Task) {
                app(ApplyAssistantTaskRecommendationAction::class)->execute($user, $entity, $snapshot, $userAction);
            } elseif ($entity instanceof Event) {
                app(ApplyAssistantEventRecommendationAction::class)->execute($user, $entity, $snapshot, $userAction);
            } elseif ($entity instanceof Project) {
                app(ApplyAssistantProjectRecommendationAction::class)->execute($user, $entity, $snapshot, $userAction);
            }
        }

        $snapshot['user_action'] = $userAction;
        $snapshot['applied'] = $userAction === 'accept';
        $metadata['recommendation_snapshot'] = $snapshot;
        $message->metadata = $metadata;
        $message->save();
    }

    /**
     * Resolve the task to apply when entity_type is task (single-entity or multi-entity with one task).
     * Uses target_task_title from snapshot when present so the correct task is updated.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function resolveTaskForRecommendation(User $user, array $snapshot): ?Task
    {
        $structured = $snapshot['structured'] ?? [];
        $targetTitle = isset($structured['target_task_title']) && trim((string) $structured['target_task_title']) !== ''
            ? trim((string) $structured['target_task_title'])
            : null;

        $query = Task::query()
            ->forUser($user->id)
            ->incomplete()
            ->orderByRaw('CASE WHEN end_datetime IS NULL THEN 1 ELSE 0 END')
            ->orderBy('end_datetime');

        if ($targetTitle !== null) {
            $task = (clone $query)->where('title', $targetTitle)->first();
            if ($task instanceof Task) {
                return $task;
            }
        }

        return $query->first();
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
