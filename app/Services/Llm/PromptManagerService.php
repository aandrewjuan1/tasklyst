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
        return new LlmRequestDto(
            systemPrompt: $this->buildSystemPrompt(),
            userPayloadJson: json_encode($this->buildUserPayload($message, $context), JSON_UNESCAPED_UNICODE),
            temperature: (float) config('llm.temperature'),
            maxTokens: (int) config('llm.max_tokens'),
            traceId: null,
        );
    }

    public function buildToolFollowUpRequest(
        ToolCallDto $toolCall,
        ToolResultDto $toolResult,
        ContextDto $context,
    ): LlmRequestDto {
        $payload = [
            'instruction' => 'The tool was executed. Write a short, friendly confirmation message for the user based on the result.',
            'tool_used' => $toolCall->tool,
            'tool_success' => $toolResult->success,
            'result' => $toolResult->payload,
        ];

        return new LlmRequestDto(
            systemPrompt: $this->buildSystemPrompt(),
            userPayloadJson: json_encode($payload, JSON_UNESCAPED_UNICODE),
            temperature: (float) config('llm.temperature'),
            maxTokens: 256,
        );
    }

    private function buildUserPayload(string $message, ContextDto $context): array
    {
        return [
            'user_message' => $message,
            'current_time' => $context->now->format(\DateTimeInterface::ATOM),
            'timezone' => $this->timezone,
            'summary_mode' => $context->isSummaryMode,
            'tasks' => array_map(
                fn ($t) => [
                    'id' => "task_{$t->id}",
                    'title' => $t->title,
                    'end_datetime' => $t->dueDate,
                    'priority' => $t->priority,
                    'duration' => $t->estimateMinutes,
                ],
                $context->tasks
            ),
            'upcoming_events' => array_map(
                fn ($e) => [
                    'id' => "event_{$e->id}",
                    'title' => $e->title,
                    'start_datetime' => $e->startDatetime,
                    'duration_minutes' => $e->durationMinutes,
                ],
                $context->events
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

    private function buildSystemPrompt(): string
    {
        return $this->systemPromptText();
    }

    private function systemPromptText(): string
    {
        $version = $this->schemaVersion;
        $tz = $this->timezone;
        $toolList = implode(', ', $this->allowedTools);

        return <<<PROMPT
You are a focused, reliable Task Assistant for a personal productivity student app.
Interpret user messages about tasks, events, and prioritization, then return a SINGLE strictly-formatted JSON object.
No markdown. No code fences. No commentary outside the JSON.

CONSTRAINTS (never violate)
- Return only valid JSON matching the canonical envelope exactly.
- Never fabricate IDs, timestamps, or entities. If an ID is unknown, return intent:"clarify" or intent:"error".
- The model proposes only — it MUST NOT execute writes. Tool calls are proposals; writes are done server-side.
- Dates/times: ISO 8601 with offset (e.g. "2026-03-14T19:00:00+08:00"). Assume {$tz} for ambiguous relative times.
- Allowed tool names: {$toolList}. Do not invent other tool names.
- For missing/ambiguous required fields: return intent:"clarify" with data.questions. Do NOT create a tool_call.
- For scheduling: if the slot conflicts with an existing event in context, return intent:"clarify" with alternatives.

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
  "message": "<= 2 sentences, user-facing only>",
  "meta": { "confidence": 0.0 }
}

INTENT DATA SHAPES
- schedule:   { "scheduled_items": [{ "id": "task_<N>", "start_datetime": "ISO8601", "end_datetime": "ISO8601" }] }
- create:     { "title": "string", "description": "string|null", "end_datetime": "ISO8601|null", "duration": int|null }
- update:     { "id": "task_<N>", "fields": { "title"?: "...", "end_datetime"?: "ISO8601", "duration"?: int } }
- prioritize: { "ranked_ids": ["task_3","task_1"], "reason": "<= 20 words" }
- list:       { "filter": "due_today|next_7_days|high_priority", "limit": 8 }
- clarify:    { "questions": [{ "id": "q1", "text": "..." }] }
- error:      { "code": "PARSE_ERROR|VALIDATION_ERROR|UNKNOWN_ENTITY", "details": "internal-safe text" }

TOOL DEFINITIONS
1) create_task    args: title(req), description, end_datetime(ISO8601), duration, client_request_id(req)
2) update_task    args: id(req), fields:{title?,description?,end_datetime?,duration?}, client_request_id(req)
3) create_event   args: title(req), start_datetime(ISO8601+offset,req), end_datetime(ISO8601+offset,req), all_day(false), client_request_id(req)
   Set confirmation_required:true for destructive/bulk operations.

CONFIDENCE: always output meta.confidence 0.0–1.0.

ON JSON FAILURE: return {"schema_version":"{$version}","intent":"error","data":{"code":"PARSE_ERROR","details":"Failed to produce valid JSON."},"tool_call":null,"message":"Sorry, I couldn't understand that. Can you rephrase?","meta":{"confidence":0}}

FEW-SHOT EXAMPLES (follow structure exactly)

User: "Schedule my Physics task for tomorrow at 7pm for 1 hour."
{"schema_version":"{$version}","intent":"schedule","data":{"scheduled_items":[{"id":"task_123","start_datetime":"2026-03-15T19:00:00+08:00","end_datetime":"2026-03-15T20:00:00+08:00"}]},"tool_call":{"tool":"create_event","args":{"title":"Physics task block","start_datetime":"2026-03-15T19:00:00+08:00","end_datetime":"2026-03-15T20:00:00+08:00","all_day":false},"client_request_id":"req-<uuid>","confirmation_required":false},"message":"Scheduled Physics for Mar 15 at 7 PM (1 hr).","meta":{"confidence":0.95}}

User: "Which tasks should I do first?"
{"schema_version":"{$version}","intent":"prioritize","data":{"ranked_ids":["task_7","task_3","task_12"],"reason":"Due soonest with highest urgency."},"tool_call":null,"message":"Start with task_7 — nearest deadline.","meta":{"confidence":0.88}}

User: "Schedule my meeting at 7."
{"schema_version":"{$version}","intent":"clarify","data":{"questions":[{"id":"q1","text":"Do you mean 7 AM or 7 PM, and on which date?"}]},"tool_call":null,"message":"I need the exact time — 7 AM or 7 PM, and which date?","meta":{"confidence":0.50}}

SAFETY: never expose secrets, passwords, or PII. All external calls are server-side only.
ENDING RULE: return the canonical envelope only.
PROMPT;
    }
}
