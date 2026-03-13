<?php

namespace App\Console\Commands;

use App\Enums\ChatMessageRole;
use App\Events\LlmResponseReady;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\User;
use App\Services\Llm\LlmChatService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class LlmChatCommand extends Command
{
    /**
     * This is a debug/diagnostic tool for exercising the LLM backend from the CLI.
     */
    protected $signature = 'llm:chat {--user-id=} {--user-email=} {--thread-id=}';

    protected $description = 'Interactive CLI chat against the LLM assistant backend.';

    public function handle(): int
    {
        $user = $this->resolveUser();

        if (! $user instanceof User) {
            $this->error('Unable to resolve a user. Provide --user-id or --user-email, or create a user first.');

            return self::FAILURE;
        }

        $thread = $this->resolveThread($user);

        if (! $thread instanceof ChatThread) {
            $this->error('Unable to resolve or create a chat thread for this user.');

            return self::FAILURE;
        }

        $this->info('LLM CLI chat');
        $this->line('User:    '.$user->email.' (ID '.$user->id.')');
        $this->line('Thread:  '.$thread->title.' (ID '.$thread->id.')');
        $this->line('Schema:  '.config('llm.schema_version'));
        $this->line('Model:   '.config('llm.model'));
        $this->line('');
        $this->line('Type your message and press enter. Type ":q" or "exit" to quit.');
        $this->line('');

        /** @var LlmChatService $service */
        $service = app(LlmChatService::class);

        while (true) {
            $prompt = $this->ask('You');

            if ($prompt === null) {
                break;
            }

            $trimmed = trim($prompt);

            if ($trimmed === '' || in_array(strtolower($trimmed), ['exit', 'quit', ':q'], true)) {
                break;
            }

            $traceId = (string) Str::uuid();
            $clientRequestId = (string) Str::uuid();

            ChatMessage::query()->create([
                'thread_id' => $thread->id,
                'role' => ChatMessageRole::User,
                'author_id' => $user->id,
                'content_text' => $trimmed,
                'content_json' => null,
                'llm_raw' => null,
                'meta' => [
                    'trace_id' => $traceId,
                    'source' => 'cli',
                ],
                'client_request_id' => $clientRequestId,
            ]);

            try {
                $recommendation = $service->handle($user, (string) $thread->id, $trimmed, $traceId);

                event(new LlmResponseReady(
                    userId: $user->id,
                    threadId: $thread->id,
                ));
            } catch (\Throwable $e) {
                $this->error('[ERROR] '.$e->getMessage());

                continue;
            }

            $prefix = $recommendation->isError ? '[Assistant: error] ' : 'Assistant: ';
            $this->line($prefix.$recommendation->primaryMessage);

            $this->line('  trace_id: '.$recommendation->traceId);
            $this->line('');
        }

        $this->line('Goodbye.');

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        $userId = $this->option('user-id');
        $email = $this->option('user-email');

        if ($userId !== null) {
            return User::query()->find((int) $userId);
        }

        if (is_string($email) && $email !== '') {
            return User::query()->where('email', $email)->first();
        }

        $defaultEmail = 'andrew.juan.cvt@eac.edu.ph';

        return User::query()->where('email', $defaultEmail)->first()
            ?? User::query()->first();
    }

    private function resolveThread(User $user): ?ChatThread
    {
        $threadId = $this->option('thread-id');

        if ($threadId !== null) {
            return ChatThread::query()
                ->where('user_id', $user->id)
                ->find((int) $threadId);
        }

        $existing = ChatThread::query()
            ->where('user_id', $user->id)
            ->where('title', 'CLI Test Thread')
            ->orderByDesc('updated_at')
            ->first();

        if ($existing instanceof ChatThread) {
            return $existing;
        }

        return ChatThread::query()->create([
            'user_id' => $user->id,
            'title' => 'CLI Test Thread',
            'schema_version' => config('llm.schema_version'),
            'metadata' => [
                'source' => 'cli',
            ],
        ]);
    }
}
