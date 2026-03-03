<?php

use App\Actions\Llm\ProcessAssistantMessageAction;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\User;
use App\Services\AssistantConversationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public ?int $threadId = null;

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

        if ($this->messages !== []) {
            $last = end($this->messages);
            if (is_array($last) && ($last['role'] ?? null) === 'user') {
                $this->pendingAssistantCount = 1;
            }
            reset($this->messages);
        }
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
};
