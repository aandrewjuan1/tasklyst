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
     * Persist the ordered listing from prioritize for multiturn follow-ups (e.g. schedule).
     *
     * @param  array<int, array<string, mixed>>  $items  Rows with entity_type, entity_id, title (and optional extra keys)
     */
    public function rememberLastListing(
        TaskAssistantThread $thread,
        string $sourceFlow,
        array $items,
        ?int $assistantMessageId = null,
        ?int $limit = null,
    ): void {
        $state = $this->get($thread);
        $state['last_flow'] = $sourceFlow;
        if ($limit !== null) {
            $state['last_limit'] = $limit;
        }
        unset($state['selected_entities']);

        $normalized = [];
        $position = 0;
        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = (string) ($row['entity_type'] ?? '');
            $id = (int) ($row['entity_id'] ?? 0);
            $title = (string) ($row['title'] ?? '');
            if ($type === '' || $id <= 0 || trim($title) === '') {
                continue;
            }
            $normalized[] = [
                'entity_type' => $type,
                'entity_id' => $id,
                'title' => $title,
                'position' => $position,
            ];
            $position++;
        }

        $state['last_listing'] = [
            'source_flow' => $sourceFlow,
            'items' => $normalized,
            'assistant_message_id' => $assistantMessageId,
        ];
        if ($limit !== null) {
            $state['last_listing']['last_limit'] = $limit;
        }

        $this->put($thread, $state);
    }

    /**
     * @return array{
     *   source_flow: string,
     *   items: list<array{entity_type: string, entity_id: int, title: string, position: int}>,
     *   assistant_message_id?: int|null,
     *   last_limit?: int
     * }|null
     */
    public function lastListing(TaskAssistantThread $thread): ?array
    {
        $state = $this->get($thread);
        $listing = $state['last_listing'] ?? null;
        if (! is_array($listing)) {
            return null;
        }
        $items = $listing['items'] ?? [];
        if (! is_array($items) || $items === []) {
            return null;
        }

        $source = (string) ($listing['source_flow'] ?? '');
        if ($source === '') {
            return null;
        }

        $normalizedItems = $this->normalizeLastListingItems($items);
        if ($normalizedItems === []) {
            return null;
        }

        return [
            'source_flow' => $source,
            'items' => $normalizedItems,
            'assistant_message_id' => isset($listing['assistant_message_id']) ? (int) $listing['assistant_message_id'] : null,
            'last_limit' => isset($listing['last_limit']) ? (int) $listing['last_limit'] : ($state['last_limit'] ?? null),
        ];
    }

    public function clearLastListing(TaskAssistantThread $thread): void
    {
        $state = $this->get($thread);
        unset($state['last_listing'], $state['selected_entities'], $state['last_limit'], $state['prioritize_pagination']);
        $this->put($thread, $state);
    }

    /**
     * @param  array<int, array{entity_type: string, entity_id: int, title: string}>  $items
     */
    public function rememberPrioritizedItems(TaskAssistantThread $thread, array $items, int $limit): void
    {
        $this->rememberLastListing($thread, 'prioritize', $items, null, $limit);
    }

    /**
     * @param  array<int, array{entity_type: string, entity_id: int, title: string}>  $targetEntities
     * @param  list<string>  $referencedProposalUuids
     */
    public function rememberScheduleContext(TaskAssistantThread $thread, array $targetEntities, ?string $timeWindowHint, array $referencedProposalUuids = []): void
    {
        $state = $this->get($thread);
        $state['last_flow'] = 'schedule';
        $state['last_schedule'] = [
            'target_entities' => $targetEntities,
            'time_window_hint' => $timeWindowHint,
            'last_referenced_proposal_uuids' => array_values(array_filter(array_map(
                static fn (mixed $uuid): string => trim((string) $uuid),
                $referencedProposalUuids
            ), static fn (string $uuid): bool => $uuid !== '')),
        ];
        $this->put($thread, $state);
    }

    /**
     * @return list<string>
     */
    public function lastScheduleReferencedProposalUuids(TaskAssistantThread $thread): array
    {
        $state = $this->get($thread);
        $schedule = $state['last_schedule'] ?? null;
        if (! is_array($schedule)) {
            return [];
        }
        $uuids = $schedule['last_referenced_proposal_uuids'] ?? [];
        if (! is_array($uuids)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $uuid): string => trim((string) $uuid),
            $uuids
        ), static fn (string $uuid): bool => $uuid !== ''));
    }

    /**
     * Store pending general-guidance state for a follow-up reply.
     */
    public function rememberPendingGeneralGuidance(
        TaskAssistantThread $thread,
        string $initialUserMessage,
        string $clarifyingQuestion,
        array $reasonCodes = [],
    ): void {
        $state = $this->get($thread);
        $state['pending_general_guidance'] = [
            'initial_user_message' => $initialUserMessage,
            'clarifying_question' => $clarifyingQuestion,
            'reason_codes' => $reasonCodes,
        ];
        $this->put($thread, $state);
    }

    /**
     * @return array{
     *   initial_user_message: string,
     *   clarifying_question: string,
     *   reason_codes: array<int, string>
     }|null
     */
    public function pendingGeneralGuidance(TaskAssistantThread $thread): ?array
    {
        $state = $this->get($thread);
        $pending = $state['pending_general_guidance'] ?? null;

        if (! is_array($pending)) {
            return null;
        }

        $initialUserMessage = (string) ($pending['initial_user_message'] ?? '');
        $clarifyingQuestion = (string) ($pending['clarifying_question'] ?? '');
        if ($initialUserMessage === '' || $clarifyingQuestion === '') {
            return null;
        }

        $reasonCodes = is_array($pending['reason_codes'] ?? null)
            ? array_values(array_map(static fn (mixed $code): string => (string) $code, $pending['reason_codes']))
            : [];

        return [
            'initial_user_message' => $initialUserMessage,
            'clarifying_question' => $clarifyingQuestion,
            'reason_codes' => $reasonCodes,
        ];
    }

    public function clearPendingGeneralGuidance(TaskAssistantThread $thread): void
    {
        $state = $this->get($thread);
        unset($state['pending_general_guidance']);
        $this->put($thread, $state);
    }

    /**
     * Clears listing selection state (legacy name; prefer {@see clearLastListing}).
     */
    public function clearSelectedEntities(TaskAssistantThread $thread): void
    {
        $this->clearLastListing($thread);
    }

    /**
     * Entities from the latest prioritize listing, for "those / them" references.
     *
     * @return array<int, array{entity_type: string, entity_id: int, title: string}>
     */
    public function selectedEntities(TaskAssistantThread $thread): array
    {
        $state = $this->get($thread);

        // After a `schedule` flow, "those/them" should resolve against the
        // schedule-selected targets (not the prior prioritize listing).
        $lastFlow = (string) ($state['last_flow'] ?? '');
        if ($lastFlow === 'schedule') {
            $schedule = $state['last_schedule'] ?? null;
            $targets = is_array($schedule) && is_array($schedule['target_entities'] ?? null)
                ? $schedule['target_entities']
                : [];

            if ($targets !== []) {
                return $this->normalizeReferenceEntities($targets);
            }
        }

        $listing = $this->lastListing($thread);
        if ($listing !== null) {
            return $this->normalizeReferenceEntities($listing['items']);
        }

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

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{entity_type: string, entity_id: int, title: string}>
     */
    private function normalizeReferenceEntities(array $items): array
    {
        $out = [];

        foreach ($items as $entity) {
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

    /**
     * @param  array<int, mixed>  $items
     * @return list<array{entity_type: string, entity_id: int, title: string, position: int}>
     */
    private function normalizeLastListingItems(array $items): array
    {
        $out = [];
        $position = 0;
        foreach ($items as $entity) {
            if (! is_array($entity)) {
                continue;
            }
            $type = (string) ($entity['entity_type'] ?? '');
            $id = (int) ($entity['entity_id'] ?? 0);
            $title = (string) ($entity['title'] ?? '');
            if ($type === '' || $id <= 0 || trim($title) === '') {
                continue;
            }
            $pos = isset($entity['position']) && is_numeric($entity['position'])
                ? (int) $entity['position']
                : $position;
            $out[] = [
                'entity_type' => $type,
                'entity_id' => $id,
                'title' => $title,
                'position' => $pos,
            ];
            $position++;
        }

        usort($out, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return array_values($out);
    }
}
