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
            $this->line('Processing (this may take up to 90 seconds)...');

            $assistantMessage = $this->waitForAssistantReply($thread->id, $resultMessage->id);

            if ($assistantMessage === null) {
                $this->line('No reply yet. If this persists, ensure a queue worker is running: php artisan queue:work --queue=llm,default');
                $this->newLine();

                continue;
            }

            $this->line($assistantMessage->content);

            $metadata = $assistantMessage->metadata ?? [];
            $snapshot = $metadata['recommendation_snapshot'] ?? null;

            if (is_array($snapshot) && $snapshot !== []) {
                $this->newLine();
                $this->displayStructuredResponse($snapshot);
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

    /**
     * Display backend structured response (intent, entity, confidence, structured payload) for testing.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function displayStructuredResponse(array $snapshot): void
    {
        $this->comment('--- Backend structured response ---');

        $intent = $snapshot['intent'] ?? null;
        $entityType = $snapshot['entity_type'] ?? null;
        if ($intent !== null || $entityType !== null) {
            $this->line(sprintf('  Intent: %s  |  Entity type: %s', $intent ?? '—', $entityType ?? '—'));
        }

        $validationConfidence = $snapshot['validation_confidence'] ?? null;
        if ($validationConfidence !== null) {
            $this->line(sprintf('  Validation confidence: %s', is_numeric($validationConfidence) ? round((float) $validationConfidence, 2) : $validationConfidence));
        }

        $usedFallback = $snapshot['used_fallback'] ?? false;
        $fallbackReason = $snapshot['fallback_reason'] ?? null;
        $this->line(sprintf('  Used fallback: %s%s', $usedFallback ? 'yes' : 'no', $fallbackReason ? ' ('.$fallbackReason.')' : ''));

        $structured = $snapshot['structured'] ?? [];
        if (is_array($structured) && $structured !== []) {
            $this->newLine();
            $this->line('  <fg=gray>Structured payload:</>');
            $json = json_encode($structured, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            foreach (explode("\n", $json) as $line) {
                $this->line('    '.$line);
            }
        }

        $rankedTasks = $snapshot['structured']['ranked_tasks'] ?? null;
        $rankedEvents = $snapshot['structured']['ranked_events'] ?? null;
        $rankedProjects = $snapshot['structured']['ranked_projects'] ?? null;
        if (is_array($rankedTasks) && $rankedTasks !== []) {
            $this->newLine();
            $this->line('  <fg=gray>Ranked tasks:</>');
            foreach ($rankedTasks as $item) {
                $rank = $item['rank'] ?? '?';
                $title = $item['title'] ?? '';
                $end = $item['end_datetime'] ?? '';
                $this->line(sprintf('    #%s %s %s', $rank, $title, $end ? '('.$end.')' : ''));
            }
        }
        if (is_array($rankedEvents) && $rankedEvents !== []) {
            $this->newLine();
            $this->line('  <fg=gray>Ranked events:</>');
            foreach ($rankedEvents as $item) {
                $rank = $item['rank'] ?? '?';
                $title = $item['title'] ?? '';
                $this->line(sprintf('    #%s %s', $rank, $title));
            }
        }
        if (is_array($rankedProjects) && $rankedProjects !== []) {
            $this->newLine();
            $this->line('  <fg=gray>Ranked projects:</>');
            foreach ($rankedProjects as $item) {
                $rank = $item['rank'] ?? '?';
                $name = $item['name'] ?? '';
                $this->line(sprintf('    #%s %s', $rank, $name));
            }
        }

        $listedItems = $snapshot['structured']['listed_items'] ?? null;
        if (is_array($listedItems) && $listedItems !== []) {
            $this->newLine();
            $this->line('  <fg=gray>Listed items (filter/list response):</>');
            foreach ($listedItems as $item) {
                $title = $item['title'] ?? '';
                $priority = $item['priority'] ?? null;
                $end = $item['end_datetime'] ?? null;
                $extra = array_filter([$priority, $end], fn ($v) => $v !== null && $v !== '');
                $this->line('    • '.$title.($extra !== [] ? ' ('.implode(', ', $extra).')' : ''));
            }
        }

        $recommendedAction = $snapshot['recommended_action'] ?? null;
        $reasoning = $snapshot['reasoning'] ?? null;
        if ($recommendedAction !== null && $recommendedAction !== '' && $this->output->isVerbose()) {
            $this->newLine();
            $this->line('  <fg=gray>recommended_action (raw):</> '.$recommendedAction);
        }
        if ($reasoning !== null && $reasoning !== '' && $this->output->isVerbose()) {
            $this->line('  <fg=gray>reasoning (raw):</> '.$reasoning);
        }

        $this->comment('-----------------------------------');
    }

    private function waitForAssistantReply(int $threadId, int $afterMessageId): ?\App\Models\AssistantMessage
    {
        $llmTimeout = (int) config('tasklyst.llm.timeout', 25);
        $maxAttempts = max(1, (int) config('tasklyst.llm.max_attempts', 1));
        $timeoutSeconds = ($llmTimeout * $maxAttempts) + 30;
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
