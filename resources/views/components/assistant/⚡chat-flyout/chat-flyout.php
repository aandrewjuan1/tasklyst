<?php

use App\Enums\MessageRole;
use App\Enums\TaskAssistantIntent;
use App\Jobs\BroadcastTaskAssistantStreamJob;
use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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

    #[On('echo:task-assistant.user.{userId},.json_delta')]
    public function appendStreamingDelta(array $payload): void
    {
        $delta = $payload['delta'] ?? '';
        if ($delta !== '') {
            $this->streamingContent .= $delta;
        }
    }

    #[On('echo:task-assistant.user.{userId},.stream_end')]
    public function onStreamEnd(): void
    {
        $this->refreshMessages();
    }

    #[On('echo:task-assistant.user.{userId},.tool_call')]
    public function onToolCall(): void
    {
        $this->showWorking = true;
    }

    #[On('echo:task-assistant.user.{userId},.tool_result')]
    public function onToolResult(): void
    {
        $this->showWorking = false;
    }

    public function submitMessage(): void
    {
        $this->validate();

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
            'content' => $content,
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

        // Detect intent based on user message content
        $intent = $this->detectIntent($content);

        Log::info('task-assistant.job.dispatch', [
            'thread_id' => $this->thread->id,
            'user_message_id' => $userMessage->id,
            'assistant_message_id' => $assistantMessage->id,
            'user_id' => Auth::id(),
            'intent' => $intent->value,
        ]);

        BroadcastTaskAssistantStreamJob::dispatch(
            $this->thread->id,
            $userMessage->id,
            $assistantMessage->id,
            (int) Auth::id(),
            $intent
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

    /**
     * Detect the appropriate intent based on user message content.
     */
    private function detectIntent(string $content): TaskAssistantIntent
    {
        $intentService = new \App\Services\LLM\Intent\IntentClassificationService();
        return $intentService->classify($content);
    }

};
