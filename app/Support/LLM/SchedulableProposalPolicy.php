<?php

namespace App\Support\LLM;

final class SchedulableProposalPolicy
{
    public const NO_SCHEDULABLE_ITEMS_TITLE = 'No schedulable items found';

    /**
     * @param  array<string, mixed>  $proposal
     */
    public static function isPendingSchedulable(array $proposal): bool
    {
        if (($proposal['status'] ?? 'pending') !== 'pending') {
            return false;
        }
        if (trim((string) ($proposal['title'] ?? '')) === self::NO_SCHEDULABLE_ITEMS_TITLE) {
            return false;
        }

        $payload = $proposal['apply_payload'] ?? null;
        if (is_array($payload) && $payload !== []) {
            return true;
        }

        $entityType = (string) ($proposal['entity_type'] ?? '');
        $entityId = (int) ($proposal['entity_id'] ?? 0);
        $start = trim((string) ($proposal['start_datetime'] ?? ''));
        $end = trim((string) ($proposal['end_datetime'] ?? ''));

        if ($entityType === 'task' && $entityId > 0 && $start !== '') {
            return true;
        }
        if ($entityType === 'event' && $entityId > 0 && $start !== '' && $end !== '') {
            return true;
        }

        return $entityType === 'project' && $entityId > 0 && $start !== '';
    }

    /**
     * @param  list<array<string, mixed>>  $proposals
     * @return list<string>
     */
    public static function referencedPendingUuids(array $proposals): array
    {
        return array_values(array_filter(array_map(
            static function (mixed $proposal): string {
                if (! is_array($proposal)) {
                    return '';
                }

                $uuid = trim((string) ($proposal['proposal_uuid'] ?? $proposal['proposal_id'] ?? ''));
                if ($uuid === '') {
                    return '';
                }

                return self::isPendingSchedulable($proposal) ? $uuid : '';
            },
            $proposals
        ), static fn (string $uuid): bool => $uuid !== ''));
    }
}
