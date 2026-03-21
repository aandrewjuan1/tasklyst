<?php

namespace App\Services\LLM\TaskAssistant;

use Illuminate\Support\Facades\Validator;

final class TaskAssistantResponseProcessor
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $snapshot
     * @return array{valid: bool, formatted_content: string, structured_data: array<string, mixed>, errors: array<int, string>}
     */
    public function processResponse(
        string $flow,
        array $data,
        array $snapshot = [],
        ?\App\Models\TaskAssistantThread $thread = null,
        ?string $originalUserMessage = null
    ): array {
        $validation = $this->validateFlowData($flow, $data, $snapshot);
        $formattedContent = $this->formatFlowData($flow, $data);

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
            'prioritize' => $this->validatePrioritizeData($data),
            'daily_schedule' => $this->validateDailyScheduleData($data, $snapshot),
            default => ['valid' => true, 'data' => $data, 'errors' => []],
        };
    }

    private function validatePrioritizeData(array $data): array
    {
        $rules = [
            'summary' => ['nullable', 'string', 'max:1000'],
            'limit_used' => ['required', 'integer', 'min:0', 'max:20'],
            'items' => ['required', 'array', 'max:20'],
            'items.*.entity_type' => ['required', 'string', 'in:task,event,project'],
            'items.*.entity_id' => ['required', 'integer', 'min:1'],
            'items.*.title' => ['required', 'string', 'max:200'],
            'items.*.reason' => ['nullable', 'string', 'max:500'],
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

    private function formatFlowData(string $flow, array $data): string
    {
        $body = match ($flow) {
            'prioritize' => $this->formatPrioritizeData($data),
            'daily_schedule' => $this->formatDailyScheduleData($data),
            default => $this->formatDefaultData($data),
        };

        return trim($body);
    }

    private function formatDailyScheduleData(array $data): string
    {
        $summary = trim((string) ($data['summary'] ?? ''));
        $reasoning = trim((string) ($data['reasoning'] ?? ''));
        $assistantNote = trim((string) ($data['assistant_note'] ?? ''));
        $blocks = $data['blocks'] ?? [];
        $strategyPoints = is_array($data['strategy_points'] ?? null) ? $data['strategy_points'] : [];
        $nextSteps = is_array($data['suggested_next_steps'] ?? null) ? $data['suggested_next_steps'] : [];
        $assumptions = is_array($data['assumptions'] ?? null) ? $data['assumptions'] : [];

        $paragraphs = [];
        if ($summary !== '') {
            $paragraphs[] = $summary;
        }
        if ($reasoning !== '') {
            $paragraphs[] = 'Why this schedule works: '.$reasoning;
        }

        if (is_array($blocks) && ! empty($blocks)) {
            $sentences = [];
            foreach ($blocks as $block) {
                if (! is_array($block)) {
                    continue;
                }
                $start = (string) ($block['start_time'] ?? '');
                $end = (string) ($block['end_time'] ?? '');
                $label = (string) ($block['label'] ?? $block['title'] ?? 'Focus time');
                $reason = (string) ($block['reason'] ?? $block['note'] ?? '');
                $ref = $label;
                if ($block['task_id'] ?? null) {
                    $ref .= ' (task '.$block['task_id'].')';
                } elseif ($block['event_id'] ?? null) {
                    $ref .= ' (event '.$block['event_id'].')';
                }

                $time = trim($start.'–'.$end, '–');
                $sentence = ($time !== '' ? $time.': ' : '').$ref;
                if ($reason !== '') {
                    $sentence .= ' — '.$reason;
                }

                $sentences[] = $sentence;
            }

            if (! empty($sentences)) {
                $paragraphs[] = 'Planned time blocks: '.$this->joinSentences($sentences);
            }
        }

        $strategyPoints = array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $strategyPoints),
            static fn (string $value): bool => $value !== ''
        ));
        if ($strategyPoints !== []) {
            $paragraphs[] = 'Scheduling strategy: '.$this->joinSentences($strategyPoints).'.';
        }

        $nextSteps = array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $nextSteps),
            static fn (string $value): bool => $value !== ''
        ));
        if ($nextSteps !== []) {
            $paragraphs[] = 'Suggested next steps: '.$this->joinSentences($nextSteps).'.';
        }

        $assumptions = array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $assumptions),
            static fn (string $value): bool => $value !== ''
        ));
        if ($assumptions !== []) {
            $paragraphs[] = 'Assumptions used: '.$this->joinSentences($assumptions).'.';
        }

        $proposals = $data['proposals'] ?? [];
        if (is_array($proposals) && ! empty($proposals)) {
            $paragraphs[] = 'Use Accept/Decline on each proposed item to apply schedule updates.';
        }
        if ($assistantNote !== '') {
            $paragraphs[] = $assistantNote;
        }

        return implode("\n\n", $paragraphs);
    }

    private function formatPrioritizeData(array $data): string
    {
        $summary = trim((string) ($data['summary'] ?? ''));
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];

        $lines = [];
        if ($summary !== '') {
            $lines[] = $summary;
        }

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $type = (string) ($item['entity_type'] ?? 'task');
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $reason = trim((string) ($item['reason'] ?? ''));
            $line = ($index + 1).". [{$type}] {$title}";
            if ($reason !== '') {
                $line .= ' - '.$reason;
            }
            $lines[] = $line;
        }

        return implode("\n\n", $lines);
    }

    private function formatDefaultData(array $data): string
    {
        if (isset($data['message']) && is_string($data['message'])) {
            return $data['message'];
        }

        if (isset($data['summary']) && is_string($data['summary'])) {
            return $data['summary'];
        }

        return 'I\'ve processed your request. Is there anything specific you\'d like me to help you with next?';
    }

    /**
     * Join sentences into a natural flowing paragraph. Uses commas and conjunction for readability.
     *
     * @param  array<int, string>  $sentences
     */
    private function joinSentences(array $sentences): string
    {
        $sentences = array_values($sentences);
        $count = count($sentences);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return $sentences[0];
        }
        if ($count === 2) {
            return $sentences[0].' and '.$sentences[1];
        }

        $last = array_pop($sentences);

        return implode(', ', $sentences).', and '.$last;
    }
}
