<?php

namespace App\Services\Llm;

use App\DataTransferObjects\Llm\ContextDto;
use App\DataTransferObjects\Llm\LlmRequestDto;
use App\DataTransferObjects\Llm\ToolCallDto;
use App\DataTransferObjects\Llm\ToolResultDto;

class PromptManagerService
{
    public function __construct(
        private readonly string $schemaVersion,
        private readonly string $timezone,
        private readonly array $allowedTools,
    ) {}

    public function buildRequest(string $message, ContextDto $context): LlmRequestDto
    {
        $payload = $this->buildUserPayload($message, $context);
        $intentHint = $payload['intent_hint'] ?? 'unknown';
        $temperature = $this->resolveTemperatureForHint($intentHint);

        return new LlmRequestDto(
            systemPrompt: $this->buildSystemPrompt(),
            userPayloadJson: json_encode($payload, JSON_UNESCAPED_UNICODE),
            temperature: $temperature,
            maxTokens: (int) config('llm.max_tokens'),
            userPayload: $payload,
            traceId: null,
        );
    }

    private function messageIdRules(bool $useTitlesInMessage, bool $showIdsInMessage): string
    {
        if ($useTitlesInMessage && ! $showIdsInMessage) {
            return '- In user-facing "message", always refer to tasks and events by their titles (e.g. "Physics assignment"), not by raw IDs like "task_31". IDs should only appear inside data/tool_call, not in the message.';
        }

        if ($useTitlesInMessage && $showIdsInMessage) {
            return '- In user-facing "message", refer to tasks by their titles first and optionally include the ID in parentheses when helpful (e.g. "Physics assignment (task_31)"). Never use the ID alone without the title.';
        }

        return '- In user-facing "message", avoid exposing raw IDs like "task_31" unless the user specifically asks for them.';
    }

    public function buildToolFollowUpRequest(
        ToolCallDto $toolCall,
        ToolResultDto $toolResult,
        ContextDto $context,
    ): LlmRequestDto {
        $maxSentences = (int) config('llm.prompt.message.max_sentences', 4);

        $payload = [
            'instruction' => 'The tool was executed. Using the result and the recent conversation, write a short, natural follow-up message for a student that confirms what changed and, when helpful, suggests a concrete next step.',
            'tone' => 'balanced',
            'max_sentences' => $maxSentences,
            'tool_used' => $toolCall->tool,
            'tool_success' => $toolResult->success,
            'result' => $toolResult->payload,
            'tool_error' => $toolResult->errorMessage,
            'last_user_message' => $this->lastUserMessage($context),
            'recent_messages' => array_map(
                fn ($m) => [
                    'role' => $m->role,
                    'text' => $m->text,
                ],
                $context->recentMessages
            ),
            'response_preferences' => [
                'include_reason' => true,
                'include_next_step' => (bool) config('llm.prompt.include_next_steps', true),
                'clarify_if_ambiguous' => (bool) config('llm.prompt.require_clarification_for_ambiguous_time', true),
            ],
        ];

        return new LlmRequestDto(
            systemPrompt: $this->buildSystemPrompt(),
            userPayloadJson: json_encode($payload, JSON_UNESCAPED_UNICODE),
            userPayload: $payload,
            temperature: (float) config('llm.temperature'),
            maxTokens: 256,
        );
    }

    private function buildUserPayload(string $message, ContextDto $context): array
    {
        $topTaskLimit = (int) config('llm.prompt.prioritize_default_limit', 5);
        $messageMinSentences = (int) config('llm.prompt.message.min_sentences', 1);
        $messageMaxSentences = (int) config('llm.prompt.message.max_sentences', 4);
        $reasoningWordLimit = (int) config('llm.prompt.reasoning_word_limit', 25);
        $reasoningWordLimitPrioritize = (int) config('llm.prompt.reasoning_word_limit_for_prioritize', max(40, $reasoningWordLimit));
        $tokenBudget = (int) config('llm.context.token_budget', 2000);

        $tasks = $context->tasks;
        $events = $context->events;
        $projects = $context->projects;

        if ($tokenBudget > 0) {
            $tasks = array_slice($tasks, 0, min(count($tasks), $topTaskLimit));
        }

        return [
            'user_message' => $message,
            'intent_hint' => $this->inferIntentHint($message),
            'current_time' => $context->now->format(\DateTimeInterface::ATOM),
            'timezone' => $this->timezone,
            'summary_mode' => $context->isSummaryMode,
            'context_fingerprint' => $context->fingerprint,
            'user_preferences' => $context->userPreferences,
            'task_summary' => $context->taskSummary,
            'last_user_message' => $context->lastUserMessage,
            'project_summary' => $context->projectSummary,
            'response_preferences' => [
                'style' => config('llm.prompt.default_style', 'balanced'),
                'message_min_sentences' => $messageMinSentences,
                'message_max_sentences' => $messageMaxSentences,
                'prioritize_default_limit' => $topTaskLimit,
                'reasoning_word_limit' => $reasoningWordLimit,
                'reasoning_word_limit_for_prioritize' => $reasoningWordLimitPrioritize,
                'include_next_steps' => (bool) config('llm.prompt.include_next_steps', true),
                'clarify_ambiguous_time' => (bool) config('llm.prompt.require_clarification_for_ambiguous_time', true),
                'use_titles_in_message' => (bool) config('llm.prompt.use_titles_in_message', true),
                'show_rank_numbers' => (bool) config('llm.prompt.show_rank_numbers', true),
                'show_ids_in_message' => (bool) config('llm.prompt.show_ids_in_message', false),
            ],
            'tasks' => array_map(
                fn ($t) => [
                    'id' => "task_{$t->id}",
                    'title' => $t->title,
                    'end_datetime' => $t->dueDate,
                    'priority' => $t->priority,
                    'duration' => $t->estimateMinutes,
                ],
                $tasks
            ),
            'upcoming_events' => array_map(
                fn ($e) => [
                    'id' => "event_{$e->id}",
                    'title' => $e->title,
                    'start_datetime' => $e->startDatetime,
                    'duration_minutes' => $e->durationMinutes,
                ],
                $events
            ),
            'projects' => array_map(
                fn ($p) => [
                    'id' => "project_{$p->id}",
                    'name' => $p->name,
                    'start_date' => $p->startDate,
                    'end_date' => $p->endDate,
                    'active_task_count' => $p->activeTaskCount,
                ],
                $projects
            ),
            'recent_messages' => array_map(
                fn ($m) => [
                    'role' => $m->role,
                    'text' => $m->text,
                ],
                $context->recentMessages
            ),
        ];
    }

    private function inferIntentHint(string $message): string
    {
        $text = mb_strtolower($message);

        if (
            str_contains($text, 'schedule')
            || str_contains($text, 'when should i do')
            || str_contains($text, 'what time')
            || str_contains($text, 'put this on my calendar')
            || str_contains($text, 'block out time')
        ) {
            return 'schedule';
        }

        if (
            str_contains($text, 'prioritize')
            || str_contains($text, 'top')
            || str_contains($text, 'what should i do first')
            || str_contains($text, 'what should i focus on')
            || str_contains($text, 'rank my tasks')
            || str_contains($text, 'most important')
        ) {
            return 'prioritize';
        }

        if (
            str_contains($text, 'create')
            || str_contains($text, 'add a task')
            || str_contains($text, 'new task')
            || str_contains($text, 'make a task')
            || str_contains($text, 'new event')
            || str_contains($text, 'add this to my tasks')
        ) {
            return 'create';
        }

        if (str_contains($text, 'update') || str_contains($text, 'move') || str_contains($text, 'reschedule') || str_contains($text, 'change the due')) {
            return 'update';
        }

        return 'unknown';
    }

    private function buildSystemPrompt(): string
    {
        $base = $this->systemPromptText();

        if (config('llm.prompt.chatml.enabled', false)) {
            return "<|im_start|>system\n{$base}\n<|im_end|>";
        }

        return $base;
    }

    private function resolveTemperatureForHint(string $intentHint): float
    {
        $base = (float) config('llm.temperature');
        $tuning = config('llm.prompt.intent_tuning', []);

        if ($intentHint !== 'unknown' && isset($tuning[$intentHint])) {
            return (float) $tuning[$intentHint];
        }

        return $base;
    }

    private function systemPromptText(): string
    {
        $version = $this->schemaVersion;
        $tz = $this->timezone;
        $toolList = implode(', ', $this->allowedTools);
        $modelName = (string) config('llm.model', 'hermes3:3b');
        $messageMinSentences = (int) config('llm.prompt.message.min_sentences', 1);
        $messageMaxSentences = (int) config('llm.prompt.message.max_sentences', 4);
        $reasoningWordLimit = (int) config('llm.prompt.reasoning_word_limit', 25);
        $prioritizeDefaultLimit = (int) config('llm.prompt.prioritize_default_limit', 5);
        $reasoningWordLimitPrioritize = (int) config('llm.prompt.reasoning_word_limit_for_prioritize', 50);
        $assistantStyle = (string) config('llm.prompt.default_style', 'balanced');
        $requireClarification = (bool) config('llm.prompt.require_clarification_for_ambiguous_time', true);
        $allowInlineBullets = (bool) config('llm.prompt.allow_inline_bullets', true);
        $includeNextSteps = (bool) config('llm.prompt.include_next_steps', true);
        $useTitlesInMessage = (bool) config('llm.prompt.use_titles_in_message', true);
        $showRankNumbers = (bool) config('llm.prompt.show_rank_numbers', true);
        $showIdsInMessage = (bool) config('llm.prompt.show_ids_in_message', false);
        $bulletsRule = $allowInlineBullets
            ? 'You may use short inline separators ("First..., then...") when it improves clarity.'
            : 'Do not use bullets or list formatting in the message.';
        $nextStepRule = $includeNextSteps
            ? 'When useful, end with one concrete next step.'
            : 'Do not add extra next-step suggestions unless explicitly asked.';
        $clarifyRule = $requireClarification
            ? 'If date/time is ambiguous (e.g., "later evening"), ask a precise clarifying question before proposing a write action.'
            : 'If date/time is ambiguous, make a best-effort assumption using context and mention the assumption clearly.';
        $domainGuardrails = config('llm.prompt.domain_guardrails', []);
        $blockPolitics = (bool) ($domainGuardrails['block_politics'] ?? false);
        $blockOutOfScope = (bool) ($domainGuardrails['block_out_of_scope_qa'] ?? false);

        $domainRules = [];
        if ($blockPolitics) {
            $domainRules[] = '- For political, ideological, or public-figure opinion questions (e.g. "Who is the best president ever?"), do NOT answer directly. Use intent:"general" or intent:"error", explain briefly that you only help with study, tasks, and planning, and never create tool calls.';
        }
        if ($blockOutOfScope) {
            $domainRules[] = '- For questions clearly unrelated to study, tasks, planning, or projects (e.g. celebrity gossip, random trivia), respond with intent:"general" or intent:"error" and a short message that you are focused on productivity support only.';
        }
        $domainRulesText = implode("\n", $domainRules);

        return <<<PROMPT
You are **{$modelName}**, a warm, practical Task Assistant for students in a personal productivity app.
You run behind the scenes inside a task manager. Interpret user messages about tasks, events, prioritization, and planning, then return a SINGLE JSON object that matches the canonical envelope.
You are very good at following instructions, keeping track of context across turns, and calling tools when they are clearly needed.
Never roleplay, never speculate about your own training, and never ignore safety rules.

OUTPUT CONTRACT (must follow exactly)
- Output **only one** JSON object using the canonical envelope below.
- **No markdown. No code fences. No XML. No commentary** before or after the JSON.
- Do not include explanations of your reasoning in the JSON fields; keep reasoning internal.

GOAL
- Help students decide what to do next with clear, grounded guidance.
- Keep responses useful, natural, and supportive while staying concise.

INTENT SELECTION (choose carefully)
- Always choose the intent that best matches the USER'S request, not just the available context.
- Use intent:"prioritize" only when the user explicitly asks to rank, order, list, or pick "what to do first" among tasks.
- Use intent:"schedule" when the user is asking WHEN to do something, or to put work into a specific time window.
- Use intent:"create" when the user is asking to add a new task or event that does not already exist in context.
- Use intent:"update" when the user is asking to change details of an existing task (title, due date, duration, etc.) that you can clearly identify from context.
- Use intent:"list" when the user wants a filtered set of tasks (e.g. due today, this week, high priority) without a full ranking.
- Use intent:"general" when the user is chatting about goals or productivity in a way that does not clearly request schedule/create/update/prioritize/list.
- Use intent:"clarify" when you need more information (e.g. date/time, which task) before you can safely schedule, create or update.
- Use intent:"error" only when you truly cannot interpret the request or produce valid JSON.

RESPONSE STYLE (adaptive)
- Default style is "{$assistantStyle}".
- Tone adapts to user intent: scheduling = precise, prioritization = decisive, uncertainty = clarifying, motivation = encouraging but practical.
- "message" should be {$messageMinSentences}-{$messageMaxSentences} sentences, unless the user asks for very short output.
- Include a short reason when recommending tasks (<= {$reasoningWordLimit} words, or <= {$reasoningWordLimitPrioritize} words for prioritize/list intents).
- {$nextStepRule}
- {$bulletsRule}
- Vary your phrasing between turns; avoid repeating the exact same sentence templates when the user asks similar questions.
{$this->messageIdRules($useTitlesInMessage, $showIdsInMessage)}

PRIORITIZE & LIST INTENTS (ranking style)
- For intent:"prioritize" and intent:"list":
  - data.ranked_ids MUST contain the internal IDs like "task_31" in priority order.
  - In the user-facing "message", talk about tasks by their titles first (e.g. "Physics assignment"), not by raw ID.
  - If needed, you MAY mention the ID in parentheses after the title (e.g. "Physics assignment (task_31)").
  - Present an ordered ranking using the titles, ideally with rank numbers, for example:
    - "1) Physics assignment — due soonest tonight, high priority."
    - "2) ITCS 101 – Midterm Project Checkpoint — upcoming deadline, medium duration."
  - Explain the ranking in 2–3 short sentences overall, mentioning deadlines, priority, and workload tradeoffs.

CONSTRAINTS (must never be violated)
- Return only valid JSON matching the canonical envelope exactly.
- Never fabricate IDs, timestamps, or entities. If an ID is unknown, return intent:"clarify" or intent:"error".
- The model proposes only — it MUST NOT execute writes. Tool calls are proposals; writes are done server-side.
- Dates/times: ISO 8601 with offset (e.g. "2026-03-14T19:00:00+08:00"). Assume {$tz} for ambiguous relative times.
- Allowed tool names: {$toolList}. Do not invent other tool names.
- For missing/ambiguous required fields: return intent:"clarify" with data.questions. Do NOT create a tool_call.
- {$clarifyRule}
- For scheduling: if the slot conflicts with an existing event in context, return intent:"clarify" with alternatives.
 - The user payload may include an "intent_hint" field (e.g. "schedule", "prioritize", "create", "update", "unknown"). This is advisory only; you MUST override it when the actual user intent differs.
{$domainRulesText}
 - The server may override tool_call and message fields for safety or domain reasons; always assume the backend is the final authority on writes and user-facing text.

CANONICAL ENVELOPE (always return this exact shape)
{
  "schema_version": "{$version}",
  "intent": "schedule|create|update|prioritize|list|general|clarify|error",
  "data": {},
  "tool_call": null | {
    "tool": "create_task|update_task|create_event",
    "args": {},
    "client_request_id": "req-<uuid>",
    "confirmation_required": false
  },
  "message": "natural user-facing text ({$messageMinSentences}-{$messageMaxSentences} sentences)",
  "meta": { "confidence": 0.0 }
}

IMPORTANT TOOL-CALLING RULES
- Only include a non-null "tool_call" when the user is clearly asking you to create or update data and you have all required arguments.
- Prefer intent:"clarify" with data.questions instead of guessing IDs, dates, or durations.
- For **schedule**:
  - If you are proposing concrete calendar blocks, you may use tool:"create_event" with explicit datetimes, or you may just return "scheduled_items" in data without a tool_call when the app should not write yet.
- For **create**:
  - Use tool:"create_task" when the user clearly wants a new task saved and you know title plus at least one of end_datetime/duration.
  - Use tool:"create_event" for calendar events with explicit start and end datetimes.
- For **update**:
  - Only use tool:"update_task" if the task ID can be matched to an existing task in context; otherwise use intent:"clarify" first.
- Set "confirmation_required": true only for destructive or bulk actions; otherwise, leave it false.

INTENT DATA SHAPES
- schedule:   { "scheduled_items": [{ "id": "task_<N>", "start_datetime": "ISO8601", "end_datetime": "ISO8601" }] }
- create:     { "title": "string", "description": "string|null", "end_datetime": "ISO8601|null", "duration": int|null }
- update:     { "id": "task_<N>", "fields": { "title"?: "...", "end_datetime"?: "ISO8601", "duration"?: int } }
- prioritize: { "ranked_ids": ["task_3","task_1"], "reason": "<= {$reasoningWordLimitPrioritize} words" }
- list:       { "filter": "due_today|next_7_days|high_priority", "limit": {$prioritizeDefaultLimit} }
- clarify:    { "questions": [{ "id": "q1", "text": "..." }] }
- error:      { "code": "PARSE_ERROR|VALIDATION_ERROR|UNKNOWN_ENTITY", "details": "internal-safe text" }

TOOL DEFINITIONS
1) create_task    args: title(req), description, end_datetime(ISO8601), duration, client_request_id(req)
2) update_task    args: id(req), fields:{title?,description?,end_datetime?,duration?}, client_request_id(req)
3) create_event   args: title(req), start_datetime(ISO8601+offset,req), end_datetime(ISO8601+offset,req), all_day(false), client_request_id(req)
   Set confirmation_required:true for destructive/bulk operations.

CONFIDENCE: always output meta.confidence 0.0–1.0.

ON JSON FAILURE: return {"schema_version":"{$version}","intent":"error","data":{"code":"PARSE_ERROR","details":"Failed to produce valid JSON."},"tool_call":null,"message":"Sorry, I couldn't understand that. Can you rephrase?","meta":{"confidence":0}}

SAFETY: never expose secrets, passwords, or PII. All external calls are server-side only.
ENDING RULE: return the canonical envelope only.

EXAMPLES (follow structure exactly; adapt content to the user)

// Example A: prioritize without tool_call
{
  "schema_version": "{$version}",
  "intent": "prioritize",
  "data": {
    "ranked_ids": ["task_31", "task_12"],
    "reason": "Physics assignment is due tonight and the project is due later this week."
  },
  "tool_call": null,
  "message": "Start with your Physics assignment because it is due tonight and carries more weight, then move on to your ITCS 101 project checkpoint.",
  "meta": { "confidence": 0.86 }
}

// Example B: schedule with create_event tool_call
{
  "schema_version": "{$version}",
  "intent": "schedule",
  "data": {
    "scheduled_items": [{
      "id": "task_31",
      "start_datetime": "2026-03-13T19:00:00+08:00",
      "end_datetime": "2026-03-13T20:00:00+08:00"
    }]
  },
  "tool_call": {
    "tool": "create_event",
    "args": {
      "title": "Physics assignment focus block",
      "start_datetime": "2026-03-13T19:00:00+08:00",
      "end_datetime": "2026-03-13T20:00:00+08:00",
      "all_day": false
    },
    "client_request_id": "req-uuid",
    "confirmation_required": false
  },
  "message": "I scheduled a one-hour block this evening for your Physics assignment so you can finish it before it is due.",
  "meta": { "confidence": 0.88 }
}
PROMPT;
    }

    private function lastUserMessage(ContextDto $context): ?string
    {
        $messages = array_reverse($context->recentMessages);

        foreach ($messages as $message) {
            if ($message->role === 'user') {
                return $message->text;
            }
        }

        return null;
    }
}
