<?php

namespace App\Services\Llm;

use App\Enums\LlmEntityType;
use App\Models\AssistantThread;

class ConversationContextBuilder
{
    /**
     * @return array<int, array{role:string,content:string}>
     */
    public function buildConversationHistory(?AssistantThread $thread): array
    {
        if (! $thread instanceof AssistantThread) {
            return [];
        }

        $limit = (int) config('tasklyst.context.conversation_history_limit', 6);
        $maxChars = (int) config('tasklyst.context.conversation_history_message_max_chars', 220);

        return $thread->lastMessages($limit)
            ->map(fn ($message): array => [
                'role' => $message->role,
                'content' => mb_substr((string) $message->content, 0, $maxChars),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildPreviousListContext(?AssistantThread $thread, LlmEntityType $entityScope, ?string $userMessage = null): ?array
    {
        if (! $thread instanceof AssistantThread) {
            return null;
        }
        if (! $this->messageExplicitlyReferencesPreviousList($userMessage)) {
            return null;
        }

        $messages = $thread->lastMessages(10);
        $lastAssistant = $messages->reverse()->first(fn ($m) => $m->role === 'assistant');
        if ($lastAssistant === null) {
            return null;
        }

        $snapshot = $lastAssistant->metadata['recommendation_snapshot'] ?? null;
        if (! is_array($snapshot)) {
            return null;
        }

        $structured = $snapshot['structured'] ?? null;
        if (! is_array($structured)) {
            return null;
        }

        $items = match ($entityScope) {
            LlmEntityType::Task => $this->extractItems($structured['ranked_tasks'] ?? [], 'title'),
            LlmEntityType::Event => $this->extractItems($structured['ranked_events'] ?? [], 'title'),
            LlmEntityType::Project => $this->extractItems($structured['ranked_projects'] ?? [], 'name'),
            LlmEntityType::Multiple => $this->extractItems($structured['listed_items'] ?? [], 'title'),
        };

        if ($items === []) {
            return null;
        }

        return [
            'entity_type' => $entityScope->value,
            'items_in_order' => $items,
            'instruction' => 'Use the previous list order strictly when user references prior ranking/list.',
        ];
    }

    private function messageExplicitlyReferencesPreviousList(?string $userMessage): bool
    {
        $normalized = mb_strtolower(trim((string) $userMessage));
        if ($normalized === '') {
            return false;
        }

        $phrases = [
            'those',
            'these',
            'that one',
            'this one',
            'next one',
            'last one',
            'that task',
            'this task',
            'next task',
            'last task',
            'second task',
            'third task',
            'that event',
            'this event',
            'next event',
            'last event',
            'second event',
            'third event',
            'that project',
            'this project',
            'next project',
            'last project',
            'second project',
            'third project',
            'top 1',
            'top 2',
            'top 3',
            'top one',
            'top task',
            'top event',
            'top project',
            'first one',
            'second one',
            'third one',
            'first task',
            'second task',
            'third task',
            'first event',
            'second event',
            'third event',
            'first project',
            'second project',
            'third project',
            'from previous list',
            'from the list',
            'that list',
            'previous list',
            'previous ranking',
            'previously ranked',
            'you listed',
            'you mentioned',
        ];

        foreach ($phrases as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return true;
            }
        }

        if (preg_match('/\b(?:rank|item|number|#)\s*[1-9]\b/u', $normalized) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @return array<int, array{position:int,title:string}>
     */
    private function extractItems(mixed $items, string $key): array
    {
        if (! is_array($items)) {
            return [];
        }

        $position = 1;
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $title = trim((string) ($item[$key] ?? ''));
            if ($title === '') {
                continue;
            }
            $out[] = [
                'position' => $position++,
                'title' => $title,
            ];
        }

        return $out;
    }
}
