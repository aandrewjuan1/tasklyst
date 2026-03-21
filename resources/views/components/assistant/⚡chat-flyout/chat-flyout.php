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

    public bool $showWorking = false;

    public function mount(): void
    {
        $this->userId = Auth::id();
        $this->chatMessages = new Collection;
        $this->ensureThread();
        $this->loadMessages();
    }

    public function startNewChat(): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

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
        $delta = $payload['delta'] ?? '';
        if ($delta !== '') {
            $this->streamingContent .= $delta;
        }
    }

    #[On('echo-private:task-assistant.user.{userId},.stream_end')]
    public function onStreamEnd(): void
    {
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
        $this->validate();
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
            'thread_id' => $this->thread->id,
            'user_id' => Auth::id(),
            'content_length' => mb_strlen($content),
        ]);

        session(['task_assistant.current_thread_id' => $this->thread->id]);

        $this->newMessage = '';

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

        Log::info('task-assistant.job.dispatch', [
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
        );
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
    }

    public function refreshMessages(): void
    {
        $this->loadMessages();
        $this->isStreaming = false;
        $this->showWorking = false;
        $this->streamingContent = '';
        $this->streamingMessageId = null;
    }

    public function acceptScheduleProposalItem(int $assistantMessageId, string $proposalId): void
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

        [$proposal, $index, $path] = $this->findProposal($message, $proposalId);
        if ($proposal === null || $index === null || $path === null) {
            return;
        }

        try {
            $this->applyScheduleProposal($proposal);
            $this->setProposalStatus($message, $path, $index, 'accepted');
        } catch (\Throwable $e) {
            Log::warning('task-assistant.proposal.accept_failed', [
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

        $message->update(['metadata' => $metadata]);
    }

};
