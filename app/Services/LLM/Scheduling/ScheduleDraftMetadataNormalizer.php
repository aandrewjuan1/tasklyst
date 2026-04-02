<?php

namespace App\Services\LLM\Scheduling;

use Illuminate\Support\Str;

final class ScheduleDraftMetadataNormalizer
{
    public const SCHEMA_VERSION = 2;

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{
     *   valid: bool,
     *   reason_code: string|null,
     *   reason_message: string|null,
     *   canonical_data: array<string, mixed>|null,
     *   canonical_metadata: array<string, mixed>,
     *   proposals: list<array<string, mixed>>,
     *   repairs: list<string>
     * }
     */
    public function normalizeAndValidate(array $metadata): array
    {
        $repairs = [];
        $schedule = $this->extractRawSchedule($metadata);

        if (! is_array($schedule)) {
            return [
                'valid' => false,
                'reason_code' => 'missing_schedule_payload',
                'reason_message' => 'No schedule payload was found.',
                'canonical_data' => null,
                'canonical_metadata' => $metadata,
                'proposals' => [],
                'repairs' => [],
            ];
        }

        $schemaVersion = (int) ($schedule['schema_version'] ?? 0);
        if ($schemaVersion !== self::SCHEMA_VERSION) {
            $repairs[] = 'schema_version_normalized';
            $schemaVersion = self::SCHEMA_VERSION;
        }

        $proposalsRaw = $schedule['proposals'] ?? null;
        if (! is_array($proposalsRaw) || $proposalsRaw === []) {
            return [
                'valid' => false,
                'reason_code' => 'missing_proposals',
                'reason_message' => 'No schedule proposals were found.',
                'canonical_data' => null,
                'canonical_metadata' => $metadata,
                'proposals' => [],
                'repairs' => $repairs,
            ];
        }

        $proposals = [];
        foreach (array_values($proposalsRaw) as $index => $proposal) {
            if (! is_array($proposal)) {
                continue;
            }
            $normalized = $this->normalizeProposal($proposal, $index, $repairs);
            $proposals[] = $normalized;
        }

        if ($proposals === []) {
            return [
                'valid' => false,
                'reason_code' => 'invalid_item_shape',
                'reason_message' => 'Schedule proposals are malformed.',
                'canonical_data' => null,
                'canonical_metadata' => $metadata,
                'proposals' => [],
                'repairs' => $repairs,
            ];
        }

        $schedule['schema_version'] = $schemaVersion;
        $schedule['proposals'] = $proposals;

        if (! isset($schedule['items']) || ! is_array($schedule['items'])) {
            $schedule['items'] = [];
            $repairs[] = 'items_defaulted';
        }
        if (! isset($schedule['blocks']) || ! is_array($schedule['blocks'])) {
            $schedule['blocks'] = [];
            $repairs[] = 'blocks_defaulted';
        }
        if (! isset($schedule['confirmation_required']) || ! is_bool($schedule['confirmation_required'])) {
            $schedule['confirmation_required'] = false;
            $repairs[] = 'confirmation_required_defaulted';
        }
        if (! isset($schedule['awaiting_user_decision']) || ! is_bool($schedule['awaiting_user_decision'])) {
            $schedule['awaiting_user_decision'] = false;
            $repairs[] = 'awaiting_user_decision_defaulted';
        }
        if (! array_key_exists('confirmation_context', $schedule)) {
            $schedule['confirmation_context'] = null;
            $repairs[] = 'confirmation_context_defaulted';
        }
        if (! array_key_exists('fallback_preview', $schedule)) {
            $schedule['fallback_preview'] = null;
            $repairs[] = 'fallback_preview_defaulted';
        }

        $canonicalMetadata = $metadata;
        $canonicalMetadata['schedule'] = $schedule;
        $canonicalMetadata['structured'] = array_merge(
            is_array($canonicalMetadata['structured'] ?? null) ? $canonicalMetadata['structured'] : [],
            ['data' => $schedule]
        );
        if (isset($canonicalMetadata['daily_schedule']) && is_array($canonicalMetadata['daily_schedule'])) {
            $canonicalMetadata['daily_schedule'] = $schedule;
        }

        return [
            'valid' => true,
            'reason_code' => null,
            'reason_message' => null,
            'canonical_data' => $schedule,
            'canonical_metadata' => $canonicalMetadata,
            'proposals' => $proposals,
            'repairs' => array_values(array_unique($repairs)),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>|null
     */
    private function extractRawSchedule(array $metadata): ?array
    {
        $candidates = [
            $metadata['schedule'] ?? null,
            $metadata['daily_schedule'] ?? null,
            data_get($metadata, 'structured.data'),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && is_array($candidate['proposals'] ?? null)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  list<string>  $repairs
     * @return array<string, mixed>
     */
    private function normalizeProposal(array $proposal, int $index, array &$repairs): array
    {
        $uuid = trim((string) ($proposal['proposal_uuid'] ?? ''));
        $legacyId = trim((string) ($proposal['proposal_id'] ?? ''));
        if ($uuid === '') {
            $uuid = $legacyId !== '' ? $legacyId : (string) Str::uuid();
            $repairs[] = 'proposal_uuid_generated';
        }
        if ($legacyId === '') {
            $legacyId = $uuid;
            $repairs[] = 'proposal_id_backfilled';
        }

        $proposal['proposal_uuid'] = $uuid;
        $proposal['proposal_id'] = $legacyId;
        $proposal['display_order'] = $index;

        if (! isset($proposal['status']) || ! is_string($proposal['status'])) {
            $proposal['status'] = 'pending';
            $repairs[] = 'proposal_status_defaulted';
        }

        return $proposal;
    }
}
