<?php

namespace App\Services\LLM\TaskAssistant;

use App\Models\TaskAssistantThread;

final class TaskAssistantConversationStateService
{
    /**
     * @return array<string, mixed>
     */
    public function get(TaskAssistantThread $thread): array
    {
        $metadata = $thread->metadata ?? [];
        $state = $metadata['conversation_state'] ?? [];

        return is_array($state) ? $state : [];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function put(TaskAssistantThread $thread, array $state): void
    {
        $metadata = $thread->metadata ?? [];
        $metadata['conversation_state'] = $state;
        $thread->update(['metadata' => $metadata]);
    }

    /**
     * @param  array<int, array{entity_type: string, entity_id: int, title: string}>  $items
     */
    public function rememberPrioritizedItems(TaskAssistantThread $thread, array $items, int $limit): void
    {
        $state = $this->get($thread);
        $state['last_flow'] = 'prioritize';
        $state['last_limit'] = $limit;
        $state['selected_entities'] = $items;
        $this->put($thread, $state);
    }

    /**
     * @param  array<int, array{entity_type: string, entity_id: int, title: string}>  $targetEntities
     */
    public function rememberScheduleContext(TaskAssistantThread $thread, array $targetEntities, ?string $timeWindowHint): void
    {
        $state = $this->get($thread);
        $state['last_flow'] = 'schedule';
        $state['last_schedule'] = [
            'target_entities' => $targetEntities,
            'time_window_hint' => $timeWindowHint,
        ];
        $this->put($thread, $state);
    }

    /**
     * @return array<int, array{entity_type: string, entity_id: int, title: string}>
     */
    public function selectedEntities(TaskAssistantThread $thread): array
    {
        $state = $this->get($thread);
        $selected = $state['selected_entities'] ?? [];

        if (! is_array($selected)) {
            return [];
        }

        $out = [];
        foreach ($selected as $entity) {
            if (! is_array($entity)) {
                continue;
            }
            $type = (string) ($entity['entity_type'] ?? '');
            $id = (int) ($entity['entity_id'] ?? 0);
            $title = (string) ($entity['title'] ?? '');

            if ($type === '' || $id <= 0 || trim($title) === '') {
                continue;
            }

            $out[] = [
                'entity_type' => $type,
                'entity_id' => $id,
                'title' => $title,
            ];
        }

        return $out;
    }
}
