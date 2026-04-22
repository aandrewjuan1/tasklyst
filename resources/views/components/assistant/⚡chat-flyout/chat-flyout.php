<?php

use App\Enums\AssistantSchedulePlanItemStatus;
use App\Enums\MessageRole;
use App\Actions\Assistant\AcceptScheduleProposalsAction;
use App\Jobs\BroadcastTaskAssistantStreamJob;
use App\Models\AssistantSchedulePlan;
use App\Models\AssistantSchedulePlanItem;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;
use App\Notifications\AssistantScheduleAcceptedNotification;
use App\Services\EventService;
use App\Services\LLM\Scheduling\ScheduleDraftMetadataNormalizer;
use App\Services\ProjectService;
use App\Services\TaskService;
use App\Services\LLM\TaskAssistant\TaskAssistantQuickChipResolver;
use App\Support\LLM\SchedulableProposalPolicy;
use App\Services\UserNotificationBroadcastService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public ?TaskAssistantThread $thread = null;

    #[Locked]
    public ?int $userId = null;

    /** @var Collection<int, TaskAssistantMessage> */
    public Collection $chatMessages;

    public string $newMessage = '';

    public string $streamingContent = '';

    public bool $isStreaming = false;

    #[Locked]
    public ?int $streamingMessageId = null;

    public ?int $latestAssistantMessageId = null;

    /** @var array<int, bool> */
    public array $dismissedNextOptionChipsByMessage = [];

    public bool $showWorking = false;

    public ?string $pendingClientActionId = null;

    public ?string $streamingCorrelationId = null;

    public ?string $streamingTimedOutAt = null;

    /** @var array<int, string> */
    public array $emptyStateQuickChips = [];

    public function mount(): void
    {
        $this->userId = Auth::id();
        $this->chatMessages = new Collection;
        $this->ensureThread();
        $this->loadMessages();
        $this->syncStreamingStateFromPersistence();
        $this->refreshEmptyStateQuickChips();
    }

    public function startNewChat(): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        $this->stopActiveStreamingRun();

        $existingEmptyThread = $user->taskAssistantThreads()
            ->whereDoesntHave('messages')
            ->latest('id')
            ->first();

        if ($existingEmptyThread) {
            $this->thread = $existingEmptyThread;
        } else {
            $this->thread = $user->taskAssistantThreads()->create([
                'metadata' => [],
            ]);
        }

        session(['task_assistant.current_thread_id' => $this->thread->id]);

        $this->chatMessages = collect();
        $this->newMessage = '';
        $this->streamingContent = '';
        $this->isStreaming = false;
        $this->streamingMessageId = null;
        $this->latestAssistantMessageId = null;
        $this->dismissedNextOptionChipsByMessage = [];
        $this->showWorking = false;
        $this->streamingCorrelationId = null;
        $this->streamingTimedOutAt = null;
        $this->pendingClientActionId = null;
        $this->refreshEmptyStateQuickChips();
    }

    public function applyQuickPromptChip(string $value): void
    {
        if ($this->isStreaming) {
            return;
        }

        $value = trim($value);
        if ($value === '') {
            return;
        }

        // UX: quick prompt chips should *replace* the current draft input,
        // not append/stack lines.
        $this->newMessage = $value;
    }

    /**
     * Prevent MethodNotFoundException when Livewire/Alpine serializes the component.
     */
    public function toJSON(): string
    {
        return json_encode([
            'chatMessages' => isset($this->chatMessages) ? $this->chatMessages->toArray() : [],
            'newMessage' => $this->newMessage,
            'streamingContent' => $this->streamingContent,
            'isStreaming' => $this->isStreaming,
            'streamingMessageId' => $this->streamingMessageId,
            'latestAssistantMessageId' => $this->latestAssistantMessageId,
            'dismissedNextOptionChipsByMessage' => $this->dismissedNextOptionChipsByMessage,
            'showWorking' => $this->showWorking,
            'streamingCorrelationId' => $this->streamingCorrelationId,
            'streamingTimedOutAt' => $this->streamingTimedOutAt,
        ]);
    }

    protected function rules(): array
    {
        return [
            'newMessage' => ['required', 'string', 'max:16000'],
        ];
    }

    protected function messages(): array
    {
        return [
            'newMessage.required' => __('Please enter a message.'),
            'newMessage.max' => __('Message is too long.'),
        ];
    }

    #[On('echo-private:task-assistant.user.{userId},.json_delta')]
    public function appendStreamingDelta(array $payload): void
    {
        if (! $this->isStreaming || ! $this->streamingMessageId) {
            return;
        }

        $assistantMessageId = (int) ($payload['assistant_message_id'] ?? 0);
        if ($assistantMessageId <= 0 || $assistantMessageId !== $this->streamingMessageId) {
            return;
        }

        $delta = $payload['delta'] ?? '';
        if ($delta !== '') {
            $this->streamingContent .= $delta;
        }
    }

    #[On('echo-private:task-assistant.user.{userId},.stream_end')]
    public function onStreamEnd(array $payload): void
    {
        $assistantMessageId = (int) ($payload['assistant_message_id'] ?? 0);
        if ($assistantMessageId <= 0 || $assistantMessageId !== $this->streamingMessageId) {
            return;
        }

        $this->refreshMessages();
    }

    public function pollStreamingFallback(): void
    {
        if (! $this->isStreaming || ! $this->streamingMessageId || ! $this->thread) {
            return;
        }

        $assistant = $this->thread->messages()
            ->whereKey($this->streamingMessageId)
            ->where('role', MessageRole::Assistant)
            ->first();

        if (! $assistant) {
            $this->refreshMessages();

            return;
        }

        $isStopped = data_get($assistant->metadata, 'stream.status') === 'stopped';
        $isStreamed = (bool) data_get($assistant->metadata, 'streamed', false);
        $hasFinalContent = trim((string) ($assistant->content ?? '')) !== '';

        if ($isStopped || $isStreamed || $hasFinalContent) {
            $this->refreshMessages();
        }
    }

    #[On('assistant-chat-open-requested')]
    public function onAssistantChatOpenRequested(): void
    {
        $this->ensureThread();
        $this->refreshMessages(true);
    }

    public function checkStreamingTimeout(): void
    {
        if (! $this->isStreaming || ! $this->streamingMessageId || ! $this->thread) {
            return;
        }

        $startedAt = data_get($this->thread->metadata, 'stream.processing.started_at');
        if (! is_string($startedAt) || trim($startedAt) === '') {
            return;
        }

        $timeoutSeconds = max(3, (int) config('task-assistant.streaming.health_timeout_seconds', 20));

        try {
            $started = \Carbon\CarbonImmutable::parse($startedAt);
        } catch (\Throwable) {
            return;
        }

        if ($started->addSeconds($timeoutSeconds)->isFuture()) {
            return;
        }

        $assistant = $this->thread->messages()->whereKey($this->streamingMessageId)->first();
        if ($assistant && trim((string) ($assistant->content ?? '')) !== '') {
            $this->refreshMessages();

            return;
        }

        $this->streamingTimedOutAt = now()->toIso8601String();
        $this->refreshMessages(true);
    }

    public function submitMessage(): void
    {
        $content = trim((string) $this->newMessage);
        if ($content === '') {
            return;
        }

        try {
            $this->validate();
        } catch (ValidationException $e) {
            throw $e;
        }

        $this->enforceRateLimit();

        $this->ensureThread();
        if (! $this->thread) {
            return;
        }

        // $content already computed and validated as non-empty above.

        Log::info('task-assistant.submit', [
            'layer' => 'ui',
            'thread_id' => $this->thread->id,
            'user_id' => Auth::id(),
            'content_length' => mb_strlen($content),
        ]);

        session(['task_assistant.current_thread_id' => $this->thread->id]);

        $this->newMessage = '';
        $this->cancelPreviousActiveAssistantRuns();

        // Always async: create messages then dispatch one job, which decides the flow and streams output.
        $userMessageMetadata = null;
        if (is_string($this->pendingClientActionId) && trim($this->pendingClientActionId) !== '') {
            $userMessageMetadata = [
                'client_action' => [
                    'id' => trim($this->pendingClientActionId),
                    'source' => 'fallback_option_chip',
                ],
            ];
        }
        $userMessage = $this->thread->messages()->create([
            'role' => MessageRole::User,
            'content' => $content,
            'metadata' => $userMessageMetadata,
        ]);
        $this->pendingClientActionId = null;
        $assistantMessage = $this->thread->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => '',
        ]);

        $this->loadMessages();
        $this->isStreaming = true;
        $this->streamingMessageId = $assistantMessage->id;
        $this->streamingContent = '';
        $this->showWorking = false;
        $this->streamingTimedOutAt = null;
        $correlationId = (string) Str::uuid();
        $this->streamingCorrelationId = $correlationId;
        $assistantMetadata = is_array($assistantMessage->metadata ?? null) ? $assistantMessage->metadata : [];
        data_set($assistantMetadata, 'stream.correlation_id', $correlationId);
        data_set($assistantMetadata, 'stream.phase', 'queued');
        data_set($assistantMetadata, 'stream.phase_at', now()->toIso8601String());
        $assistantMessage->update(['metadata' => $assistantMetadata]);
        $this->markThreadProcessing($assistantMessage->id);

        Log::info('task-assistant.job.dispatch', [
            'layer' => 'ui',
            'thread_id' => $this->thread->id,
            'user_message_id' => $userMessage->id,
            'assistant_message_id' => $assistantMessage->id,
            'user_id' => Auth::id(),
        ]);

        BroadcastTaskAssistantStreamJob::dispatch(
            $this->thread->id,
            $userMessage->id,
            $assistantMessage->id,
            (int) Auth::id(),
        )->onQueue((string) config('task-assistant.queue', 'task-assistant'));
    }

    public function submitNextOptionChip(int $assistantMessageId, int $chipIndex): void
    {
        if ($this->isStreaming || ! $this->thread) {
            return;
        }

        $assistantMessage = $this->thread->messages()
            ->whereKey($assistantMessageId)
            ->where('role', MessageRole::Assistant)
            ->first();

        if (! $assistantMessage) {
            return;
        }

        if ($this->latestAssistantMessageId !== null && $assistantMessageId !== $this->latestAssistantMessageId) {
            return;
        }

        $nextOptionChips = $this->resolveNextOptionChipsForMessage($assistantMessage);
        if (! array_key_exists($chipIndex, $nextOptionChips)) {
            return;
        }

        $chipActions = $this->resolveFallbackOptionActionsForMessage($assistantMessage);
        if (array_key_exists($chipIndex, $chipActions)) {
            $actionId = trim((string) data_get($chipActions[$chipIndex], 'id', ''));
            $this->pendingClientActionId = $actionId !== '' ? $actionId : null;
        } else {
            $this->pendingClientActionId = null;
        }

        $content = trim((string) $nextOptionChips[$chipIndex]);
        if ($content === '') {
            return;
        }

        $this->dismissedNextOptionChipsByMessage[$assistantMessageId] = true;
        $this->newMessage = $content;
        $this->submitMessage();
    }

    /**
     * @return array<int, string>
     */
    private function resolveNextOptionChipsForMessage(TaskAssistantMessage $assistantMessage): array
    {
        $prioritizeChips = data_get($assistantMessage->metadata, 'prioritize.next_options_chip_texts', []);
        $guidanceChips = data_get($assistantMessage->metadata, 'general_guidance.next_options_chip_texts', []);
        $scheduleChips = data_get($assistantMessage->metadata, 'schedule.next_options_chip_texts', []);
        $listingFollowupChips = data_get($assistantMessage->metadata, 'listing_followup.next_options_chip_texts', []);
        $structuredChips = data_get($assistantMessage->metadata, 'structured.data.next_options_chip_texts', []);
        $fallbackOptionActions = $this->resolveFallbackOptionActionsForMessage($assistantMessage);
        if ($fallbackOptionActions !== []) {
            return array_values(array_map(
                static fn (array $action): string => trim((string) ($action['label'] ?? '')),
                $fallbackOptionActions
            ));
        }

        $nextOptionChips = is_array($prioritizeChips) && count($prioritizeChips) > 0
            ? $prioritizeChips
            : (is_array($guidanceChips) && count($guidanceChips) > 0
                ? $guidanceChips
                : (is_array($scheduleChips) && count($scheduleChips) > 0
                    ? $scheduleChips
                    : (is_array($listingFollowupChips) && count($listingFollowupChips) > 0
                        ? $listingFollowupChips
                        : (is_array($structuredChips) ? $structuredChips : []))));

        $trimmed = array_values(array_filter(
            array_map(static fn (mixed $chip): string => trim((string) $chip), is_array($nextOptionChips) ? $nextOptionChips : []),
            static fn (string $chip): bool => $chip !== ''
        ));

        return $this->filterContinueStyleQuickChips($trimmed);
    }

    /**
     * @return array<int, array{id: string, label: string}>
     */
    private function resolveFallbackOptionActionsForMessage(TaskAssistantMessage $assistantMessage): array
    {
        $optionActions = data_get($assistantMessage->metadata, 'schedule.confirmation_context.option_actions', []);
        if (! is_array($optionActions)) {
            return [];
        }

        $normalized = [];
        foreach ($optionActions as $optionAction) {
            if (! is_array($optionAction)) {
                continue;
            }
            $id = trim((string) ($optionAction['id'] ?? ''));
            $label = trim((string) ($optionAction['label'] ?? ''));
            if ($id === '' || $label === '') {
                continue;
            }
            $normalized[] = [
                'id' => $id,
                'label' => $label,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, string>  $chips
     * @return array<int, string>
     */
    public function filterContinueStyleQuickChips(array $chips): array
    {
        return app(TaskAssistantQuickChipResolver::class)->filterContinueStyleQuickChips($chips);
    }

    public function requestStopStreaming(): void
    {
        if (! $this->thread || ! $this->isStreaming || ! $this->streamingMessageId) {
            return;
        }

        $assistantMessageId = $this->streamingMessageId;
        $metadata = is_array($this->thread->metadata ?? null) ? $this->thread->metadata : [];
        $processing = is_array(data_get($metadata, 'stream.processing'))
            ? data_get($metadata, 'stream.processing')
            : [];

        data_set($processing, 'active', true);
        data_set($processing, 'assistant_message_id', $assistantMessageId);
        data_set($processing, 'cancel_requested', true);
        data_set($processing, 'cancel_requested_at', now()->toIso8601String());
        data_set($metadata, 'stream.processing', $processing);

        $this->thread->update(['metadata' => $metadata]);
        $this->thread->refresh();

        $this->markMessageAsStopped($assistantMessageId);
        $this->clearThreadProcessingState();
        $this->isStreaming = false;
        $this->showWorking = false;
        $this->streamingContent = '';
        $this->streamingMessageId = null;
        $this->loadMessages();
    }

    private function ensureThread(): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        if ($this->thread !== null) {
            return;
        }

        $currentThreadId = (int) session('task_assistant.current_thread_id', 0);
        if ($currentThreadId > 0) {
            $this->thread = $user->taskAssistantThreads()
                ->whereKey($currentThreadId)
                ->first();
        }

        if (! $this->thread) {
            $this->thread = $user->taskAssistantThreads()->latest('id')->first();
        }

        if (! $this->thread) {
            $this->thread = $user->taskAssistantThreads()->create([
                'metadata' => [],
            ]);
        }

        session(['task_assistant.current_thread_id' => $this->thread->id]);
    }

    private function enforceRateLimit(): void
    {
        $userId = Auth::id();
        if (! $userId) {
            return;
        }

        $maxAttempts = (int) config('task-assistant.rate_limit.submissions_per_minute', 15);
        $rateLimitKey = 'task-assistant:submit:'.$userId;

        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
            Log::warning('task-assistant.ui', [
                'layer' => 'ui',
                'stage' => 'rate_limited',
                'user_id' => $userId,
                'max_attempts' => $maxAttempts,
            ]);

            throw ValidationException::withMessages([
                'newMessage' => __('You are sending messages too quickly. Please wait a minute and try again.'),
            ]);
        }

        RateLimiter::hit($rateLimitKey, 60);
    }

    private function loadMessages(): void
    {
        $this->chatMessages = $this->thread
            ? $this->thread->messages()->orderBy('id')->get()
            : collect();

        $latestAssistant = $this->chatMessages
            ->filter(fn (TaskAssistantMessage $message): bool => $message->role === MessageRole::Assistant)
            ->last();
        $this->latestAssistantMessageId = $latestAssistant?->id;
    }

    public function refreshMessages(bool $preserveStreamingState = false): void
    {
        $this->loadMessages();

        if (! $preserveStreamingState) {
            $this->isStreaming = false;
            $this->showWorking = false;
            $this->streamingContent = '';
            $this->streamingMessageId = null;
            $this->clearThreadProcessingState();
        } else {
            $this->syncStreamingStateFromPersistence();
        }

    }

    /**
     * Apply every pending schedulable proposal on the latest assistant schedule card (stop on first failure).
     */
    public function acceptAllScheduleProposals(int $assistantMessageId): void
    {
        $user = Auth::user();
        if (! $this->thread || ! $user) {
            return;
        }

        $result = app(AcceptScheduleProposalsAction::class)->execute(
            thread: $this->thread,
            user: $user,
            assistantMessageId: $assistantMessageId,
            latestAssistantMessageId: $this->latestAssistantMessageId,
        );

        $this->refreshMessages();

        if (! ($result['is_full_success'] ?? false)) {
            return;
        }

        $acceptedCount = (int) ($result['accepted_count'] ?? 0);

        $toastMessage = trans_choice(
            'Accepted :count proposal.|Accepted :count proposals.',
            $acceptedCount,
            ['count' => $acceptedCount]
        );
        $this->dispatch('toast', type: 'success', message: $toastMessage);
        $user->notify(new AssistantScheduleAcceptedNotification(
            threadId: (int) $this->thread->id,
            assistantMessageId: $assistantMessageId,
            acceptedCount: $acceptedCount,
        ));
        app(UserNotificationBroadcastService::class)->broadcastInboxUpdated($user);
        $this->dispatch('assistant-schedule-plan-updated');
    }

    /**
     * @param  array<int, array<string, mixed>>  $acceptedProposals
     */
    private function persistAcceptedSchedulePlan(
        \App\Models\User $user,
        TaskAssistantMessage $assistantMessage,
        array $acceptedProposals
    ): void {
        if ($this->thread === null || $acceptedProposals === []) {
            return;
        }

        $plan = AssistantSchedulePlan::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'thread_id' => $this->thread->id,
                'assistant_message_id' => $assistantMessage->id,
                'source' => 'assistant_accept_all',
            ],
            [
                'accepted_at' => now(),
                'metadata' => [
                    'proposal_count' => count($acceptedProposals),
                ],
            ]
        );

        foreach ($acceptedProposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }

            $proposalUuid = trim((string) ($proposal['proposal_uuid'] ?? $proposal['proposal_id'] ?? ''));
            if ($proposalUuid === '') {
                continue;
            }

            $entityType = trim((string) ($proposal['entity_type'] ?? ''));
            $entityId = (int) ($proposal['entity_id'] ?? 0);
            $title = trim((string) ($proposal['title'] ?? ''));
            $plannedStartAtRaw = trim((string) ($proposal['start_datetime'] ?? ''));

            if ($entityType === '' || $entityId <= 0 || $title === '' || $plannedStartAtRaw === '') {
                continue;
            }

            try {
                $plannedStartAt = \Carbon\CarbonImmutable::parse($plannedStartAtRaw);
            } catch (\Throwable) {
                continue;
            }

            $plannedEndAt = null;
            $plannedEndAtRaw = trim((string) ($proposal['end_datetime'] ?? ''));
            if ($plannedEndAtRaw !== '') {
                try {
                    $plannedEndAt = \Carbon\CarbonImmutable::parse($plannedEndAtRaw);
                } catch (\Throwable) {
                    $plannedEndAt = null;
                }
            }

            $plannedDurationMinutes = (int) ($proposal['duration_minutes'] ?? 0);
            if ($plannedDurationMinutes <= 0) {
                $plannedDurationMinutes = null;
            }

            $timezone = (string) config('app.timezone', 'UTC');
            $plannedDayYmd = $plannedStartAt->setTimezone($timezone)->format('Y-m-d');
            $dedupeKey = AssistantSchedulePlanItem::buildDedupeKey($user->id, $entityType, $entityId, $plannedDayYmd);

            $proposalMetadata = [
                'reason_score' => $proposal['reason_score'] ?? null,
                'apply_payload' => $proposal['apply_payload'] ?? null,
                'priority_rank' => $proposal['priority_rank'] ?? null,
            ];

            $existing = AssistantSchedulePlanItem::query()
                ->forUser($user->id)
                ->where('dedupe_key', $dedupeKey)
                ->active()
                ->first();

            $savedItem = null;
            if ($existing) {
                $mergedMetadata = is_array($existing->metadata) ? $existing->metadata : [];
                $mergedMetadata = array_merge($mergedMetadata, $proposalMetadata);
                data_set($mergedMetadata, 'proposal_last_accepted_at', now()->toIso8601String());
                data_set($mergedMetadata, 'proposal_last_uuid', $proposalUuid);

                $existing->fill([
                    'assistant_schedule_plan_id' => $plan->id,
                    'proposal_uuid' => $proposalUuid,
                    'proposal_id' => trim((string) ($proposal['proposal_id'] ?? '')) ?: null,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'title' => $title,
                    'planned_start_at' => $plannedStartAt,
                    'planned_end_at' => $plannedEndAt,
                    'planned_duration_minutes' => $plannedDurationMinutes,
                    'status' => AssistantSchedulePlanItemStatus::Planned,
                    'accepted_at' => now(),
                    'metadata' => $mergedMetadata,
                ]);
                $existing->save();
                $savedItem = $existing;
            } else {
                $savedItem = AssistantSchedulePlanItem::query()->create([
                    'assistant_schedule_plan_id' => $plan->id,
                    'user_id' => $user->id,
                    'proposal_uuid' => $proposalUuid,
                    'proposal_id' => trim((string) ($proposal['proposal_id'] ?? '')) ?: null,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'title' => $title,
                    'planned_start_at' => $plannedStartAt,
                    'planned_end_at' => $plannedEndAt,
                    'planned_duration_minutes' => $plannedDurationMinutes,
                    'status' => AssistantSchedulePlanItemStatus::Planned,
                    'accepted_at' => now(),
                    'metadata' => $proposalMetadata,
                ]);
            }

            if ($savedItem instanceof AssistantSchedulePlanItem) {
                $supersededCount = $this->dismissOlderActivePlanItemsForEntity(
                    userId: (int) $user->id,
                    entityType: $entityType,
                    entityId: $entityId,
                    keepItemId: (int) $savedItem->id,
                );
                if ($supersededCount > 0) {
                    $savedMetadata = is_array($savedItem->metadata) ? $savedItem->metadata : [];
                    data_set($savedMetadata, 'actions.last_action', 'rescheduled');
                    data_set($savedMetadata, 'actions.last_action_at', now()->toIso8601String());
                    data_set($savedMetadata, 'rescheduled_from_previous_plan_item_count', $supersededCount);
                    $savedItem->fill([
                        'metadata' => $savedMetadata,
                    ]);
                    $savedItem->save();
                }
            }
        }
    }

    private function dismissOlderActivePlanItemsForEntity(
        int $userId,
        string $entityType,
        int $entityId,
        int $keepItemId
    ): int {
        $activeItems = AssistantSchedulePlanItem::query()
            ->forUser($userId)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->active()
            ->where('id', '!=', $keepItemId)
            ->get();

        $dismissedCount = 0;
        foreach ($activeItems as $activeItem) {
            /** @var AssistantSchedulePlanItem $activeItem */
            $metadata = is_array($activeItem->metadata) ? $activeItem->metadata : [];
            data_set($metadata, 'superseded_at', now()->toIso8601String());
            data_set($metadata, 'superseded_by_plan_item_id', $keepItemId);

            $activeItem->fill([
                'status' => AssistantSchedulePlanItemStatus::Dismissed,
                'dismissed_at' => now(),
                'metadata' => $metadata,
            ]);
            $activeItem->save();
            $dismissedCount++;
        }

        return $dismissedCount;
    }

    private function applyScheduleProposal(array $proposal): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        $applyPayload = $proposal['apply_payload'] ?? null;
        if (is_array($applyPayload)) {
            $this->applyFromPayload($user, $applyPayload);

            return;
        }

        $entityType = (string) ($proposal['entity_type'] ?? '');
        $entityId = (int) ($proposal['entity_id'] ?? 0);
        $startDatetime = (string) ($proposal['start_datetime'] ?? '');
        $endDatetime = (string) ($proposal['end_datetime'] ?? '');
        $durationMinutes = (int) ($proposal['duration_minutes'] ?? 0);

        if ($entityType === 'task' && $entityId > 0 && $startDatetime !== '') {
            $task = Task::query()
                ->forUser($user->id)
                ->whereKey($entityId)
                ->first();
            if (! $task) {
                return;
            }

            $attributes = [
                'start_datetime' => $startDatetime,
            ];

            if ($durationMinutes > 0) {
                $attributes['duration'] = $durationMinutes;
            }

            app(TaskService::class)->updateTask($task, $attributes);

            return;
        }

        if ($entityType === 'event' && $entityId > 0 && $startDatetime !== '' && $endDatetime !== '') {
            $event = Event::query()
                ->forUser($user->id)
                ->whereKey($entityId)
                ->first();
            if (! $event) {
                return;
            }

            app(EventService::class)->updateEvent($event, [
                'start_datetime' => $startDatetime,
                'end_datetime' => $endDatetime,
            ]);

            return;
        }

        if ($entityType === 'project' && $entityId > 0 && $startDatetime !== '') {
            $project = Project::query()
                ->forUser($user->id)
                ->whereKey($entityId)
                ->first();
            if (! $project) {
                return;
            }

            app(ProjectService::class)->updateProject($project, [
                'start_datetime' => $startDatetime,
            ]);
        }
    }

    private function applyFromPayload(\App\Models\User $user, array $applyPayload): void
    {
        $applyAction = (string) ($applyPayload['action'] ?? $applyPayload['tool'] ?? '');
        $arguments = is_array($applyPayload['arguments'] ?? null) ? $applyPayload['arguments'] : [];
        $updates = is_array($arguments['updates'] ?? null) ? $arguments['updates'] : [];

        if ($applyAction === 'create_event') {
            app(EventService::class)->createEvent($user, [
                'title' => (string) ($arguments['title'] ?? ''),
                'description' => isset($arguments['description']) ? (string) $arguments['description'] : null,
                'start_datetime' => $arguments['startDatetime'] ?? null,
                'end_datetime' => $arguments['endDatetime'] ?? null,
            ]);

            return;
        }

        if ($applyAction === 'update_task') {
            $taskId = (int) ($arguments['taskId'] ?? 0);
            if ($taskId <= 0) {
                return;
            }
            $task = Task::query()
                ->forUser($user->id)
                ->whereKey($taskId)
                ->first();
            if (! $task) {
                return;
            }

            $attributes = [];
            foreach ($updates as $update) {
                if (! is_array($update)) {
                    continue;
                }
                $property = (string) ($update['property'] ?? '');
                $value = $update['value'] ?? null;
                if ($property === '' || $value === null) {
                    continue;
                }
                if ($property === 'startDatetime') {
                    $attributes['start_datetime'] = (string) $value;
                } elseif ($property === 'duration') {
                    $attributes['duration'] = (int) $value;
                }
            }

            if ($attributes !== []) {
                app(TaskService::class)->updateTask($task, $attributes);
            }

            return;
        }

        if ($applyAction === 'update_event') {
            $eventId = (int) ($arguments['eventId'] ?? 0);
            if ($eventId <= 0) {
                return;
            }
            $event = Event::query()
                ->forUser($user->id)
                ->whereKey($eventId)
                ->first();
            if (! $event) {
                return;
            }

            $attributes = [];
            foreach ($updates as $update) {
                if (! is_array($update)) {
                    continue;
                }
                $property = (string) ($update['property'] ?? '');
                $value = $update['value'] ?? null;
                if ($property === '' || $value === null) {
                    continue;
                }
                if ($property === 'startDatetime') {
                    $attributes['start_datetime'] = (string) $value;
                } elseif ($property === 'endDatetime') {
                    $attributes['end_datetime'] = (string) $value;
                }
            }

            if ($attributes !== []) {
                app(EventService::class)->updateEvent($event, $attributes);
            }

            return;
        }

        if ($applyAction === 'update_project') {
            $projectId = (int) ($arguments['projectId'] ?? 0);
            if ($projectId <= 0) {
                return;
            }
            $project = Project::query()
                ->forUser($user->id)
                ->whereKey($projectId)
                ->first();
            if (! $project) {
                return;
            }

            $attributes = [];
            foreach ($updates as $update) {
                if (! is_array($update)) {
                    continue;
                }
                $property = (string) ($update['property'] ?? '');
                $value = $update['value'] ?? null;
                if ($property === '' || $value === null) {
                    continue;
                }
                if ($property === 'startDatetime') {
                    $attributes['start_datetime'] = (string) $value;
                }
            }

            if ($attributes !== []) {
                app(ProjectService::class)->updateProject($project, $attributes);
            }
        }
    }

    /**
     * @return array{0: string, 1: int}|null  [dot path to proposals array, item count]
     */
    private function resolveScheduleProposalsBucket(TaskAssistantMessage $message): ?array
    {
        $metadata = is_array($message->metadata ?? null) ? $message->metadata : [];
        $normalized = app(ScheduleDraftMetadataNormalizer::class)->normalizeAndValidate($metadata);
        if (! ($normalized['valid'] ?? false)) {
            return null;
        }

        $message->update(['metadata' => $normalized['canonical_metadata']]);

        return ['schedule.proposals', count($normalized['proposals'] ?? [])];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function proposalAtIndex(TaskAssistantMessage $message, string $fullPath, int $index): ?array
    {
        $items = data_get($message->metadata ?? [], $fullPath, []);
        if (! is_array($items) || ! isset($items[$index]) || ! is_array($items[$index])) {
            return null;
        }

        return $items[$index];
    }

    /**
     * @param  array<string, mixed>  $proposal
     */
    private function isSchedulablePendingProposal(array $proposal): bool
    {
        return SchedulableProposalPolicy::isPendingSchedulable($proposal);
    }

    private function setProposalStatus(TaskAssistantMessage $message, string $path, int $index, string $status): void
    {
        $metadata = $message->metadata ?? [];
        $items = data_get($metadata, $path, []);
        if (! is_array($items) || ! isset($items[$index]) || ! is_array($items[$index])) {
            return;
        }

        $items[$index]['status'] = $status;
        data_set($metadata, $path, $items);

        TaskAssistantMessage::query()
            ->whereKey($message->id)
            ->update(['metadata' => $metadata]);
    }

    private function markThreadProcessing(int $assistantMessageId): void
    {
        if (! $this->thread) {
            return;
        }

        $metadata = is_array($this->thread->metadata ?? null) ? $this->thread->metadata : [];
        data_set($metadata, 'stream.processing', [
            'active' => true,
            'assistant_message_id' => $assistantMessageId,
            'started_at' => now()->toIso8601String(),
        ]);

        $this->thread->update(['metadata' => $metadata]);
        $this->thread->refresh();
    }

    private function clearThreadProcessingState(): void
    {
        if (! $this->thread) {
            return;
        }

        $metadata = is_array($this->thread->metadata ?? null) ? $this->thread->metadata : [];
        data_set($metadata, 'stream.processing', null);
        data_set($metadata, 'stream.last_completed_at', now()->toIso8601String());

        $this->thread->update(['metadata' => $metadata]);
        $this->thread->refresh();
    }

    private function syncStreamingStateFromPersistence(): void
    {
        if (! $this->thread) {
            return;
        }

        $processingState = data_get($this->thread->metadata, 'stream.processing');
        $processingActive = (bool) data_get($processingState, 'active', false);
        $processingMessageId = (int) data_get($processingState, 'assistant_message_id', 0);
        $cancelRequested = (bool) data_get($processingState, 'cancel_requested', false);

        if (! $processingActive || $processingMessageId <= 0 || $cancelRequested) {
            return;
        }

        /** @var TaskAssistantMessage|null $message */
        $message = $this->chatMessages->first(function ($item) use ($processingMessageId): bool {
            return $item instanceof TaskAssistantMessage && $item->id === $processingMessageId;
        });

        if (! $message || $message->role !== MessageRole::Assistant) {
            $this->clearThreadProcessingState();

            return;
        }

        if (data_get($message->metadata, 'stream.status') === 'stopped') {
            $this->clearThreadProcessingState();

            return;
        }

        $hasFinalContent = trim((string) ($message->content ?? '')) !== '';
        $isMarkedStreamed = (bool) data_get($message->metadata, 'streamed', false);
        if ($hasFinalContent || $isMarkedStreamed) {
            $this->clearThreadProcessingState();

            return;
        }

        $this->isStreaming = true;
        $this->streamingMessageId = $processingMessageId;
        $this->streamingContent = '';
        $this->showWorking = false;
    }

    private function markMessageAsStopped(int $assistantMessageId): void
    {
        if (! $this->thread) {
            return;
        }

        $message = $this->thread->messages()
            ->where('id', $assistantMessageId)
            ->where('role', MessageRole::Assistant)
            ->first();

        if (! $message) {
            return;
        }

        $metadata = is_array($message->metadata ?? null) ? $message->metadata : [];
        data_set($metadata, 'stream.status', 'stopped');
        data_set($metadata, 'stream.stopped_at', now()->toIso8601String());

        TaskAssistantMessage::query()
            ->whereKey($message->id)
            ->update([
                'content' => '',
                'metadata' => $metadata,
            ]);
    }

    private function refreshEmptyStateQuickChips(): void
    {
        $user = Auth::user();
        if (! $user) {
            $this->emptyStateQuickChips = [];

            return;
        }

        $this->emptyStateQuickChips = $this->filterContinueStyleQuickChips(
            app(TaskAssistantQuickChipResolver::class)
                ->resolveForEmptyState(
                    user: $user,
                    thread: $this->thread,
                    limit: 4,
                )
        );
    }

    private function cancelPreviousActiveAssistantRuns(): void
    {
        if (! $this->thread) {
            return;
        }

        $messages = $this->thread->messages()
            ->where('role', MessageRole::Assistant)
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        foreach ($messages as $message) {
            $metadata = is_array($message->metadata ?? null) ? $message->metadata : [];
            $isStopped = data_get($metadata, 'stream.status') === 'stopped';
            $isStreamed = (bool) data_get($metadata, 'streamed', false);
            $hasContent = trim((string) ($message->content ?? '')) !== '';

            if ($isStopped || $isStreamed || $hasContent) {
                continue;
            }

            data_set($metadata, 'stream.status', 'stopped');
            data_set($metadata, 'stream.stopped_at', now()->toIso8601String());
            TaskAssistantMessage::query()
                ->whereKey($message->id)
                ->update([
                    'content' => '',
                    'metadata' => $metadata,
                ]);
        }
    }

    private function stopActiveStreamingRun(): void
    {
        if (! $this->thread) {
            return;
        }

        $assistantMessageId = $this->streamingMessageId
            ?? (int) data_get($this->thread->metadata, 'stream.processing.assistant_message_id', 0);

        if ($assistantMessageId <= 0) {
            return;
        }

        $this->markMessageAsStopped($assistantMessageId);
        $this->clearThreadProcessingState();
        $this->isStreaming = false;
        $this->showWorking = false;
        $this->streamingContent = '';
        $this->streamingMessageId = null;
    }

};
