<?php

use App\Enums\MessageRole;
use App\Jobs\BroadcastTaskAssistantStreamJob;
use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public ?TaskAssistantThread $thread = null;

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
        $this->chatMessages = new Collection;
        $this->ensureThread();
        $this->loadMessages();
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

    public function getListeners(): array
    {
        if (! $this->thread) {
            return [];
        }

        $channel = 'task-assistant.'.$this->thread->id;

        return [
            "echo:{$channel},.text_delta" => 'appendStreamingDelta',
            "echo:{$channel},.stream_end" => 'onStreamEnd',
            "echo:{$channel},.tool_call" => 'onToolCall',
            "echo:{$channel},.tool_result" => 'onToolResult',
        ];
    }

    public function appendStreamingDelta(array $payload): void
    {
        $delta = $payload['delta'] ?? '';
        if ($delta !== '') {
            $this->streamingContent .= $delta;
        }
    }

    public function onStreamEnd(): void
    {
        $this->isStreaming = false;
        $this->showWorking = false;
        $this->streamingContent = '';
        $this->streamingMessageId = null;
        $this->refreshMessages();
    }

    public function onToolCall(): void
    {
        $this->showWorking = true;
    }

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

        $userMessage = $this->thread->messages()->create([
            'role' => MessageRole::User,
            'content' => $content,
        ]);
        $assistantMessage = $this->thread->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => '',
        ]);

        $this->newMessage = '';
        $this->loadMessages();
        $this->isStreaming = true;
        $this->streamingMessageId = $assistantMessage->id;
        $this->streamingContent = '';
        $this->showWorking = false;

        BroadcastTaskAssistantStreamJob::dispatch(
            $this->thread->id,
            $userMessage->id,
            $assistantMessage->id,
            (int) Auth::id()
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

        $this->thread = $user->taskAssistantThreads()->latest('id')->first();
        if ($this->thread === null) {
            $this->thread = $user->taskAssistantThreads()->create([
                'title' => null,
                'metadata' => [],
            ]);
        }
    }

    private function loadMessages(): void
    {
        $this->chatMessages = $this->thread
            ? $this->thread->messages()->orderBy('id')->get()
            : collect();
    }

    private function refreshMessages(): void
    {
        $this->loadMessages();
    }
};
