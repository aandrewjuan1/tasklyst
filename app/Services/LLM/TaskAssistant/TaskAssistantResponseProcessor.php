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
            'listing_followup' => $this->validateListingFollowupData($data),
            default => ['valid' => true, 'data' => $data, 'errors' => []],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{valid: bool, data: array<string, mixed>, errors: array<int, string>}
     */
    private function validateListingFollowupData(array $data): array
    {
        $maxNext = TaskAssistantPrioritizeOutputDefaults::maxNextFieldChars();
        $rules = [
            'verdict' => ['required', 'string', 'in:yes,partial,no'],
            'compared_items' => ['present', 'array'],
            'compared_items.*.entity_type' => ['required', 'string', 'min:1'],
            'compared_items.*.entity_id' => ['required', 'integer', 'min:1'],
            'compared_items.*.title' => ['required', 'string', 'min:1', 'max:500'],
            'more_urgent_alternatives' => ['present', 'array', 'max:3'],
            'more_urgent_alternatives.*.entity_type' => ['required', 'string', 'min:1'],
            'more_urgent_alternatives.*.entity_id' => ['required', 'integer', 'min:1'],
            'more_urgent_alternatives.*.title' => ['required', 'string', 'min:1', 'max:500'],
            'more_urgent_alternatives.*.reason_short' => ['required', 'string', 'min:2', 'max:220'],
            'framing' => ['required', 'string', 'min:5', 'max:900'],
            'rationale' => ['required', 'string', 'min:10', 'max:1200'],
            'caveats' => ['nullable', 'string', 'max:500'],
            'next_options' => ['required', 'string', 'min:5', 'max:'.$maxNext],
            'next_options_chip_texts' => ['required', 'array', 'size:2'],
            'next_options_chip_texts.*' => ['required', 'string', 'min:2', 'max:120'],
        ];

        $validator = Validator::make($data, $rules);

        return [
            'valid' => ! $validator->fails(),
            'data' => $data,
            'errors' => $validator->fails() ? array_values($validator->errors()->all()) : [],
        ];
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
            'framing' => ['nullable', 'string', 'max:'.$maxFraming],
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
            'count_mismatch_explanation' => ['nullable', 'string', 'max:280'],
            'assumptions' => ['nullable', 'array', 'max:4'],
            'assumptions.*' => ['string', 'max:240'],
            'prioritize_variant' => ['nullable', 'string', 'in:rank'],
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

            $coach = trim((string) ($data['doing_progress_coach'] ?? ''));
            $hasDoingCoach = $coach !== '';

            if ($hasDoingCoach) {
                // When doing exists, we unify in-progress coaching and omit framing.
                if ($framing !== '') {
                    $validator->errors()->add('framing', 'framing must be omitted/empty when doing_progress_coach is present.');
                }
            } else {
                // When there is no doing coaching, framing becomes required.
                if ($framing === '' || mb_strlen($framing) < 3) {
                    $validator->errors()->add('framing', 'framing is required when doing_progress_coach is empty or null.');
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

        return [
            'valid' => true,
            'data' => $data,
            'errors' => [],
        ];
    }

    private function validateDailyScheduleData(array $data, array $snapshot): array
    {
        $maxFraming = TaskAssistantPrioritizeOutputDefaults::maxFramingChars();
        $maxReasoning = TaskAssistantPrioritizeOutputDefaults::maxReasoningChars();
        $maxConfirmation = TaskAssistantPrioritizeOutputDefaults::maxNextFieldChars();

        $rules = [
            'proposals' => ['nullable', 'array', 'max:100'],
            'proposals.*.proposal_id' => ['required_with:proposals', 'string', 'max:100'],
            'proposals.*.proposal_uuid' => ['nullable', 'string', 'max:100'],
            'proposals.*.display_order' => ['nullable', 'integer', 'min:0', 'max:500'],
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
            'proposals.*.apply_payload.tool' => ['required_with:proposals.*.apply_payload', 'string', 'max:64', 'in:update_task,update_event,update_project,create_event'],
            'proposals.*.apply_payload.arguments' => ['nullable', 'array'],
            'proposals.*.apply_payload.arguments.title' => ['nullable', 'string', 'max:200'],
            'proposals.*.apply_payload.arguments.description' => ['nullable', 'string', 'max:2000'],
            'proposals.*.apply_payload.arguments.startDatetime' => ['nullable', 'string', 'max:64'],
            'proposals.*.apply_payload.arguments.endDatetime' => ['nullable', 'string', 'max:64'],
            'proposals.*.apply_payload.arguments.updates' => ['nullable', 'array', 'max:10'],
            'proposals.*.apply_payload.arguments.updates.*.property' => ['required_with:proposals.*.apply_payload.arguments.updates', 'string', 'max:64'],
            'proposals.*.apply_payload.arguments.updates.*.value' => ['required_with:proposals.*.apply_payload.arguments.updates'],
            'items' => ['present', 'array', 'max:100'],
            'items.*.title' => ['required', 'string', 'max:200'],
            'items.*.entity_type' => ['required', 'string', 'in:task,event,project'],
            'items.*.entity_id' => ['nullable', 'integer'],
            'items.*.start_datetime' => ['required', 'date'],
            'items.*.end_datetime' => ['nullable', 'date'],
            'items.*.duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'blocks' => ['present', 'array', 'max:48'],
            'blocks.*.start_time' => ['required', 'string', 'max:20'],
            'blocks.*.end_time' => ['required', 'string', 'max:20'],
            'blocks.*.label' => ['nullable', 'string', 'max:160'],
            'blocks.*.task_id' => ['nullable', 'integer'],
            'blocks.*.event_id' => ['nullable', 'integer'],
            'blocks.*.note' => ['nullable', 'string', 'max:300'],
            'schedule_variant' => ['nullable', 'string', 'in:daily,range'],
            'schedule_empty_placement' => ['nullable', 'boolean'],
            'placement_digest' => ['nullable', 'array'],
            'placement_digest.summary' => ['nullable', 'string', 'max:2000'],
            'placement_digest.placement_dates' => ['nullable', 'array', 'max:400'],
            'placement_digest.placement_dates.*' => ['string', 'max:32'],
            'placement_digest.days_used' => ['nullable', 'array', 'max:400'],
            'placement_digest.days_used.*' => ['string', 'max:32'],
            'placement_digest.skipped_targets' => ['nullable', 'array', 'max:200'],
            'placement_digest.unplaced_units' => ['nullable', 'array', 'max:200'],
            'placement_digest.partial_units' => ['nullable', 'array', 'max:200'],
            'placement_digest.fallback_mode' => ['nullable', 'string', 'max:120'],
            'placement_digest.fallback_trigger_reason' => ['nullable', 'string', 'max:120'],
            'placement_digest.suppress_bulk_unplaced_narrative' => ['nullable', 'boolean'],
            'placement_digest.requested_count' => ['nullable', 'integer', 'min:1', 'max:10'],
            'placement_digest.full_placed_count' => ['nullable', 'integer', 'min:0', 'max:200'],
            'placement_digest.partial_placed_count' => ['nullable', 'integer', 'min:0', 'max:200'],
            'placement_digest.count_shortfall' => ['nullable', 'integer', 'min:0', 'max:200'],
            'placement_digest.top_n_shortfall' => ['nullable', 'boolean'],
            'confirmation_required' => ['nullable', 'boolean'],
            'awaiting_user_decision' => ['nullable', 'boolean'],
            'confirmation_context' => ['nullable', 'array'],
            'confirmation_context.reason_code' => ['nullable', 'string', 'max:120'],
            'confirmation_context.requested_count' => ['nullable', 'integer', 'min:1', 'max:10'],
            'confirmation_context.placed_count' => ['nullable', 'integer', 'min:0', 'max:200'],
            'confirmation_context.requested_count_source' => ['nullable', 'string', 'in:explicit_user,system_default'],
            'confirmation_context.reason_message' => ['nullable', 'string', 'max:500'],
            'confirmation_context.requested_window' => ['nullable', 'array'],
            'confirmation_context.attempted_horizon' => ['nullable', 'array'],
            'confirmation_context.fallback_horizon' => ['nullable', 'array'],
            'confirmation_context.prompt' => ['nullable', 'string', 'max:500'],
            'confirmation_context.options' => ['nullable', 'array', 'max:6'],
            'confirmation_context.options.*' => ['string', 'max:160'],
            'fallback_preview' => ['nullable', 'array'],
            'fallback_preview.proposals_count' => ['nullable', 'integer', 'min:0', 'max:200'],
            'fallback_preview.days_used' => ['nullable', 'array', 'max:400'],
            'fallback_preview.days_used.*' => ['string', 'max:32'],
            'fallback_preview.placement_dates' => ['nullable', 'array', 'max:400'],
            'fallback_preview.placement_dates.*' => ['string', 'max:32'],
            'fallback_preview.summary' => ['nullable', 'string', 'max:2000'],
            'framing' => ['required', 'string', 'min:3', 'max:'.$maxFraming],
            'reasoning' => ['required', 'string', 'min:3', 'max:'.$maxReasoning],
            'confirmation' => ['required', 'string', 'min:5', 'max:'.$maxConfirmation],
        ];

        $validator = Validator::make($data, $rules);
        $validator->after(function (ValidationValidator $validator) use ($data): void {
            $framing = trim((string) ($data['framing'] ?? ''));
            $reasoning = trim((string) ($data['reasoning'] ?? ''));
            $confirmation = trim((string) ($data['confirmation'] ?? ''));
            $framingNorm = trim(preg_replace('/\s+/u', ' ', $framing) ?? $framing);
            $reasoningNorm = trim(preg_replace('/\s+/u', ' ', $reasoning) ?? $reasoning);
            if ($framingNorm !== '' && $framingNorm === $reasoningNorm) {
                $validator->errors()->add('reasoning', 'reasoning must not duplicate framing verbatim.');
            }

            $confirmNorm = trim(preg_replace('/\s+/u', ' ', $confirmation) ?? $confirmation);
            if ($framingNorm !== '' && $framingNorm === $confirmNorm) {
                $validator->errors()->add('confirmation', 'confirmation must not duplicate framing verbatim.');
            }

            $proposals = is_array($data['proposals'] ?? null) ? $data['proposals'] : [];
            $items = is_array($data['items'] ?? null) ? $data['items'] : [];
            $blocks = is_array($data['blocks'] ?? null) ? $data['blocks'] : [];

            if (count($items) !== count($proposals)) {
                $validator->errors()->add('items', 'items must have the same length as proposals.');

                return;
            }
            if (count($blocks) !== count($proposals)) {
                $validator->errors()->add('blocks', 'blocks must have the same length as proposals.');

                return;
            }

            foreach ($proposals as $i => $proposal) {
                if (! is_array($proposal) || ! isset($items[$i]) || ! is_array($items[$i])) {
                    continue;
                }
                $item = $items[$i];
                $pTitle = (string) ($proposal['title'] ?? '');
                $iTitle = (string) ($item['title'] ?? '');
                if ($pTitle !== $iTitle) {
                    $validator->errors()->add("items.$i.title", 'item title must match the corresponding proposal title.');

                    return;
                }
                $pType = (string) ($proposal['entity_type'] ?? '');
                $iType = (string) ($item['entity_type'] ?? '');
                if ($pType !== $iType) {
                    $validator->errors()->add("items.$i.entity_type", 'item entity_type must match the proposal.');

                    return;
                }
                $pEid = $proposal['entity_id'] ?? null;
                $iEid = $item['entity_id'] ?? null;
                $pEidNorm = $pEid !== null && $pEid !== '' ? (int) $pEid : null;
                $iEidNorm = $iEid !== null && $iEid !== '' ? (int) $iEid : null;
                if ($pEidNorm !== $iEidNorm) {
                    $validator->errors()->add("items.$i.entity_id", 'item entity_id must match the proposal.');

                    return;
                }
                $pStart = (string) ($proposal['start_datetime'] ?? '');
                $iStart = (string) ($item['start_datetime'] ?? '');
                if ($pStart !== $iStart) {
                    $validator->errors()->add("items.$i.start_datetime", 'item start_datetime must match the proposal.');

                    return;
                }
                $pEnd = $proposal['end_datetime'] ?? null;
                $iEnd = $item['end_datetime'] ?? null;
                $pEndStr = $pEnd !== null && $pEnd !== '' ? (string) $pEnd : null;
                $iEndStr = $iEnd !== null && $iEnd !== '' ? (string) $iEnd : null;
                if ($pEndStr !== $iEndStr) {
                    $validator->errors()->add("items.$i.end_datetime", 'item end_datetime must match the proposal.');

                    return;
                }
                $pDur = $proposal['duration_minutes'] ?? null;
                $iDur = $item['duration_minutes'] ?? null;
                $pDurNorm = $pDur !== null && $pDur !== '' ? (int) $pDur : null;
                $iDurNorm = $iDur !== null && $iDur !== '' ? (int) $iDur : null;
                if ($pDurNorm !== $iDurNorm) {
                    $validator->errors()->add("items.$i.duration_minutes", 'item duration_minutes must match the proposal.');

                    return;
                }
            }

            $seenProposalIds = [];
            $seenProposalUuids = [];
            $hasSchedulableProposal = false;
            $hasPlaceholderProposal = false;

            foreach ($proposals as $i => $proposal) {
                if (! is_array($proposal)) {
                    continue;
                }

                $proposalId = trim((string) ($proposal['proposal_id'] ?? ''));
                if ($proposalId !== '') {
                    if (isset($seenProposalIds[$proposalId])) {
                        $validator->errors()->add("proposals.$i.proposal_id", 'proposal_id must be unique.');

                        return;
                    }
                    $seenProposalIds[$proposalId] = true;
                }

                $proposalUuid = trim((string) ($proposal['proposal_uuid'] ?? ''));
                if ($proposalUuid !== '') {
                    if (isset($seenProposalUuids[$proposalUuid])) {
                        $validator->errors()->add("proposals.$i.proposal_uuid", 'proposal_uuid must be unique when present.');

                        return;
                    }
                    $seenProposalUuids[$proposalUuid] = true;
                }

                $title = trim((string) ($proposal['title'] ?? ''));
                if ($title === 'No schedulable items found') {
                    $hasPlaceholderProposal = true;
                }

                if (($proposal['apply_payload'] ?? null) !== null) {
                    $hasSchedulableProposal = true;
                }
            }

            $scheduleEmptyPlacement = (bool) ($data['schedule_empty_placement'] ?? false);
            if ($scheduleEmptyPlacement && $hasSchedulableProposal) {
                $validator->errors()->add('schedule_empty_placement', 'schedule_empty_placement cannot be true when schedulable proposals exist.');
            }
            if (! $scheduleEmptyPlacement && $hasPlaceholderProposal && ! $hasSchedulableProposal) {
                $validator->errors()->add('schedule_empty_placement', 'schedule_empty_placement must be true when proposals only contain placeholders.');
            }

            foreach ($proposals as $i => $proposal) {
                if (! is_array($proposal)) {
                    continue;
                }
                $ap = $proposal['apply_payload'] ?? null;
                if (! is_array($ap) || ($ap['tool'] ?? '') !== 'create_event') {
                    continue;
                }
                $args = is_array($ap['arguments'] ?? null) ? $ap['arguments'] : [];
                $title = trim((string) ($args['title'] ?? ''));
                $start = trim((string) ($args['startDatetime'] ?? ''));
                $end = trim((string) ($args['endDatetime'] ?? ''));
                if ($title === '' || $start === '' || $end === '') {
                    $validator->errors()->add("proposals.$i.apply_payload", 'create_event apply_payload requires title, startDatetime, and endDatetime.');
                }
            }

            $confirmationRequired = (bool) ($data['confirmation_required'] ?? false);
            if ($confirmationRequired) {
                $confirmationContext = is_array($data['confirmation_context'] ?? null)
                    ? $data['confirmation_context']
                    : [];
                $prompt = trim((string) ($confirmationContext['prompt'] ?? ''));
                if ($prompt === '') {
                    $validator->errors()->add('confirmation_context.prompt', 'confirmation_context.prompt is required when confirmation_required is true.');
                }

                $options = is_array($confirmationContext['options'] ?? null) ? $confirmationContext['options'] : [];
                if ($options === []) {
                    $validator->errors()->add('confirmation_context.options', 'confirmation_context.options is required when confirmation_required is true.');
                }

                $reasonCode = trim((string) ($confirmationContext['reason_code'] ?? ''));
                $expectedOptions = match ($reasonCode) {
                    'top_n_shortfall' => [
                        'Keep this current draft',
                        'Pick another time window',
                        'Cancel scheduling for now',
                    ],
                    'explicit_day_not_feasible' => [
                        'Widen to nearby days',
                        'Cancel scheduling for now',
                    ],
                    'later_window_not_feasible' => [
                        'Yes, continue with tomorrow',
                        'Pick another time window',
                        'Cancel scheduling for now',
                    ],
                    default => [],
                };

                if ($expectedOptions !== []) {
                    foreach ($expectedOptions as $expected) {
                        if (! in_array($expected, $options, true)) {
                            $validator->errors()->add('confirmation_context.options', 'confirmation_context.options must include deterministic options for the reason_code.');
                            break;
                        }
                    }
                }

                $requestedCountSource = (string) ($confirmationContext['requested_count_source'] ?? '');
                if ($requestedCountSource === 'system_default') {
                    $narrativeBlob = implode(' ', [
                        (string) ($data['framing'] ?? ''),
                        (string) ($data['reasoning'] ?? ''),
                        (string) ($data['confirmation'] ?? ''),
                        (string) ($confirmationContext['reason_message'] ?? ''),
                    ]);
                    if (preg_match('/\byou\s+asked\s+for\s+top\s+\d+\b/i', $narrativeBlob) === 1) {
                        $validator->errors()->add('confirmation_context.requested_count_source', 'confirmation narrative must not claim explicit top-N ask when requested_count_source is system_default.');
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
