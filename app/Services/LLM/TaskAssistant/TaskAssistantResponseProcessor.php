<?php

namespace App\Services\LLM\TaskAssistant;

use App\Support\LLM\TaskAssistantPrioritizeOutputDefaults;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidationValidator;

final class TaskAssistantResponseProcessor
{
    private const FORMATTED_MESSAGE_LOG_MAX_CHARS = 8000;

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
            'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
            'thread_id' => app()->bound('task_assistant.thread_id') ? app('task_assistant.thread_id') : null,
            'assistant_message_id' => app()->bound('task_assistant.message_id') ? app('task_assistant.message_id') : null,
            'validation_valid' => $validation['valid'],
            'content_length' => $contentLength,
            'formatted_message_truncated' => $truncated,
            'formatted_message_sha256' => hash('sha256', $formattedContent),
            'formatted_message' => $loggedBody,
        ]);

        Log::info('task-assistant.validation', [
            'layer' => 'validation',
            'flow' => $flow,
            'run_id' => app()->bound('task_assistant.run_id') ? app('task_assistant.run_id') : null,
            'valid' => $validation['valid'],
            'errors' => $validation['errors'],
            'errors_count' => is_array($validation['errors'] ?? null) ? count($validation['errors']) : 0,
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
     * General guidance payload: unified response + next-step guidance + optional clarifier.
     *
     * @param  array<string, mixed>  $data
     * @return array{valid: bool, data: array<string, mixed>, errors: array<int, string>}
     */
    private function validateGeneralGuidanceData(array $data): array
    {
        $maxNextOptions = TaskAssistantPrioritizeOutputDefaults::maxNextFieldChars();
        $rules = [
            'intent' => ['required', 'string', 'in:task,out_of_scope,unclear'],
            'acknowledgement' => ['required', 'string', 'min:2', 'max:220'],
            'message' => ['required', 'string', 'min:5', 'max:760'],
            'suggested_next_actions' => ['required', 'array', 'min:2', 'max:3'],
            'suggested_next_actions.*' => ['required', 'string', 'min:2', 'max:140'],
            'next_options' => ['required', 'string', 'min:5', 'max:'.$maxNextOptions],
            'next_options_chip_texts' => ['present', 'array', 'size:2'],
            'next_options_chip_texts.*' => ['required', 'string', 'min:2', 'max:120'],
        ];

        $validator = Validator::make($data, $rules);
        $validator->after(function (ValidationValidator $validator) use ($data): void {
            $intent = (string) ($data['intent'] ?? '');
            $message = (string) ($data['message'] ?? '');
            $messageLower = mb_strtolower($message);
            $ack = (string) ($data['acknowledgement'] ?? '');
            $ackLower = mb_strtolower($ack);
            $actions = is_array($data['suggested_next_actions'] ?? null) ? $data['suggested_next_actions'] : [];
            $actionBlob = mb_strtolower(implode(' ', array_map(static fn (mixed $line): string => (string) $line, $actions)));
            $nextOptions = (string) ($data['next_options'] ?? '');
            $nextOptionsLower = mb_strtolower($nextOptions);

            if (! str_contains($actionBlob, 'priorit')) {
                $validator->errors()->add('suggested_next_actions', 'suggested_next_actions must include a prioritize option.');
            }

            if (! str_contains($actionBlob, 'schedule') && ! str_contains($actionBlob, 'time block')) {
                $validator->errors()->add('suggested_next_actions', 'suggested_next_actions must include a schedule/time-block option.');
            }

            $nextHasPrioritizeTheme = str_contains($nextOptionsLower, 'priorit')
                || str_contains($nextOptionsLower, 'do first')
                || str_contains($nextOptionsLower, 'tackle')
                || str_contains($nextOptionsLower, 'rank');
            $nextHasScheduleTheme = str_contains($nextOptionsLower, 'schedule')
                || str_contains($nextOptionsLower, 'time block')
                || str_contains($nextOptionsLower, 'block time')
                || str_contains($nextOptionsLower, 'calendar');
            if (! $nextHasPrioritizeTheme) {
                $validator->errors()->add('next_options', 'next_options must offer a prioritize or ordering theme.');
            }
            if (! $nextHasScheduleTheme) {
                $validator->errors()->add('next_options', 'next_options must offer a scheduling or time-blocking theme.');
            }

            if (str_contains($nextOptionsLower, 'snapshot')
                || str_contains($nextOptionsLower, 'json')
                || str_contains($nextOptionsLower, 'backend')
                || str_contains($nextOptionsLower, 'database')) {
                $validator->errors()->add('next_options', 'next_options must not include internal technical terms.');
            }

            if (str_contains($messageLower, 'snapshot')
                || str_contains($messageLower, 'json')
                || str_contains($messageLower, 'backend')
                || str_contains($messageLower, 'database')) {
                $validator->errors()->add('message', 'message must not include internal technical terms.');
            }

            // Acknowledgement should be empathy-only (no refusal/boundary language).
            if (str_contains($ackLower, "can't help")
                || str_contains($ackLower, 'cannot help')
                || str_contains($ackLower, 'out of scope')
                || str_contains($ackLower, 'off-topic')
                || str_contains($ackLower, 'off topic')) {
                $validator->errors()->add('acknowledgement', 'acknowledgement must not include refusal/boundary language.');
            }

            // For out_of_scope, the boundary must live in message.
            if ($intent === 'out_of_scope'
                && ! str_contains($messageLower, "can't help")
                && ! str_contains($messageLower, 'cannot help')) {
                $validator->errors()->add('message', 'out_of_scope message must include a gentle refusal/boundary.');
            }

            // Suggested next actions should be clausal/verb-led (not noun labels).
            foreach ($actions as $index => $action) {
                $line = trim((string) $action);
                if ($line === '') {
                    continue;
                }

                if (preg_match('/^(tell|share|list|pick|ask|show|help|rephrase|schedule|prioritize)\\b/i', $line) !== 1) {
                    $validator->errors()->add("suggested_next_actions.$index", 'suggested_next_actions must start with a verb-led clause (e.g., Tell/Share/List/Pick/Ask).');
                }
            }

            // Detect obvious truncation fragments.
            foreach ([$ack, $message] as $fieldText) {
                $trimmed = rtrim((string) $fieldText);
                if ($trimmed === '') {
                    continue;
                }
                if (preg_match('/\b[a-z]{1,2}$/u', $trimmed) === 1 && ! preg_match('/[.!?]$/u', $trimmed)) {
                    $validator->errors()->add('general_guidance', 'section appears truncated mid-thought; regenerate cleaner wording.');
                    break;
                }
            }
        });

        $errors = $validator->errors()->all();

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

    private function textSimilarityScore(string $left, string $right): float
    {
        $a = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $left) ?? $left));
        $b = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $right) ?? $right));
        if ($a === '' || $b === '') {
            return 0.0;
        }

        $aTokens = preg_split('/[^\pL\pN]+/u', $a, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $bTokens = preg_split('/[^\pL\pN]+/u', $b, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($aTokens === [] || $bTokens === []) {
            return 0.0;
        }

        $aSet = array_values(array_unique($aTokens));
        $bSet = array_values(array_unique($bTokens));
        $intersection = count(array_intersect($aSet, $bSet));
        $union = count(array_unique(array_merge($aSet, $bSet)));
        if ($union === 0) {
            return 0.0;
        }

        return $intersection / $union;
    }

    /**
     * Prioritize payload: backend items plus narrative fields for {@see TaskAssistantMessageFormatter::formatPrioritizeListingMessage}.
     * Student-visible order is optional acknowledgment, framing, Doing block when present, ranked lines,
     * filter_interpretation, reasoning (coach/why), then next_options last.
     * Narrative singular/plural coherence is enforced in {@see TaskAssistantPrioritizeOutputDefaults::coerceSingularPrioritizeNarrative}
     * and prompts when count(items) is 1.
     *
     * @param  array<string, mixed>  $data
     */
    private function validatePrioritizeListingData(array $data): array
    {
        $maxReasoning = TaskAssistantPrioritizeOutputDefaults::maxReasoningChars();
        $maxFraming = TaskAssistantPrioritizeOutputDefaults::maxFramingChars();
        $maxDoingCoach = TaskAssistantPrioritizeOutputDefaults::maxDoingProgressCoachChars();
        $maxNextField = min(320, $maxReasoning);
        $maxFocusTitle = 200;
        $rules = [
            'limit_used' => ['required', 'integer', 'min:0', 'max:50'],
            // `present` allows an empty ranked slice (empty workspace or zero matches after filters).
            'items' => ['present', 'array', 'max:50'],
            'focus' => ['required', 'array'],
            'focus.main_task' => ['required', 'string', 'min:1', 'max:'.$maxFocusTitle],
            'focus.secondary_tasks' => ['present', 'array', 'max:49'],
            'focus.secondary_tasks.*' => ['string', 'max:'.$maxFocusTitle],
            'framing' => ['required', 'string', 'min:3', 'max:'.$maxFraming],
            'next_options' => ['required', 'string', 'min:5', 'max:'.$maxNextField],
            // Empty array is allowed (e.g. deterministic empty-workspace reply has no follow-up chips).
            'next_options_chip_texts' => ['present', 'array', 'max:3'],
            'next_options_chip_texts.*' => ['string', 'min:2', 'max:120'],
            'items.*.entity_type' => ['required', 'string', 'in:task,event,project'],
            'items.*.entity_id' => ['required', 'integer', 'min:1'],
            'items.*.title' => ['required', 'string', 'max:200'],
            'items.*.priority' => ['nullable', 'string', 'max:32'],
            'items.*.due_bucket' => ['nullable', 'string', 'max:32'],
            'items.*.due_phrase' => ['nullable', 'string', 'max:64'],
            'items.*.due_on' => ['nullable', 'string', 'max:64'],
            'items.*.complexity_label' => ['nullable', 'string', 'max:64'],
            'acknowledgment' => ['nullable', 'string', 'max:'.$maxFraming],
            'reasoning' => ['required', 'string', 'min:3', 'max:'.$maxReasoning],
            'filter_interpretation' => ['nullable', 'string', 'max:280'],
            'assumptions' => ['nullable', 'array', 'max:4'],
            'assumptions.*' => ['string', 'max:240'],
            'prioritize_variant' => ['nullable', 'string', 'in:rank'],
            'doing_titles' => ['nullable', 'array', 'max:20'],
            'doing_titles.*' => ['string', 'max:200'],
            'doing_progress_coach' => ['nullable', 'string', 'max:'.$maxDoingCoach],
        ];

        $validator = Validator::make($data, $rules);
        $validator->after(function (ValidationValidator $validator) use ($data): void {
            $framing = trim((string) ($data['framing'] ?? ''));
            $reasoning = trim((string) ($data['reasoning'] ?? ''));
            $framingNorm = trim(preg_replace('/\s+/u', ' ', $framing) ?? $framing);
            $reasoningNorm = trim(preg_replace('/\s+/u', ' ', $reasoning) ?? $reasoning);
            if ($framingNorm !== '' && $framingNorm === $reasoningNorm) {
                $validator->errors()->add('reasoning', 'reasoning must not duplicate framing verbatim.');
            }

            $doingTitles = is_array($data['doing_titles'] ?? null) ? $data['doing_titles'] : [];
            $doingTitles = array_values(array_filter(
                array_map(static fn (mixed $t): string => trim((string) $t), $doingTitles),
                static fn (string $s): bool => $s !== ''
            ));
            $coach = trim((string) ($data['doing_progress_coach'] ?? ''));
            if ($doingTitles !== [] && $coach === '') {
                $validator->errors()->add('doing_progress_coach', 'doing_progress_coach is required when doing_titles is non-empty.');
            }
            if ($doingTitles === [] && $coach !== '') {
                $validator->errors()->add('doing_progress_coach', 'doing_progress_coach must be empty when doing_titles is empty.');
            }
        });

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
        $maxFraming = TaskAssistantPrioritizeOutputDefaults::maxFramingChars();
        $maxNextOptions = TaskAssistantPrioritizeOutputDefaults::maxNextFieldChars();
        $maxReasoning = TaskAssistantPrioritizeOutputDefaults::maxReasoningChars();

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
            'schedule_variant' => ['nullable', 'string', 'in:daily,range'],
            'schedule_empty_placement' => ['nullable', 'boolean'],
            'summary' => ['required', 'string', 'min:3', 'max:500'],
            'framing' => ['required', 'string', 'min:3', 'max:'.$maxFraming],
            'filter_interpretation' => ['nullable', 'string', 'max:280'],
            'acknowledgment' => ['nullable', 'string', 'max:220'],
            'assistant_note' => ['nullable', 'string', 'max:500'],
            'reasoning' => ['required', 'string', 'min:3', 'max:'.$maxReasoning],
            'next_options' => ['required', 'string', 'min:5', 'max:'.$maxNextOptions],
            'next_options_chip_texts' => ['required', 'array', 'min:2', 'max:3'],
            'next_options_chip_texts.*' => ['required', 'string', 'min:2', 'max:120'],
            'display_block_order' => ['nullable', 'array', 'max:48'],
            'display_block_order.*' => ['integer', 'min:0', 'max:47'],
            'strategy_points' => ['nullable', 'array', 'max:6'],
            'strategy_points.*' => ['string', 'max:300'],
            'suggested_next_steps' => ['nullable', 'array', 'max:8'],
            'suggested_next_steps.*' => ['string', 'max:300'],
            'assumptions' => ['nullable', 'array', 'max:6'],
            'assumptions.*' => ['string', 'max:300'],
        ];

        $validator = Validator::make($data, $rules);
        $validator->after(function (ValidationValidator $validator) use ($data): void {
            $framing = trim((string) ($data['framing'] ?? ''));
            $reasoning = trim((string) ($data['reasoning'] ?? ''));
            $framingNorm = trim(preg_replace('/\s+/u', ' ', $framing) ?? $framing);
            $reasoningNorm = trim(preg_replace('/\s+/u', ' ', $reasoning) ?? $reasoning);
            if ($framingNorm !== '' && $framingNorm === $reasoningNorm) {
                $validator->errors()->add('reasoning', 'reasoning must not duplicate framing verbatim.');
            }

            $nextOptions = (string) ($data['next_options'] ?? '');
            $nextLower = mb_strtolower($nextOptions);
            $nextHasPrioritizeTheme = str_contains($nextLower, 'priorit')
                || str_contains($nextLower, 'do first')
                || str_contains($nextLower, 'tackle')
                || str_contains($nextLower, 'rank');
            $nextHasScheduleTheme = str_contains($nextLower, 'schedule')
                || str_contains($nextLower, 'time block')
                || str_contains($nextLower, 'block time')
                || str_contains($nextLower, 'calendar')
                || str_contains($nextLower, 'window');
            if (! $nextHasPrioritizeTheme) {
                $validator->errors()->add('next_options', 'next_options must offer a prioritize or ordering theme.');
            }
            if (! $nextHasScheduleTheme) {
                $validator->errors()->add('next_options', 'next_options must offer a scheduling or time-blocking theme.');
            }

            $blocks = is_array($data['blocks'] ?? null) ? $data['blocks'] : [];
            $blockCount = count($blocks);
            $order = $data['display_block_order'] ?? null;
            if (is_array($order) && $order !== []) {
                $normalized = [];
                foreach ($order as $v) {
                    if (is_int($v)) {
                        $normalized[] = $v;
                    } elseif (is_float($v)) {
                        $normalized[] = (int) $v;
                    } elseif (is_string($v) && is_numeric($v)) {
                        $normalized[] = (int) $v;
                    }
                }
                if (count($normalized) !== $blockCount) {
                    $validator->errors()->add('display_block_order', 'display_block_order must be a permutation of block indices.');

                    return;
                }
                $sorted = $normalized;
                sort($sorted);
                for ($i = 0; $i < $blockCount; $i++) {
                    if (($sorted[$i] ?? -1) !== $i) {
                        $validator->errors()->add('display_block_order', 'display_block_order must be a permutation of block indices.');

                        return;
                    }
                }
            }
        });

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

        $allowedProjectIds = collect($snapshot['projects'] ?? [])
            ->map(fn (array $project): int => (int) ($project['id'] ?? 0))
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
            if ($entityType === 'project') {
                if ($entityId === null || (int) $entityId <= 0) {
                    $errors[] = "proposals.$index.entity_id is required for project proposals.";
                } elseif (! in_array((int) $entityId, $allowedProjectIds, true)) {
                    $errors[] = "proposals.$index.entity_id must exist in snapshot.projects.";
                }
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
