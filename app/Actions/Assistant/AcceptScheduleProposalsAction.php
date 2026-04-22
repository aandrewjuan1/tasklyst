<?php

namespace App\Actions\Assistant;

use App\Enums\AssistantSchedulePlanItemStatus;
use App\Enums\MessageRole;
use App\Models\AssistantSchedulePlan;
use App\Models\AssistantSchedulePlanItem;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;
use App\Models\User;
use App\Services\EventService;
use App\Services\LLM\Scheduling\ScheduleDraftMetadataNormalizer;
use App\Services\ProjectService;
use App\Services\TaskService;
use App\Support\LLM\SchedulableProposalPolicy;
use App\Support\LLM\TaskAssistantMetadataKeys;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

final class AcceptScheduleProposalsAction
{
    public function __construct(
        private readonly ScheduleDraftMetadataNormalizer $scheduleDraftMetadataNormalizer,
    ) {}

    /**
     * @return array{
     *   pending_schedulable_count:int,
     *   accepted_count:int,
     *   failed_count:int,
     *   is_full_success:bool
     * }
     */
    public function execute(TaskAssistantThread $thread, User $user, int $assistantMessageId, ?int $latestAssistantMessageId): array
    {
        if ($latestAssistantMessageId !== null && $assistantMessageId !== $latestAssistantMessageId) {
            return $this->result(0, 0, 0);
        }

        $message = $thread->messages()
            ->where('id', $assistantMessageId)
            ->where('role', MessageRole::Assistant)
            ->first();

        if (! $message instanceof TaskAssistantMessage) {
            return $this->result(0, 0, 0);
        }

        $resolved = $this->resolveScheduleProposalsBucket($message);
        if ($resolved === null) {
            return $this->result(0, 0, 0);
        }

        [$fullPath, $count] = $resolved;
        $pendingSchedulableCount = 0;
        $acceptedCount = 0;
        $failedCount = 0;
        $acceptedProposals = [];

        for ($index = 0; $index < $count; $index++) {
            $message->refresh();
            $proposal = $this->proposalAtIndex($message, $fullPath, $index);
            if ($proposal === null || ! SchedulableProposalPolicy::isPendingSchedulable($proposal)) {
                continue;
            }

            $pendingSchedulableCount++;
            try {
                $applyResult = $this->applyScheduleProposal($user, $proposal);
                if (! ($applyResult['applied'] ?? false)) {
                    $message->refresh();
                    $this->setProposalStatus($message, $fullPath, $index, 'failed');
                    $failedCount++;

                    Log::warning('task-assistant.proposal.accept_all_not_applied', [
                        'layer' => 'action',
                        'message_id' => $assistantMessageId,
                        'proposal_index' => $index,
                        'proposal_id' => $proposal['proposal_id'] ?? null,
                        'reason' => $applyResult['reason'] ?? 'unknown',
                    ]);

                    continue;
                }

                $message->refresh();
                $this->setProposalStatus($message, $fullPath, $index, 'accepted');
                $acceptedCount++;
                $acceptedProposals[] = $proposal;
            } catch (\Throwable $e) {
                Log::warning('task-assistant.proposal.accept_all_failed', [
                    'layer' => 'action',
                    'message_id' => $assistantMessageId,
                    'proposal_index' => $index,
                    'proposal_id' => $proposal['proposal_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
                $message->refresh();
                $this->setProposalStatus($message, $fullPath, $index, 'failed');
                $failedCount++;

                break;
            }
        }

        $isFullSuccess = $pendingSchedulableCount > 0
            && $acceptedCount === $pendingSchedulableCount
            && $failedCount === 0;

        if ($isFullSuccess) {
            $this->persistAcceptedSchedulePlan(
                user: $user,
                thread: $thread,
                assistantMessage: $message,
                acceptedProposals: $acceptedProposals,
            );
        }

        return $this->result($pendingSchedulableCount, $acceptedCount, $failedCount);
    }

    /**
     * @return array{pending_schedulable_count:int,accepted_count:int,failed_count:int,is_full_success:bool}
     */
    private function result(int $pendingSchedulableCount, int $acceptedCount, int $failedCount): array
    {
        return [
            'pending_schedulable_count' => $pendingSchedulableCount,
            'accepted_count' => $acceptedCount,
            'failed_count' => $failedCount,
            'is_full_success' => $pendingSchedulableCount > 0
                && $acceptedCount === $pendingSchedulableCount
                && $failedCount === 0,
        ];
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    private function resolveScheduleProposalsBucket(TaskAssistantMessage $message): ?array
    {
        $metadata = is_array($message->metadata ?? null) ? $message->metadata : [];
        $normalized = $this->scheduleDraftMetadataNormalizer->normalizeAndValidate($metadata);
        if (! ($normalized['valid'] ?? false)) {
            return null;
        }

        $message->update(['metadata' => $normalized['canonical_metadata']]);

        return [TaskAssistantMetadataKeys::SCHEDULE_PROPOSALS, count($normalized['proposals'] ?? [])];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function proposalAtIndex(TaskAssistantMessage $message, string $fullPath, int $index): ?array
    {
        $items = data_get($message->metadata ?? [], $fullPath, []);
        if (! is_array($items) || ! isset($items[$index]) || ! is_array($items[$index])) {
            return null;
        }

        return $items[$index];
    }

    private function setProposalStatus(TaskAssistantMessage $message, string $path, int $index, string $status): void
    {
        $metadata = $message->metadata ?? [];
        $items = data_get($metadata, $path, []);
        if (! is_array($items) || ! isset($items[$index]) || ! is_array($items[$index])) {
            return;
        }

        $items[$index]['status'] = $status;
        data_set($metadata, $path, $items);

        TaskAssistantMessage::query()
            ->whereKey($message->id)
            ->update(['metadata' => $metadata]);
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array{applied: bool, reason?: string}
     */
    private function applyScheduleProposal(User $user, array $proposal): array
    {
        $applyPayload = $proposal['apply_payload'] ?? null;
        if (is_array($applyPayload)) {
            return $this->applyFromPayload($user, $applyPayload);
        }

        $entityType = (string) ($proposal['entity_type'] ?? '');
        $entityId = (int) ($proposal['entity_id'] ?? 0);
        $startDatetime = (string) ($proposal['start_datetime'] ?? '');
        $endDatetime = (string) ($proposal['end_datetime'] ?? '');
        $durationMinutes = (int) ($proposal['duration_minutes'] ?? 0);

        if ($entityType === 'task' && $entityId > 0 && $startDatetime !== '') {
            $task = Task::query()->forUser($user->id)->whereKey($entityId)->first();
            if (! $task) {
                return ['applied' => false, 'reason' => 'task_not_found'];
            }

            $attributes = ['start_datetime' => $startDatetime];
            if ($durationMinutes > 0) {
                $attributes['duration'] = $durationMinutes;
            }
            app(TaskService::class)->updateTask($task, $attributes);

            return ['applied' => true];
        }

        if ($entityType === 'event' && $entityId > 0 && $startDatetime !== '' && $endDatetime !== '') {
            $event = Event::query()->forUser($user->id)->whereKey($entityId)->first();
            if (! $event) {
                return ['applied' => false, 'reason' => 'event_not_found'];
            }
            app(EventService::class)->updateEvent($event, [
                'start_datetime' => $startDatetime,
                'end_datetime' => $endDatetime,
            ]);

            return ['applied' => true];
        }

        if ($entityType === 'project' && $entityId > 0 && $startDatetime !== '') {
            $project = Project::query()->forUser($user->id)->whereKey($entityId)->first();
            if (! $project) {
                return ['applied' => false, 'reason' => 'project_not_found'];
            }
            app(ProjectService::class)->updateProject($project, [
                'start_datetime' => $startDatetime,
            ]);

            return ['applied' => true];
        }

        return ['applied' => false, 'reason' => 'invalid_proposal_payload'];
    }

    /**
     * @param  array<string, mixed>  $applyPayload
     * @return array{applied: bool, reason?: string}
     */
    private function applyFromPayload(User $user, array $applyPayload): array
    {
        $applyAction = (string) ($applyPayload['action'] ?? $applyPayload['tool'] ?? '');
        $arguments = is_array($applyPayload['arguments'] ?? null) ? $applyPayload['arguments'] : [];
        $updates = is_array($arguments['updates'] ?? null) ? $arguments['updates'] : [];

        if ($applyAction === 'create_event') {
            app(EventService::class)->createEvent($user, [
                'title' => (string) ($arguments['title'] ?? ''),
                'description' => isset($arguments['description']) ? (string) $arguments['description'] : null,
                'start_datetime' => $arguments['startDatetime'] ?? null,
                'end_datetime' => $arguments['endDatetime'] ?? null,
            ]);

            return ['applied' => true];
        }

        if ($applyAction === 'update_task') {
            $taskId = (int) ($arguments['taskId'] ?? 0);
            if ($taskId <= 0) {
                return ['applied' => false, 'reason' => 'task_id_missing'];
            }
            $task = Task::query()->forUser($user->id)->whereKey($taskId)->first();
            if (! $task) {
                return ['applied' => false, 'reason' => 'task_not_found'];
            }
            $attributes = [];
            foreach ($updates as $update) {
                if (! is_array($update)) {
                    continue;
                }
                $property = (string) ($update['property'] ?? '');
                $value = $update['value'] ?? null;
                if ($property === '' || $value === null) {
                    continue;
                }
                if ($property === 'startDatetime') {
                    $attributes['start_datetime'] = (string) $value;
                } elseif ($property === 'duration') {
                    $attributes['duration'] = (int) $value;
                }
            }
            if ($attributes !== []) {
                app(TaskService::class)->updateTask($task, $attributes);

                return ['applied' => true];
            }

            return ['applied' => false, 'reason' => 'no_task_attributes'];
        }

        if ($applyAction === 'update_event') {
            $eventId = (int) ($arguments['eventId'] ?? 0);
            if ($eventId <= 0) {
                return ['applied' => false, 'reason' => 'event_id_missing'];
            }
            $event = Event::query()->forUser($user->id)->whereKey($eventId)->first();
            if (! $event) {
                return ['applied' => false, 'reason' => 'event_not_found'];
            }
            $attributes = [];
            foreach ($updates as $update) {
                if (! is_array($update)) {
                    continue;
                }
                $property = (string) ($update['property'] ?? '');
                $value = $update['value'] ?? null;
                if ($property === '' || $value === null) {
                    continue;
                }
                if ($property === 'startDatetime') {
                    $attributes['start_datetime'] = (string) $value;
                } elseif ($property === 'endDatetime') {
                    $attributes['end_datetime'] = (string) $value;
                }
            }
            if ($attributes !== []) {
                app(EventService::class)->updateEvent($event, $attributes);

                return ['applied' => true];
            }

            return ['applied' => false, 'reason' => 'no_event_attributes'];
        }

        if ($applyAction !== 'update_project') {
            return ['applied' => false, 'reason' => 'unsupported_action'];
        }

        $projectId = (int) ($arguments['projectId'] ?? 0);
        if ($projectId <= 0) {
            return ['applied' => false, 'reason' => 'project_id_missing'];
        }
        $project = Project::query()->forUser($user->id)->whereKey($projectId)->first();
        if (! $project) {
            return ['applied' => false, 'reason' => 'project_not_found'];
        }
        $attributes = [];
        foreach ($updates as $update) {
            if (! is_array($update)) {
                continue;
            }
            $property = (string) ($update['property'] ?? '');
            $value = $update['value'] ?? null;
            if ($property === '' || $value === null) {
                continue;
            }
            if ($property === 'startDatetime') {
                $attributes['start_datetime'] = (string) $value;
            }
        }
        if ($attributes !== []) {
            app(ProjectService::class)->updateProject($project, $attributes);

            return ['applied' => true];
        }

        return ['applied' => false, 'reason' => 'no_project_attributes'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $acceptedProposals
     */
    private function persistAcceptedSchedulePlan(
        User $user,
        TaskAssistantThread $thread,
        TaskAssistantMessage $assistantMessage,
        array $acceptedProposals
    ): void {
        if ($acceptedProposals === []) {
            return;
        }

        $plan = AssistantSchedulePlan::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'thread_id' => $thread->id,
                'assistant_message_id' => $assistantMessage->id,
                'source' => 'assistant_accept_all',
            ],
            [
                'accepted_at' => now(),
                'metadata' => [
                    'proposal_count' => count($acceptedProposals),
                ],
            ]
        );

        foreach ($acceptedProposals as $proposal) {
            if (! is_array($proposal)) {
                continue;
            }

            $proposalUuid = trim((string) ($proposal['proposal_uuid'] ?? $proposal['proposal_id'] ?? ''));
            if ($proposalUuid === '') {
                continue;
            }

            $entityType = trim((string) ($proposal['entity_type'] ?? ''));
            $entityId = (int) ($proposal['entity_id'] ?? 0);
            $title = trim((string) ($proposal['title'] ?? ''));
            $plannedStartAtRaw = trim((string) ($proposal['start_datetime'] ?? ''));
            if ($entityType === '' || $entityId <= 0 || $title === '' || $plannedStartAtRaw === '') {
                continue;
            }

            try {
                $plannedStartAt = CarbonImmutable::parse($plannedStartAtRaw);
            } catch (\Throwable) {
                continue;
            }

            $plannedEndAt = null;
            $plannedEndAtRaw = trim((string) ($proposal['end_datetime'] ?? ''));
            if ($plannedEndAtRaw !== '') {
                try {
                    $plannedEndAt = CarbonImmutable::parse($plannedEndAtRaw);
                } catch (\Throwable) {
                    $plannedEndAt = null;
                }
            }

            $plannedDurationMinutes = (int) ($proposal['duration_minutes'] ?? 0);
            if ($plannedDurationMinutes <= 0) {
                $plannedDurationMinutes = null;
            }

            $timezone = (string) config('app.timezone', 'UTC');
            $plannedDayYmd = $plannedStartAt->setTimezone($timezone)->format('Y-m-d');
            $dedupeKey = AssistantSchedulePlanItem::buildDedupeKey($user->id, $entityType, $entityId, $plannedDayYmd);
            $proposalMetadata = [
                'reason_score' => $proposal['reason_score'] ?? null,
                'apply_payload' => $proposal['apply_payload'] ?? null,
                'priority_rank' => $proposal['priority_rank'] ?? null,
            ];

            $existing = AssistantSchedulePlanItem::query()
                ->forUser($user->id)
                ->where('dedupe_key', $dedupeKey)
                ->active()
                ->first();

            $savedItem = null;
            if ($existing) {
                $mergedMetadata = is_array($existing->metadata) ? $existing->metadata : [];
                $mergedMetadata = array_merge($mergedMetadata, $proposalMetadata);
                data_set($mergedMetadata, 'proposal_last_accepted_at', now()->toIso8601String());
                data_set($mergedMetadata, 'proposal_last_uuid', $proposalUuid);
                $existing->fill([
                    'assistant_schedule_plan_id' => $plan->id,
                    'proposal_uuid' => $proposalUuid,
                    'proposal_id' => trim((string) ($proposal['proposal_id'] ?? '')) ?: null,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'title' => $title,
                    'planned_start_at' => $plannedStartAt,
                    'planned_end_at' => $plannedEndAt,
                    'planned_duration_minutes' => $plannedDurationMinutes,
                    'status' => AssistantSchedulePlanItemStatus::Planned,
                    'accepted_at' => now(),
                    'metadata' => $mergedMetadata,
                ]);
                $existing->save();
                $savedItem = $existing;
            } else {
                $savedItem = AssistantSchedulePlanItem::query()->create([
                    'assistant_schedule_plan_id' => $plan->id,
                    'user_id' => $user->id,
                    'proposal_uuid' => $proposalUuid,
                    'proposal_id' => trim((string) ($proposal['proposal_id'] ?? '')) ?: null,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'title' => $title,
                    'planned_start_at' => $plannedStartAt,
                    'planned_end_at' => $plannedEndAt,
                    'planned_duration_minutes' => $plannedDurationMinutes,
                    'status' => AssistantSchedulePlanItemStatus::Planned,
                    'accepted_at' => now(),
                    'metadata' => $proposalMetadata,
                ]);
            }

            if ($savedItem instanceof AssistantSchedulePlanItem) {
                $supersededCount = $this->dismissOlderActivePlanItemsForEntity(
                    userId: (int) $user->id,
                    entityType: $entityType,
                    entityId: $entityId,
                    keepItemId: (int) $savedItem->id,
                );
                if ($supersededCount > 0) {
                    $savedMetadata = is_array($savedItem->metadata) ? $savedItem->metadata : [];
                    data_set($savedMetadata, 'actions.last_action', 'rescheduled');
                    data_set($savedMetadata, 'actions.last_action_at', now()->toIso8601String());
                    data_set($savedMetadata, 'rescheduled_from_previous_plan_item_count', $supersededCount);
                    $savedItem->fill(['metadata' => $savedMetadata]);
                    $savedItem->save();
                }
            }
        }
    }

    private function dismissOlderActivePlanItemsForEntity(
        int $userId,
        string $entityType,
        int $entityId,
        int $keepItemId
    ): int {
        $activeItems = AssistantSchedulePlanItem::query()
            ->forUser($userId)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->active()
            ->where('id', '!=', $keepItemId)
            ->get();

        $dismissedCount = 0;
        foreach ($activeItems as $activeItem) {
            /** @var AssistantSchedulePlanItem $activeItem */
            $metadata = is_array($activeItem->metadata) ? $activeItem->metadata : [];
            data_set($metadata, 'superseded_at', now()->toIso8601String());
            data_set($metadata, 'superseded_by_plan_item_id', $keepItemId);
            $activeItem->fill([
                'status' => AssistantSchedulePlanItemStatus::Dismissed,
                'dismissed_at' => now(),
                'metadata' => $metadata,
            ]);
            $activeItem->save();
            $dismissedCount++;
        }

        return $dismissedCount;
    }
}
