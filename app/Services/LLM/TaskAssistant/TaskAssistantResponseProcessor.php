<?php

namespace App\Services\LLM\TaskAssistant;

use App\Support\LLM\TaskAssistantListingDefaults;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

final class TaskAssistantResponseProcessor
{
    private const FORMATTED_MESSAGE_LOG_MAX_CHARS = 50000;

    public function __construct(
        private readonly TaskAssistantMessageFormatter $messageFormatter,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $snapshot
     * @return array{valid: bool, formatted_content: string, structured_data: array<string, mixed>, errors: array<int, string>}
     */
    public function processResponse(
        string $flow,
        array $data,
        array $snapshot = [],
    ): array {
        $validation = $this->validateFlowData($flow, $data, $snapshot);
        $formattedContent = $this->messageFormatter->format($flow, $data, $snapshot);

        $contentLength = mb_strlen($formattedContent);
        $loggedBody = $formattedContent;
        $truncated = false;
        if ($contentLength > self::FORMATTED_MESSAGE_LOG_MAX_CHARS) {
            $loggedBody = mb_substr($formattedContent, 0, self::FORMATTED_MESSAGE_LOG_MAX_CHARS).'…';
            $truncated = true;
        }

        Log::info('task-assistant.formatted_message', [
            'layer' => 'message_format',
            'flow' => $flow,
            'thread_id' => app()->bound('task_assistant.thread_id') ? app('task_assistant.thread_id') : null,
            'assistant_message_id' => app()->bound('task_assistant.message_id') ? app('task_assistant.message_id') : null,
            'validation_valid' => $validation['valid'],
            'content_length' => $contentLength,
            'formatted_message_truncated' => $truncated,
            'formatted_message' => $loggedBody,
        ]);

        Log::info('task-assistant.validation', [
            'layer' => 'validation',
            'flow' => $flow,
            'valid' => $validation['valid'],
            'errors' => $validation['errors'],
            'data_keys' => array_keys($data),
        ]);

        return [
            'valid' => $validation['valid'],
            'formatted_content' => $formattedContent,
            'structured_data' => $data,
            'errors' => $validation['errors'],
        ];
    }

    private function validateFlowData(string $flow, array $data, array $snapshot): array
    {
        return match ($flow) {
            'general_guidance' => $this->validateGeneralGuidanceData($data),
            'prioritize' => $this->validatePrioritizeListingData($data),
            'daily_schedule' => $this->validateDailyScheduleData($data, $snapshot),
            default => ['valid' => true, 'data' => $data, 'errors' => []],
        };
    }

    /**
     * General guidance payload: empathetic message + one clarifying question.
     *
     * @param  array<string, mixed>  $data
     * @return array{valid: bool, data: array<string, mixed>, errors: array<int, string>}
     */
    private function validateGeneralGuidanceData(array $data): array
    {
        $rules = [
            // General guidance is intentionally more conversational and can be
            // slightly longer than other flows. We still keep it bounded to
            // prevent UI/payload issues.
            'message' => ['required', 'string', 'min:1', 'max:500'],
            'clarifying_question' => ['required', 'string', 'min:5', 'max:220'],
            'redirect_target' => ['required', 'string', 'in:prioritize,schedule,either'],
            'suggested_replies' => ['nullable', 'array', 'max:3'],
            'suggested_replies.*' => ['string', 'min:1', 'max:140'],
        ];

        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            return [
                'valid' => false,
                'data' => [],
                'errors' => $validator->errors()->all(),
            ];
        }

        return [
            'valid' => true,
            'data' => $data,
            'errors' => [],
        ];
    }

    /**
     * Prioritize payload: backend items plus narrative reasoning and suggested_guidance paragraph.
     * No summary field; formatted message order is reasoning, items, then guidance.
     *
     * @param  array<string, mixed>  $data
     */
    private function validatePrioritizeListingData(array $data): array
    {
        $maxReasoning = TaskAssistantListingDefaults::maxReasoningChars();
        $maxGuidance = TaskAssistantListingDefaults::maxSuggestedGuidanceChars();
        $rules = [
            'reasoning' => ['required', 'string', 'min:1', 'max:'.$maxReasoning],
            'suggested_guidance' => ['required', 'string', 'min:20', 'max:'.$maxGuidance],
            'limit_used' => ['required', 'integer', 'min:0', 'max:50'],
            'items' => ['required', 'array', 'max:50'],
            'items.*.entity_type' => ['required', 'string', 'in:task,event,project'],
            'items.*.entity_id' => ['required', 'integer', 'min:1'],
            'items.*.title' => ['required', 'string', 'max:200'],
            'items.*.priority' => ['nullable', 'string', 'max:32'],
            'items.*.due_bucket' => ['nullable', 'string', 'max:32'],
            'items.*.due_phrase' => ['nullable', 'string', 'max:64'],
            'items.*.due_on' => ['nullable', 'string', 'max:64'],
            'items.*.complexity_label' => ['nullable', 'string', 'max:64'],
        ];

        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            return [
                'valid' => false,
                'data' => [],
                'errors' => $validator->errors()->all(),
            ];
        }

        return [
            'valid' => true,
            'data' => $data,
            'errors' => [],
        ];
    }

    private function validateDailyScheduleData(array $data, array $snapshot): array
    {
        $rules = [
            'proposals' => ['nullable', 'array', 'max:100'],
            'proposals.*.proposal_id' => ['required_with:proposals', 'string', 'max:100'],
            'proposals.*.status' => ['required_with:proposals', 'string', 'in:pending,accepted,declined,failed'],
            'proposals.*.entity_type' => ['required_with:proposals', 'string', 'in:task,event,project'],
            'proposals.*.entity_id' => ['nullable', 'integer'],
            'proposals.*.title' => ['required_with:proposals', 'string', 'max:200'],
            'proposals.*.reason_score' => ['nullable', 'numeric'],
            'proposals.*.start_datetime' => ['required_with:proposals', 'date'],
            'proposals.*.end_datetime' => ['nullable', 'date'],
            'proposals.*.duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'proposals.*.conflict_notes' => ['nullable', 'array', 'max:10'],
            'proposals.*.conflict_notes.*' => ['string', 'max:300'],
            'proposals.*.apply_payload' => ['nullable', 'array'],
            'proposals.*.apply_payload.tool' => ['required_with:proposals.*.apply_payload', 'string', 'max:64'],
            'proposals.*.apply_payload.arguments' => ['nullable', 'array'],
            'proposals.*.apply_payload.arguments.updates' => ['nullable', 'array', 'max:10'],
            'proposals.*.apply_payload.arguments.updates.*.property' => ['required_with:proposals.*.apply_payload.arguments.updates', 'string', 'max:64'],
            'proposals.*.apply_payload.arguments.updates.*.value' => ['required_with:proposals.*.apply_payload.arguments.updates'],
            'blocks' => ['required', 'array', 'min:1', 'max:48'],
            'blocks.*.start_time' => ['required', 'string', 'max:20'],
            'blocks.*.end_time' => ['required', 'string', 'max:20'],
            'blocks.*.label' => ['nullable', 'string', 'max:160'],
            'blocks.*.task_id' => ['nullable', 'integer'],
            'blocks.*.event_id' => ['nullable', 'integer'],
            'blocks.*.note' => ['nullable', 'string', 'max:300'],
            'summary' => ['nullable', 'string', 'max:500'],
            'assistant_note' => ['nullable', 'string', 'max:500'],
            'reasoning' => ['nullable', 'string', 'max:1200'],
            'strategy_points' => ['nullable', 'array', 'max:6'],
            'strategy_points.*' => ['string', 'max:300'],
            'suggested_next_steps' => ['nullable', 'array', 'max:8'],
            'suggested_next_steps.*' => ['string', 'max:300'],
            'assumptions' => ['nullable', 'array', 'max:6'],
            'assumptions.*' => ['string', 'max:300'],
        ];

        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            return [
                'valid' => false,
                'data' => [],
                'errors' => $validator->errors()->all(),
            ];
        }

        $allowedTaskIds = collect($snapshot['tasks'] ?? [])
            ->map(fn (array $task): int => (int) ($task['id'] ?? 0))
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $allowedEventIds = collect($snapshot['events'] ?? [])
            ->map(fn (array $event): int => (int) ($event['id'] ?? 0))
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $errors = [];
        foreach (($data['blocks'] ?? []) as $index => $block) {
            $taskId = $block['task_id'] ?? null;
            if ($taskId !== null && ! in_array((int) $taskId, $allowedTaskIds, true)) {
                $errors[] = "blocks.$index.task_id must be null or one of the IDs from snapshot.tasks.";
            }

            $eventId = $block['event_id'] ?? null;
            if ($eventId !== null && ! in_array((int) $eventId, $allowedEventIds, true)) {
                $errors[] = "blocks.$index.event_id must be null or one of the IDs from snapshot.events.";
            }
        }

        foreach (($data['proposals'] ?? []) as $index => $proposal) {
            if (! is_array($proposal)) {
                $errors[] = "proposals.$index must be an object.";

                continue;
            }

            $entityType = $proposal['entity_type'] ?? null;
            $entityId = $proposal['entity_id'] ?? null;

            if ($entityType === 'task' && $entityId !== null && ! in_array((int) $entityId, $allowedTaskIds, true)) {
                $errors[] = "proposals.$index.entity_id must exist in snapshot.tasks.";
            }
            if ($entityType === 'event' && $entityId !== null && ! in_array((int) $entityId, $allowedEventIds, true)) {
                $errors[] = "proposals.$index.entity_id must exist in snapshot.events.";
            }
        }

        if ($errors !== []) {
            return [
                'valid' => false,
                'data' => [],
                'errors' => $errors,
            ];
        }

        return [
            'valid' => true,
            'data' => $data,
            'errors' => [],
        ];
    }
}
