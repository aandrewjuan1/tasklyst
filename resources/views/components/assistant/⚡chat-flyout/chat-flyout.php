<?php

use App\Enums\MessageRole;
use App\Jobs\BroadcastTaskAssistantStreamJob;
use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;
use App\Tools\LLM\TaskAssistant\UpdateEventTool;
use App\Tools\LLM\TaskAssistant\UpdateProjectTool;
use App\Tools\LLM\TaskAssistant\UpdateTaskTool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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

    public function mount(): void
    {
        $this->userId = Auth::id();
        $this->chatMessages = new Collection;
        $this->ensureThread();
        $this->loadMessages();
        $this->syncStreamingStateFromPersistence();
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
                'title' => null,
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

    #[On('echo-private:task-assistant.user.{userId},.tool_call')]
    public function onToolCall(): void
    {
        $this->showWorking = true;
    }

    #[On('echo-private:task-assistant.user.{userId},.tool_result')]
    public function onToolResult(): void
    {
        $this->showWorking = false;
    }

    public function submitMessage(): void
    {
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

        $content = trim($this->newMessage);
        if ($content === '') {
            return;
        }

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
        $userMessage = $this->thread->messages()->create([
            'role' => MessageRole::User,
            'content' => $content,
        ]);
        $assistantMessage = $this->thread->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => '',
        ]);

        $this->loadMessages();
        $this->isStreaming = true;
        $this->streamingMessageId = $assistantMessage->id;
        $this->streamingContent = '';
        $this->showWorking = false;
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

    public function submitNextOptionChip(int $assistantMessageId, string $chipText): void
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

        $content = trim($chipText);
        if ($content === '') {
            return;
        }

        $this->dismissedNextOptionChipsByMessage[$assistantMessageId] = true;
        $this->newMessage = $content;
        $this->submitMessage();
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
                'title' => null,
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

    public function acceptScheduleProposalItem(int $assistantMessageId, string $proposalId): void
    {
        if (! $this->thread) {
            return;
        }

        /** @var TaskAssistantMessage|null $message */
        /** @var TaskAssistantMessage|null $message */
        $message = $this->thread->messages()
            ->where('id', $assistantMessageId)
            ->where('role', MessageRole::Assistant)
            ->first();

        if (! $message) {
            return;
        }

        [$proposal, $index, $path] = $this->findProposal($message, $proposalId);
        if ($proposal === null || $index === null || $path === null) {
            return;
        }

        try {
            $this->applyScheduleProposal($proposal);
            $this->setProposalStatus($message, $path, $index, 'accepted');
        } catch (\Throwable $e) {
            Log::warning('task-assistant.proposal.accept_failed', [
                'layer' => 'ui',
                'message_id' => $assistantMessageId,
                'proposal_id' => $proposalId,
                'error' => $e->getMessage(),
            ]);
            $this->setProposalStatus($message, $path, $index, 'failed');
        }

        $this->refreshMessages();
    }

    public function declineScheduleProposalItem(int $assistantMessageId, string $proposalId): void
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

        [, $index, $path] = $this->findProposal($message, $proposalId);
        if ($index === null || $path === null) {
            return;
        }

        $this->setProposalStatus($message, $path, $index, 'declined');
        $this->refreshMessages();
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
            /** @var UpdateTaskTool $tool */
            $tool = app()->make(UpdateTaskTool::class, ['user' => $user]);
            $tool(['taskId' => $entityId, 'property' => 'startDatetime', 'value' => $startDatetime]);

            if ($durationMinutes > 0) {
                $tool(['taskId' => $entityId, 'property' => 'duration', 'value' => (string) $durationMinutes]);
            }

            return;
        }

        if ($entityType === 'event' && $entityId > 0 && $startDatetime !== '' && $endDatetime !== '') {
            /** @var UpdateEventTool $tool */
            $tool = app()->make(UpdateEventTool::class, ['user' => $user]);
            $tool(['eventId' => $entityId, 'property' => 'startDatetime', 'value' => $startDatetime]);
            $tool(['eventId' => $entityId, 'property' => 'endDatetime', 'value' => $endDatetime]);

            return;
        }

        if ($entityType === 'project' && $entityId > 0 && $startDatetime !== '') {
            /** @var UpdateProjectTool $tool */
            $tool = app()->make(UpdateProjectTool::class, ['user' => $user]);
            $tool(['projectId' => $entityId, 'property' => 'startDatetime', 'value' => $startDatetime]);
        }
    }

    private function applyFromPayload(\App\Models\User $user, array $applyPayload): void
    {
        $toolName = (string) ($applyPayload['tool'] ?? '');
        $arguments = is_array($applyPayload['arguments'] ?? null) ? $applyPayload['arguments'] : [];
        $updates = is_array($arguments['updates'] ?? null) ? $arguments['updates'] : [];

        if ($toolName === 'update_task') {
            $taskId = (int) ($arguments['taskId'] ?? 0);
            if ($taskId <= 0) {
                return;
            }
            /** @var UpdateTaskTool $tool */
            $tool = app()->make(UpdateTaskTool::class, ['user' => $user]);
            foreach ($updates as $update) {
                if (! is_array($update)) {
                    continue;
                }
                $property = (string) ($update['property'] ?? '');
                $value = $update['value'] ?? null;
                if ($property === '' || $value === null) {
                    continue;
                }
                $tool(['taskId' => $taskId, 'property' => $property, 'value' => (string) $value]);
            }

            return;
        }

        if ($toolName === 'update_event') {
            $eventId = (int) ($arguments['eventId'] ?? 0);
            if ($eventId <= 0) {
                return;
            }
            /** @var UpdateEventTool $tool */
            $tool = app()->make(UpdateEventTool::class, ['user' => $user]);
            foreach ($updates as $update) {
                if (! is_array($update)) {
                    continue;
                }
                $property = (string) ($update['property'] ?? '');
                $value = $update['value'] ?? null;
                if ($property === '' || $value === null) {
                    continue;
                }
                $tool(['eventId' => $eventId, 'property' => $property, 'value' => (string) $value]);
            }

            return;
        }

        if ($toolName === 'update_project') {
            $projectId = (int) ($arguments['projectId'] ?? 0);
            if ($projectId <= 0) {
                return;
            }
            /** @var UpdateProjectTool $tool */
            $tool = app()->make(UpdateProjectTool::class, ['user' => $user]);
            foreach ($updates as $update) {
                if (! is_array($update)) {
                    continue;
                }
                $property = (string) ($update['property'] ?? '');
                $value = $update['value'] ?? null;
                if ($property === '' || $value === null) {
                    continue;
                }
                $tool(['projectId' => $projectId, 'property' => $property, 'value' => (string) $value]);
            }
        }
    }

    private function findProposal(TaskAssistantMessage $message, string $proposalId): array
    {
        $metadata = $message->metadata ?? [];
        $candidates = [
            ['key' => 'schedule', 'items_key' => 'proposals'],
            ['key' => 'daily_schedule', 'items_key' => 'proposals'],
            ['key' => 'structured', 'items_key' => 'data.proposals'],
        ];

        foreach ($candidates as $candidate) {
            $path = $candidate['items_key'];
            $items = data_get($metadata[$candidate['key']] ?? null, $path, []);
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                if ((string) ($item['proposal_id'] ?? '') === $proposalId) {
                    return [$item, $index, $candidate['key'].'.'.$path];
                }
            }
        }

        return [null, null, null];
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
