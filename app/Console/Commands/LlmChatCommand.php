<?php

namespace App\Console\Commands;

use App\Actions\Llm\GetOrCreateAssistantThreadAction;
use App\Actions\Llm\ProcessAssistantMessageAction;
use App\Models\User;
use Illuminate\Console\Command;

class LlmChatCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'llm:chat {userId? : ID of the user to chat as}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Interactive terminal chat interface for the TaskLyst LLM backend (Hermes 3 pipeline).';

    public function __construct(
        private GetOrCreateAssistantThreadAction $getOrCreateAssistantThread,
        private ProcessAssistantMessageAction $processAssistantMessage,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $user = $this->resolveUser();
        if ($user === null) {
            return self::FAILURE;
        }

        $thread = $this->getOrCreateAssistantThread->execute($user, null);

        $this->info('TaskLyst LLM console chat');
        $this->line('User: '.$user->email.' (ID: '.$user->id.')');
        $this->line('Thread ID: '.$thread->id);
        $this->newLine();
        $this->line('Type your message and press Enter. Type "exit" or "quit" to leave.');
        $this->newLine();

        while (true) {
            $input = $this->ask('You');

            if ($input === null) {
                continue;
            }

            $trimmed = trim($input);

            if ($trimmed === '') {
                continue;
            }

            $lower = mb_strtolower($trimmed);
            if (in_array($lower, ['exit', 'quit', 'q'], true)) {
                $this->newLine();
                $this->line('Goodbye.');

                break;
            }

            try {
                $resultMessage = $this->processAssistantMessage->execute(
                    $user,
                    $trimmed,
                    $thread->id,
                );
            } catch (\Throwable $e) {
                $this->newLine();
                $this->error('Error while talking to the assistant: '.$e->getMessage());

                continue;
            }

            $this->newLine();
            if ($resultMessage->isAssistant()) {
                $this->info('Assistant:');
                $this->line($resultMessage->content);
                $this->newLine();

                continue;
            }

            $this->info('Assistant:');
            $this->line('[queued] Waiting for the background job to reply...');

            $assistantMessage = $this->waitForAssistantReply($thread->id, $resultMessage->id);

            if ($assistantMessage === null) {
                $this->line('[no reply yet] Make sure a queue worker is running (e.g. `php artisan queue:work`).');
                $this->newLine();

                continue;
            }

            $this->line($assistantMessage->content);

            $metadata = $assistantMessage->metadata ?? [];
            $snapshot = $metadata['recommendation_snapshot'] ?? null;

            if (is_array($snapshot) && $snapshot !== []) {
                $reasoning = $snapshot['reasoning'] ?? null;

                if (is_string($reasoning) && $reasoning !== '') {
                    $this->newLine();
                    $this->line('Reasoning:');
                    $this->line($reasoning);
                }

                $this->newLine();
                $this->comment('Recommendation snapshot (backend view):');
                $this->line(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        $userId = $this->argument('userId');

        if ($userId !== null) {
            /** @var User|null $user */
            $user = User::query()->find($userId);

            if ($user === null) {
                $this->error('User with ID '.$userId.' not found.');

                return null;
            }

            return $user;
        }

        /** @var User|null $user */
        $user = User::query()->orderBy('id')->first();

        if ($user === null) {
            $this->error('No users found in the database. Seed or create a user first.');

            return null;
        }

        return $user;
    }

    private function waitForAssistantReply(int $threadId, int $afterMessageId): ?\App\Models\AssistantMessage
    {
        $timeoutSeconds = 20;
        $startedAt = time();

        while ((time() - $startedAt) < $timeoutSeconds) {
            $message = \App\Models\AssistantMessage::query()
                ->where('assistant_thread_id', $threadId)
                ->where('role', 'assistant')
                ->where('id', '>', $afterMessageId)
                ->orderByDesc('id')
                ->first();

            if ($message !== null) {
                return $message;
            }

            sleep(1);
        }

        return null;
    }
}
