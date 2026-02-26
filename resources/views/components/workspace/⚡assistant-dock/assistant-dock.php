<?php

use App\Actions\Llm\GetOrCreateAssistantThreadAction;
use App\Actions\Llm\ProcessAssistantMessageAction;
use App\Models\AssistantThread;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

new class extends Component
{
    use AuthorizesRequests;

    public ?int $threadId = null;

    public string $userInput = '';

    public bool $dockOpen = false;

    public bool $isLoading = false;

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    public function mount(): void
    {
        if (! auth()->check()) {
            return;
        }
        $thread = app(GetOrCreateAssistantThreadAction::class)->execute(auth()->user(), $this->threadId);
        $this->threadId = $thread->id;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\AssistantMessage>
     */
    public function getMessagesProperty(): \Illuminate\Database\Eloquent\Collection
    {
        if ($this->threadId === null || ! auth()->check()) {
            return collect();
        }
        $thread = AssistantThread::query()
            ->forUser(auth()->id())
            ->find($this->threadId);

        return $thread !== null ? $thread->messages : collect();
    }

    public function openDock(): void
    {
        $this->dockOpen = true;
    }

    public function closeDock(): void
    {
        $this->dockOpen = false;
    }

    public function toggleDock(): void
    {
        $this->dockOpen = ! $this->dockOpen;
    }

    public function newSession(): void
    {
        $this->userInput = '';
        $this->errorMessage = null;
        $this->statusMessage = null;
        if (auth()->check()) {
            $thread = app(GetOrCreateAssistantThreadAction::class)->execute(auth()->user(), null);
            $this->threadId = $thread->id;
        } else {
            $this->threadId = null;
        }
    }

    public function sendMessage(): void
    {
        $user = auth()->user();
        if ($user === null) {
            $this->errorMessage = __('You must be signed in to use the assistant.');

            return;
        }

        $message = trim($this->userInput);
        if ($message === '') {
            return;
        }

        $this->userInput = '';
        $this->errorMessage = null;
        $this->statusMessage = __('Analyzing…');
        $this->isLoading = true;

        try {
            $assistantMessage = app(ProcessAssistantMessageAction::class)->execute($user, $message, $this->threadId);
            $this->threadId = $assistantMessage->assistant_thread_id;
        } catch (\Throwable $e) {
            $this->errorMessage = __('The assistant is unavailable right now. Please try again later.');
            report($e);
        } finally {
            $this->statusMessage = null;
            $this->isLoading = false;
        }
    }

    public function clearError(): void
    {
        $this->errorMessage = null;
    }

    public function render()
    {
        return view('components.workspace.⚡assistant-dock.assistant-dock');
    }
};
