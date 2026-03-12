# LLM Assistant Backend Architecture (Services / Actions / DTOs)

**Stack:** Laravel 12, Livewire 4, PrismPHP, Ollama (`hermes3:3b`)

> Scope: this document describes the **backend** architecture for the LLM assistant only.  
> UI (chat flyout, optimistic UI, etc.) is handled separately in Livewire/Alpine guides.

---

## 1. Goals

1. **Isolate the LLM layer** behind clear application boundaries (services, actions, DTOs), so the rest of the domain (`Tasks`, `Events`, `Schedules`, `Pomodoro`, etc.) stays unchanged.
2. **Use PrismPHP → Ollama (`hermes3:3b`)** with strict schemas / tools to keep responses deterministic, safe, and easy to audit.
3. **Follow PrismPHP and Ollama/Hermes 3:3B best practices and conventions** for model configuration, schema/tool definitions, context sizing, and error handling (as summarized in this document).
4. **Keep all side‑effects deterministic:** database writes always go through existing domain actions; the model only proposes, never writes directly.
5. **Plan for model limits and failures:** treat schema violations, truncation, and ambiguity as first‑class errors with repair / fallback strategies rather than “best‑effort” guesses.
6. **Make the assistant easy to test:** pure services with DTOs, Pest tests around each layer, and no hard coupling to Livewire.

---

## 2. High‑level flow (per message)

```text
Livewire Chat Component
    ↓ (dispatches a service call, not coupled to LLM details)
LlmChatService               app/Services/Llm/LlmChatService.php
    ├─ BuildContextAction    app/Actions/Llm/BuildContextAction.php
    ├─ PromptManagerService  app/Services/Llm/PromptManagerService.php
    ├─ CallLlmAction         app/Actions/Llm/CallLlmAction.php
    ├─ PostProcessorService  app/Services/Llm/PostProcessorService.php
    ├─ ToolExecutorService   app/Services/Llm/ToolExecutorService.php
    └─ RecommendationDisplayDto  app/DataTransferObjects/Ui/RecommendationDisplayDto.php
         ↓
Livewire view consumes RecommendationDisplayDto
```

**Key constraints:**
- **1 LLM round‑trip per user message**, plus **one optional follow‑up** after tool execution to craft the final reply.
- **Structured JSON contract** from the model, validated server‑side against DTOs / schemas.
- **Tool execution** is strictly whitelisted and performed only through existing domain actions.

### Why this design is strong

- **Schema/tool‑first approach:** tools are registered in PrismPHP and the outer JSON envelope is validated server‑side, which reduces hallucination risk and makes outputs auditable.
- **Single orchestrator + DTOs:** `LlmChatService` (or `ChatService` in lower‑level examples) plus DTOs keeps the LLM layer isolated, composable, and easy to test.
- **Explicit repair & cascade rules:** a single `RetryRepairAction` and “hard failure → clarify or escalate” policy is safer than silent retries.
- **Idempotency & concurrency:** `client_request_id` on all tool calls plus a DB uniqueness constraint avoids duplicate writes in concurrent scenarios.
- **Observability:** explicit metrics and `trace_id` make regressions and schema drift visible early.
- **Disciplined context:** summaries, fingerprints, and an optional RAG hook keep token use bounded, which is especially important for a 3B model.

---

## 3. Directory & naming conventions

Follow Laravel Boost conventions: thin **Actions** for application operations, **Services** for orchestration and reusable logic, **DTOs** for boundaries.

```text
app/
  Actions/
    Llm/
      BuildContextAction.php
      CallLlmAction.php
      RetryRepairAction.php
    Tool/
      CreateTaskAction.php
      UpdateTaskAction.php
      CreateScheduleAction.php

  Services/
    Llm/
      LlmChatService.php
      ContextBuilderService.php
      PromptManagerService.php
      PostProcessorService.php
      ToolExecutorService.php

  DataTransferObjects/
    Llm/
      ContextDto.php
      LlmRequestDto.php
      LlmResponseDto.php
      ToolCallDto.php
    Ui/
      RecommendationDisplayDto.php

  Livewire/
    Chat/
      ChatFlyout.php          // uses LlmChatService
```

- **Actions** live under `app/Actions/...` and are **invokable classes** that do one thing.
- **Services** live under `app/Services/Llm` and orchestrate multiple actions / DTOs.
- **DTOs** live under `app/DataTransferObjects/...` and never know about Eloquent.

---

## 4. Orchestration: `LlmChatService`

**File:** `app/Services/Llm/LlmChatService.php`  
**Responsibility:** single entry point for the whole LLM assistant from the rest of the app.

**Public API (example):**

```php
public function handle(User $user, string $message): RecommendationDisplayDto
```

**Flow inside:**

1. **Build context**
   - Calls `BuildContextAction` → returns `ContextDto`.
2. **Build LLM request**
   - Asks `PromptManagerService` to build `LlmRequestDto` (system prompt + JSON user payload).
3. **Call model**
   - Invokes `CallLlmAction` with `LlmRequestDto` → raw model output (string + meta).
4. **Post‑process**
   - Uses `PostProcessorService` to parse / validate → `LlmResponseDto`.
5. **Optional tool execution**
   - If `LlmResponseDto` contains a valid `ToolCallDto`, delegates to `ToolExecutorService`.
   - Optionally performs **one more** LLM call with the tool result to produce a richer final message.
6. **Prepare UI DTO**
   - Maps final `LlmResponseDto` (+ optional tool result) into `RecommendationDisplayDto` for Livewire.

This keeps **Livewire components** thin: they only talk to `LlmChatService` and consume a single DTO.

---

## 5. Context layer

### 5.1 `BuildContextAction`

**File:** `app/Actions/Llm/BuildContextAction.php`  
**Responsibility:** coordinate fetching of minimal, relevant state for the current user and message.

Implementation uses `ContextBuilderService` to encapsulate query logic.

```php
public function __invoke(User $user, string $message): ContextDto
```

**Context rules:**
- Always include:
  - `now` (server time; configured timezone, e.g. Asia/Manila)
  - Top `N` active tasks (config, default 8)
  - Upcoming events (e.g. next 24 hours)
  - Recent chat history (e.g. last 4 user/assistant turns, if stored)
- If the user or previous messages reference a specific entity (`"that physics task"`), **eager‑load** it explicitly.
- When user has **many tasks** (e.g. `> 50`), switch to **summary mode**: counts, nearest deadlines, top 3 urgent items instead of full lists.
- Include a **deterministic fingerprint** (e.g. hash / short checksum) of recently created IDs and their local aliases so `PostProcessorService` can validate references without extra DB round‑trips.

> Optional future enhancement: add a small **RAG layer** that fetches only the most relevant stored snippets (e.g. extended task notes, help docs) and injects them into `ContextDto` as a separate `knowledge` field instead of sending full documents.

### 5.2 `ContextDto`

**File:** `app/DataTransferObjects/Llm/ContextDto.php`

Shape (conceptual):

```php
final class ContextDto
{
    public function __construct(
        public readonly DateTimeImmutable $now,
        /** @var list<TaskContextItem> */
        public readonly array $tasks,
        /** @var list<EventContextItem> */
        public readonly array $events,
        /** @var list<ConversationTurn> */
        public readonly array $recentMessages,
        public readonly array $userPreferences = [],
        public readonly ?ContextKnowledgeDto $knowledge = null,
        public readonly ?string $fingerprint = null,
    ) {}
}
```

DTOs like `TaskContextItem`, `EventContextItem`, `ConversationTurn` can live under `App\DataTransferObjects\Llm`.

---

## 6. Prompt & schema layer

### 6.1 `PromptManagerService`

**File:** `app/Services/Llm/PromptManagerService.php`  
**Responsibility:** centralize:

- Assistant **persona** and tone.
- Hard **guardrails** (never invent IDs, no scheduling in the past, respect explicit times).
- **Output schema** per intent (as JSON schemas or PrismPHP tool definitions).
- **Few‑shot examples** for key intents:
  - `schedule`, `prioritize`, `create`, `update`, `list`, `general_query`.

**Key methods (example):**

```php
public function buildRequest(string $message, ContextDto $context): LlmRequestDto;

public function buildToolFollowUpRequest(
    ToolCallDto $toolCall,
    ToolResultDto $toolResult,
    ContextDto $context,
): LlmRequestDto;
```

Both methods return `LlmRequestDto`, which encapsulates:

- `systemPrompt` — full system instructions string.
- `userPayload` — compact JSON string containing the user message + context.
- Optional metadata (trace id, intent hint, etc.).

### 6.2 `LlmRequestDto`

**File:** `app/DataTransferObjects/Llm/LlmRequestDto.php`

Contains:

- `string $systemPrompt`
- `string $userPayloadJson`
- `array $options` (temperature, max tokens, etc.)

### 6.3 Common output envelope

Regardless of intent, the model should return the same outer shape:

```json
{
  "schema_version": "2026-03-01.v1",
  "intent": "schedule | create | update | prioritize | list | general",
  "data": {},
  "tool_call": null,
  "message": "",
  "meta": {
    "confidence": 0.0
  }
}
```

Intent‑specific structures (`data` keys) live in the prompt and in **PHP DTO validation** (see `PostProcessorService`).  
`schema_version` allows safe prompt/schema evolution; the server must assert that the received `schema_version` matches the expected one.  
`meta.confidence` is an internal float \(0..1\) produced by `PostProcessorService` and used only for routing/observability.

### 6.4 Schema versioning

- Maintain a constant like `LlmSchema::CURRENT_VERSION = '2026-03-01.v1'`.
- On every response, `PostProcessorService` must:
  - Require `schema_version` at the top level.
  - Reject or down‑route responses whose `schema_version` does not equal the current value (log as schema drift).
- When you roll schema or prompt changes, bump the version and deploy:
  - DTO/schema changes.
  - Prompt examples.
  - Any consumers that interpret `data`.

When using PrismPHP, prefer **schema / tool definitions** (`withSchema()`, `withTools()`) instead of purely prompt‑based contracts whenever possible.

### 6.5 System prompt (ready‑to‑use for PrismPHP → Ollama hermes3:3b)

Use the following as the **system prompt** (or PrismPHP system role) in `PromptManagerService`.  
It assumes: Laravel 12, PrismPHP, Ollama, Hermes 3 3B, server timezone **Asia/Manila (+08:00)**.

```text
You are a focused, reliable Task Assistant for a personal productivity app.
Your job is to interpret user messages about tasks, schedules, and prioritization and return a single, strictly-formatted JSON object that proposes an intent, structured data, optional tool call (for server execution), and a short human message.

OPERATING PRINCIPLES
1. Persona & tone
   - Helpful, concise, courteous, and deterministic.
   - Use plain language (short sentences). When explaining, give a one-line rationale max.
   - Prioritize safety and clarity over creativity.

2. Primary constraints (must always follow)
   - Return only valid JSON that exactly matches the canonical envelope. Do not return markdown, code fences, or extra commentary.
   - Never fabricate IDs, timestamps, or database entities. If a user references an unknown id, set an error or request clarification.
   - The model proposes only — it MUST NOT perform database writes. Tool calls are suggestions; actual writes are executed by the server.
   - Date/time values: always use ISO 8601 with timezone offset (e.g., "2026-03-14T19:00:00+08:00"). Assume Asia/Manila (+08:00) when the user gives ambiguous relative times and clarify when necessary.
   - Use conservative defaults: be explicit about assumptions (e.g., assumed timezone/work hours) in the message field.
   - Tools allowed: only the whitelisted tool names in tool_call.tool. If a requested tool is not whitelisted, respond with an error and a clarifying message.

3. When to ask questions
   - If required fields are missing or ambiguous (which would prevent safe execution), do NOT create a tool call. Instead return intent: "clarify" with data.questions — an array of short, specific questions to ask the user.
   - Example clarifications: ambiguous time ("Do you mean 7 AM or 7 PM?"), missing task id, unclear duration.

OUTPUT: canonical JSON envelope (always)
{
  "schema_version": "2026-03-01.v1",
  "intent": "schedule|create|update|prioritize|list|general|clarify|error",
  "data": { /* intent-specific object */ },
  "tool_call": null | {
     "tool": "create_task|update_task|create_schedule",
     "args": { /* tool-specific args */ },
     "client_request_id": "req-<uuid>",
     "confirmation_required": false
  },
  "message": "short user-facing explanation (<= 2 sentences)",
  "meta": {
    "confidence": 0.0
  }
}

REQUIRED RULES FOR THE JSON
- Top-level keys must exist exactly as named above.
- `schema_version` must be present and equal to the current server‑expected version.
- `intent` must be one of the allowed values.
- `message` must always be present (short, user-facing — do not include raw debug).
- If `tool_call` is present it must include `tool`, `args`, and a non‑empty `client_request_id`. `confirmation_required` must be set to `true` for destructive or high‑risk operations so the UI can obtain explicit user confirmation before execution.
- `meta.confidence` must be present and between 0.0 and 1.0.
- `data` must match intent-specific shape below.

INTENT-SPECIFIC SHAPES (examples — server also validates)
- schedule:
  "data": {
    "scheduled_items": [
      {
        "id": "task_<id>",                // must match an id from context; do not invent
        "start_datetime": "ISO8601+08:00",
        "duration_minutes": 30           // integer > 0
      }
    ]
  }

- create:
  "data": {
    "title": "string (max 200 chars)",
    "description": "string (optional)",
    "due_date": "YYYY-MM-DD (optional)",
    "estimate_minutes": 60 (optional integer)
  }

- update:
  "data": {
    "id": "task_<id>",
    "fields": { "title"?: "...", "due_date"?: "YYYY-MM-DD", "estimate_minutes"?: 45 }
  }

- prioritize:
  "data": {
    "ranked_ids": ["task_3","task_1","task_7"],
    "reason": "short rationale (<= 20 words) optional"
  }

- list:
  "data": {
    "filter": "e.g., 'due_today' | 'next_7_days' | 'high_priority'",
    "limit": 8
  }

- clarify:
  "data": {
    "questions": [
      { "id": "q1", "text": "Is 7 AM or 7 PM?" }
    ]
  }

- error:
  "data": { "code": "PARSE_ERROR|VALIDATION_ERROR|UNKNOWN_ENTITY", "details": "short internal-safe text" }

TOOL DEFINITIONS (what you may request; do not invent others)
1) create_task
   args:
     - title (string, required)
     - description (string, optional)
     - due_date (YYYY-MM-DD, optional)
     - estimate_minutes (int, optional)
     - client_request_id (string, required)
     - confirmation_required (bool, optional; defaults to false)

2) update_task
   args:
     - id (existing task id, required)
     - fields (object with allowed keys: title, description, due_date, estimate_minutes)
     - client_request_id (string, required)
     - confirmation_required (bool, optional; defaults to false)

3) create_schedule
   args:
     - id (task id to schedule, required)
     - start_datetime (ISO8601 with offset, required)
     - duration_minutes (int > 0, required)
     - client_request_id (string, required)
     - confirmation_required (bool, optional; defaults to false)

GUIDELINES FOR TOOL_CALL DECISION
- Only produce a tool_call when: all required args are present, datetimes are parseable and not in the past (relative to server time Asia/Manila), and referenced IDs exist in context. If any check fails, set tool_call to null and either return an error intent or clarify.
- For schedule operations: if the requested slot conflicts with an existing event in context, do not make the tool_call — instead return intent: "clarify" with a conflict question and suggested alternative slots in data.

CONFIDENCE & REPAIRS
- You must always output a numeric `meta.confidence` between 0.0 and 1.0 that reflects how confident you are that the `intent` and `data` are correct and complete.
- If you detect JSON formatting issues internally, attempt to output the canonical envelope despite ambiguity. If you cannot produce valid JSON that meets the schema, return:
  {
    "intent":"error",
    "data":{"code":"PARSE_ERROR","details":"Could not produce valid JSON for the request."},
    "tool_call":null,
    "message":"Sorry — I couldn't understand that. Can you rephrase?"
  }

FEW-SHOT EXAMPLES (exact outputs required; follow structure)

1) User: "Schedule my Physics task for tomorrow at 7pm for 1 hour."
Output:
{
  "intent":"schedule",
  "data":{
    "scheduled_items":[
      {"id":"task_123","start_datetime":"2026-03-15T19:00:00+08:00","duration_minutes":60}
    ]
  },
  "tool_call":{
    "tool":"create_schedule",
    "args":{"id":"task_123","start_datetime":"2026-03-15T19:00:00+08:00","duration_minutes":60},
    "client_request_id":"req-<uuid>"
  },
  "message":"Scheduled 'Physics' for Mar 15, 2026 at 7:00 PM (1 hr)."
}

2) User: "Make a task: Read chapter 4, due next Friday."
Output (create):
{
  "intent":"create",
  "data":{"title":"Read chapter 4","due_date":"2026-03-20"},
  "tool_call":{
    "tool":"create_task",
    "args":{"title":"Read chapter 4","due_date":"2026-03-20"},
    "client_request_id":"req-<uuid>"
  },
  "message":"Created task 'Read chapter 4' due Mar 20, 2026."
}

3) User: "Which tasks should I do first?"
Output (prioritize):
{
  "intent":"prioritize",
  "data":{"ranked_ids":["task_7","task_3","task_12"],"reason":"Due soonest and highest urgency."},
  "tool_call":null,
  "message":"I recommend doing task_7 first (nearest deadline)."
}

4) Ambiguous time
User: "Schedule my meeting at 7."
Output (clarify):
{
  "schema_version":"2026-03-01.v1",
  "intent":"clarify",
  "data":{"questions":[{"id":"q1","text":"Do you mean 7 AM or 7 PM on which date?"}]},
  "tool_call":null,
  "message":"I need the exact time — do you mean 7 AM or 7 PM, and which date?",
  "meta":{"confidence":0.55}
}

SAFETY & PRIVACY
- Never prompt for or expose secrets, passwords, authentication tokens, or any PII beyond what the user explicitly provides.
- Mask or omit PII in any speculative messages. If a request would expose another user's private data, refuse and ask for proper authorization.
- Do not call external services from within the model. All external calls must be performed by server code after a validated tool_call.

IMPLEMENTATION NOTES FOR PRISMPHP / OLLAMA
- PrismPHP should register the same tool schemas as above (withTools / withSchema or equivalent).
- Use generation options: temperature=0.2, top_p=0.9, conservative max tokens. Favor schema enforcement over prompt tricks.
- The server must re-validate the model output against its own DTOs before executing any tool.

ENDING RULE
- Always return the canonical envelope only. If you must include human-readable clarifications or assumptions, place them in the message field (<= 2 short sentences). Do not add extra keys.
```

---

## 7. Model call layer

### 7.1 `CallLlmAction`

**File:** `app/Actions/Llm/CallLlmAction.php`  
**Responsibility:** single place where PrismPHP is called.

```php
public function __invoke(LlmRequestDto $request): LlmRawResponseDto
```

Configuration (sensible defaults):

- Model: `hermes3:3b`
- Temperature: `0.2` (low to maximize schema adherence)
- `top_p`: `0.9`
- `max_tokens`: choose dynamically based on context size; start with a conservative cap (e.g. `1024`) and:
  - **log when truncation occurs** (increment `llm.truncation.count` and record context size at time of call),
  - optionally auto‑tune `max_tokens` upward (within a safe upper bound) for user/session pairs that experience repeated truncations.

`LlmRawResponseDto` can include:

- `string $rawText`
- `array $meta` (latency, tokens, model name, etc.)

**Observability:** wrap the call with timing + logging hooks (see section 11).

**Retry strategy:**
- No blind retries.
- On invalid JSON, delegate to `RetryRepairAction` (once) instead of re‑issuing the same prompt silently.

### 7.2 `RetryRepairAction`

**File:** `app/Actions/Llm/RetryRepairAction.php`  
**Responsibility:** ask the model **once** to repair broken JSON into a valid object matching a given schema.

```php
public function __invoke(string $brokenJson, string $schemaDescription): ?string
```

If repair still fails, the caller returns a **structured error** instead of silently continuing.

---

## 8. Post‑processing & validation

### 8.1 `PostProcessorService`

**File:** `app/Services/Llm/PostProcessorService.php`  
**Responsibility:** transform `LlmRawResponseDto` into `LlmResponseDto`, enforcing all server‑side invariants.

Checks:

1. JSON parseable.
2. `intent` present and in allowed set.
3. `data` matches expected intent‑specific shape (per DTO).
4. All referenced IDs exist in the current `ContextDto`.
5. Any datetime fields (e.g. `start_datetime`) are not in the past and use correct timezone.
6. `tool_call.tool` is in a whitelisted set and its `args` are structurally valid.
7. Deterministic **rule‑engine checks** for suspicious combinations, for example:
   - Scheduling outside user working hours without explicit user permission.
   - Durations that exceed reasonable limits (e.g. multi‑day Pomodoro sessions).
   - Conflicting or overlapping schedules when your domain forbids them.

On invalid JSON:

- Use `RetryRepairAction` once.
- If still invalid, create an `LlmResponseDto` in **error mode**:
  - Contains a user‑safe message (e.g. “Sorry, I could not understand that request.”).
  - Includes debug information only in logs, not exposed to the UI.

On **low‑confidence** or ambiguous results (e.g. missing required fields after repair), treat as a hard failure:

- Prefer a safe, user‑facing clarification message over guessing.
- Optionally trigger a **cascade** to a larger remote model or a human review path if you add those later.

### 8.2 `LlmResponseDto`

**File:** `app/DataTransferObjects/Llm/LlmResponseDto.php`

Typical fields:

- `string $intent`
- `array $data`
- `?ToolCallDto $toolCall`
- `bool $isError`
- `string $message`
- `string $raw` (optional, for debugging)
- `string $schemaVersion`
- `float $confidence` \(0..1\)

The DTO exposes helper methods like:

- `public function hasToolCall(): bool`
- `public function isError(): bool`
- `public function isLowConfidence(): bool` \(e.g. `confidence < 0.4`\)

---

## 9. Tool execution layer

### 9.1 `ToolExecutorService`

**File:** `app/Services/Llm/ToolExecutorService.php`  
**Responsibility:** execute safe, whitelisted tool calls **via existing domain actions**.

```php
public function execute(ToolCallDto $toolCall, User $user): ToolResultDto
```

**Rules:**

- Validate tool name against a whitelist (`create_task`, `update_task`, `create_schedule`, etc.).
- Validate and normalize `args`:
  - Types (string, int, datetime).
  - Ranges (duration minutes > 0, etc.).
  - Timezone handling.
- Require or accept a **`client_request_id`** on tool calls and enforce idempotency:
  - Use a unique constraint or dedicated table/column so the same `client_request_id` cannot be processed twice.
  - Detect duplicates and return the original result instead of performing the operation again.
- Run within a **DB transaction** and aim for idempotency (e.g. `client_request_id` or natural unique constraints).
- Never allow arbitrary SQL or external side‑effects.

#### 9.1.1 Idempotency implementation details

- Prefer a dedicated `llm_tool_calls` table over embedding `client_request_id` uniqueness in each domain table.
- The table should at minimum contain: `client_request_id` (unique), `tool`, normalized `args_hash`, `tool_result_payload`, and timestamps.
- `ToolExecutorService` should:
  - Look up `client_request_id` before executing a tool call and, if present, return the stored `tool_result_payload`.
  - Insert the `llm_tool_calls` row in the same transaction as the domain write to prevent inconsistent replays.

#### 9.1.2 Human review & destructive actions

- When `tool_call.args.confirmation_required === true`, the UI must:
  - Ask the user for explicit confirmation (e.g. “Are you sure you want to delete 12 tasks?”).
  - Only invoke `ToolExecutorService` after confirmation is obtained.
- For destructive or high‑risk operations (bulk updates, deletes, billing‑like changes), enqueue into a **human review queue** instead of executing immediately:
  - Store the proposed tool call, user, and context in a review table.
  - Provide an internal UI for reviewers to approve/modify/reject.
  - Execute via `ToolExecutorService` only after approval.

### 9.2 Tool actions

Thin wrappers that call into existing domain logic:

- `CreateTaskAction` → uses existing task creation logic.
- `UpdateTaskAction` → uses existing update logic.
- `CreateScheduleAction` → uses existing schedule creation logic.

Each action:

- Lives in `app/Actions/Tool`.
- Has a single `__invoke()` method with explicit typed parameters.
- Returns a **domain DTO** or Eloquent model that is then mapped into `ToolResultDto`.

---

## 10. UI‑facing DTOs

### 10.1 `RecommendationDisplayDto`

**File:** `app/DataTransferObjects/Ui/RecommendationDisplayDto.php`  
**Responsibility:** provide a **UI‑ready** structure for the Livewire chat flyout and other views.

Example fields:

- `string $primaryMessage` — main assistant text.
- `array $cards` — e.g. recommended tasks/events, each with title, description, metadata.
- `array $actions` — CTA definitions (buttons: “Apply schedule”, “Create task”, etc.).

The Livewire component should not parse raw LLM data; it only consumes this DTO.

> Important: the UI must always show **real IDs and timestamps** coming from `ToolResultDto` / your domain, never values invented by the model. The optional follow‑up LLM call is only for **rewriting / explaining** results, not for generating new identifiers or authoritative data.

---

## 11. Testing strategy (Pest)

Use Pest tests to cover each layer independently and then full flows.

### 11.1 Unit tests

- `ContextBuilderServiceTest`
  - Limits, summary mode, referenced entity resolution.
- `PromptManagerServiceTest`
  - Ensures required guardrails / schema are present.
  - Ensures `LlmRequestDto` user payload is valid JSON.
- `PostProcessorServiceTest`
  - Valid vs invalid JSON.
  - Missing/unknown IDs.
  - Past datetimes.
  - Unknown tool names.
- `ToolExecutorServiceTest`
  - Transactions and idempotency.
  - Proper mapping to domain actions.

### 11.2 Integration tests (with mocked LLM)

- `ScheduleFlowTest`
  - Mock `CallLlmAction` to return a schedule intent with tool call.
  - Assert `CreateScheduleAction` is invoked and DB rows exist.
- `PrioritizeFlowTest`
  - Mock `CallLlmAction` with a `prioritize` intent.
  - Assert no DB writes and correct `RecommendationDisplayDto` content.
- `ErrorFlowTest`
  - Mock invalid JSON and ensure user‑safe error messaging plus logging.

**Important:** CI should not call real Ollama. Instead, mock `CallLlmAction` or PrismPHP client in tests.

### 11.3 Additional robustness tests to add

- Ambiguous timezones/times:
  - Inputs like “schedule for 7” must route to `clarify` intent with an appropriate follow‑up question.
- Conflicting schedules:
  - When the model proposes a slot overlapping with an event in `ContextDto`, validation must reject the tool call and require clarification.
- `client_request_id` replay:
  - Replaying the same `client_request_id` for a tool call must return the same `ToolResultDto` without duplicate DB writes.
- Truncation:
  - Long contexts that trigger `max_tokens` truncation must increment `llm.truncation.count` and return a safe error or clarification instead of a partial application.
- Schema drift:
  - A mocked response with an outdated `schema_version` must be rejected and logged as schema drift.

---

## 12. Observability & safety

### 12.1 Metrics

Emit metrics (via Laravel events, logging, or dedicated monitor):

- `llm.request.latency` (histogram).
- `llm.request.count` (by intent).
- `llm.validation_fail_rate` (per intent) with clear alert thresholds (e.g. alert if > 2% over a 24h window).
- `llm.tool_execution.count`.
- `llm.retry.count` (JSON repair attempts).
- `llm.truncation.count` plus tags for `user_id`/session and approximate context size at time of call.
- `llm.validation_outcome{status=valid|repaired|failed}` so you can alert when the proportion of repaired/failed responses grows (e.g. `failed` > 0.02 over 24h, or `repaired` / `valid` > 0.10).

### 12.2 Logging

- Attach a `trace_id` to each LLM request / response; include it in logs and Sentry (if used).
- Log:
  - `user_id`
  - `intent`
  - Shortened prompts / responses (trimmed to safe lengths and with PII redacted where possible).
  - Tool calls + results (without leaking sensitive data).
- Store full prompts/responses only in **restricted logs** with a fixed retention period (e.g. 90 days or your internal policy), and redact obvious PII where feasible.

### 12.3 Security & safety rules

- Only execute **whitelisted tools**.
- Validate and sanitize all arguments server‑side.
- Run DB writes in transactions and aim for idempotent operations.
- Treat outputs as **recommendations**, not hard authority; keep UI copy honest about that.

---

## 13. Rollout plan (migrating from legacy assistant)

1. Implement the new architecture under `app/Services/Llm`, `app/Actions/Llm`, `app/DataTransferObjects/Llm` without touching legacy code paths.
2. Add a feature flag (e.g. `llm_assistant_v2`) to switch between legacy and new pipeline at the **service layer**, not in Livewire.
3. Mirror a small percentage of traffic (5–10%) to the new pipeline in **shadow mode** and log differences in intent, tool calls, and outputs.
4. Monitor:
   - Validation failure rates.
   - Tool execution error rates.
   - User‑facing errors.
5. Gradually ramp traffic: 10% → 50% → 100% once metrics are stable.
6. After confirming stability, remove legacy assistant services/actions/DTOs and drop unused tables via migrations.

---

## 14. PrismPHP / Ollama / Hermes 3:3B conventions

The implementation of this architecture **must** follow the established best practices and conventions for **PrismPHP** and **Ollama Hermes 3:3B**:

- Always configure PrismPHP with the exact model id `hermes3:3b` and conservative generation options:
  - Low temperature (`≈0.2`) to maximize schema adherence.
  - Bounded `max_tokens` appropriate for the current context size (see section 7).
- Prefer **schema / tool definitions** (`withSchema()`, `withTools()`, or equivalents) over purely prompt‑based contracts:
  - Register tools like `create_task`, `update_task`, `create_schedule` with explicit argument schemas.
  - Treat the JSON envelope in this document as the canonical contract.
- Keep prompts **short and structured**, leaning on:
  - Clear instructions.
  - A small number of focused examples per primary intent.
  - Explicit descriptions of the allowed `intent` values and `data` shapes.
- Keep total context small where possible (target < ~2000 tokens end‑to‑end):
  - Use `ContextDto` to prune old or low‑value information.
  - Summarize when the user has many tasks or events instead of sending raw lists.
- Prefer **shallow JSON structures**; avoid deeply nested objects that are harder for small models to follow reliably.
- For error handling:
  - Treat invalid JSON or contract violations as **first‑class errors**.
  - Use a single `RetryRepairAction` attempt to fix outputs; never loop retries indefinitely.
  - Surface safe, user‑friendly errors while logging full diagnostic details server‑side.

Any future changes to PrismPHP or Ollama configuration should be reflected here so that **services, actions, and DTOs remain consistent with the latest recommended usage**.

---

## 15. Model limits, risks, and when to use a bigger model

- **3B model trade‑offs:** Hermes 3:3B is fast and cheap but has weaker long‑range reasoning and factual recall than larger models.
  - Expect more schema or field‑format errors when prompts/contexts are noisy.
  - Mitigate via strict schemas, conservative decoding, and aggressive context pruning.
- **Quantization quirks:** Ollama often serves quantized variants (e.g. Q4); test on real workloads to ensure quality is acceptable.
- **Context limits:** treat context as a scarce resource; when users have many tasks or long histories, rely on summaries and RAG rather than raw dumps.
- **Concurrency and duplication:** without idempotency keys and unique constraints, concurrent tool calls can still create duplicates even with transactions.
- **Local infra limits:** Ollama is great for privacy and low latency but introduces capacity planning (CPU, RAM, disk, concurrency) responsibilities.

**When to prefer Hermes 3:3B:**

- Everyday prioritization, small‑scope scheduling, rewriting/clarification, list manipulations, low‑risk recommendations.

**When to consider a larger / remote model or human review:**

- High‑stakes operations (billing, complex multi‑calendar coordination, destructive operations).
- Very long, cross‑document reasoning or policy/legal‑style text where small‑model limits are clear.

This architecture is designed so you can later **cascade** from Hermes 3:3B to a larger model or a human review queue by extending `PostProcessorService` and `LlmChatService` without touching domain actions.

---

## 16. RAG (retrieval‑augmented generation) hook (optional)

If you introduce a vector store or other retrieval system:

- Add a dedicated retrieval service (e.g. `LlmKnowledgeService`) that:
  - Takes the current user, message, and `ContextDto`.
  - Fetches only the most relevant snippets (notes, docs, prior plans).
  - Returns a small list of **grounding passages**.
- Map those passages into `ContextDto->knowledge` as:
  - Plain text snippets with stable IDs.
  - Optional metadata (source, timestamp).
- Make the prompt explicitly tell Hermes:
  - To ground factual claims in `knowledge` when available.
  - To say when information is **not** present instead of guessing.

RAG should be treated as an **add‑on layer**; the core contract (intents, tools, DTOs) remains unchanged.

---

## 17. Robustness & fuzz testing checklist

To move from design to a safe rollout, ensure you can check off the following:

- **PrismPHP tools & schemas**
  - [ ] Register all tool names (`create_task`, `update_task`, `create_schedule`, etc.) with explicit argument schemas.
  - [ ] Enforce the outer JSON envelope described in this document.
- **Idempotency & concurrency**
  - [ ] Accept / require `client_request_id` for tool calls.
  - [ ] Enforce uniqueness (DB constraint or equivalent) on `client_request_id`.
  - [ ] Wrap tool actions in transactions and rollback on failure.
- **Validation & metrics**
  - [ ] Implement `llm.validation_fail_rate` and alert when it exceeds a configured threshold.
  - [ ] Track outcomes (`valid`, `repaired`, `failed`) for post‑processing.
- **Testing**
  - [ ] Build a corpus of “hard” prompts (ambiguous references, timezones, many tasks).
  - [ ] Add Pest tests that fuzz `PostProcessorService` with:
    - Invalid JSON.
    - Missing or unknown IDs.
    - Edge‑case datetimes and overlapping schedules.
  - [ ] Mock `CallLlmAction` in CI; never hit real Ollama during automated tests.
- **Prompt & context discipline**
  - [ ] Keep system prompts minimal; move examples into a small set of few‑shot messages.
  - [ ] Enforce context size budgets and log when you approach or exceed them.
  - [ ] Prefer summaries and RAG for large histories over raw dumps.

Following this checklist, together with the architectural rules above, keeps the LLM assistant **small, safe, and production‑ready** on top of your existing Laravel services/actions/DTOs.

# LLM Task Assistant — Backend Design (Laravel 12 + Livewire 4 — PrismPHP + Ollama + Hermes 3:3B)

**Stack:** entity["software","Laravel 12","php framework"], Livewire 4, entity["software","PrismPHP","PHP LLM framework"], entity["software","Ollama","local LLM runtime"], entity["ai_model","Hermes 3 3B","LLM model"]

> UI note: chat UI will be implemented with **Livewire 4** — this document focuses on backend layers only (services, actions, DTOs, tests, rollout, and observability).

---

## Goals

1. Build a **small, robust** backend orchestration for an LLM-powered task assistant that:  
   - is deterministic where it matters (safety, DB writes),  
   - is model-driven for intent and reasoning,  
   - keeps responses structured and reliable for UI consumption.  
2. Use **PrismPHP** to talk to **Ollama** running **Hermes 3:3B** with schema/tool integration.  
3. Keep the **domain logic (tasks, schedules, repositories)** unchanged; the LLM layer is a pluggable feature.

---

## Overview: Minimal backend pipeline

```
Livewire 4 (Chat UI)
   ↓ (Livewire action)
ChatService (single orchestrator)
   ├─ BuildContextAction / ContextBuilderService
   ├─ PromptManagerService (instructions, tone, schema, examples)
   ├─ CallLlmAction (PrismPHP -> Ollama: hermes3:3b)
   ├─ PostProcessorService (schema validation, guards)
   └─ ToolExecutorService (validated DB writes via existing actions)
   ↓
RecommendationDisplayDto → Livewire view
```

Key constraints:
- **1 LLM call per message** (plus 1 optional follow-up when executing a tool and the model needs to craft a final message).  
- **Structured JSON contract** returned by the LLM and schema-validated server-side.  
- **Tool execution** only after server-side validation and sanitized args.  

---

## File layout (recommended)

```
app/
 ├─ Actions/
 │    ├─ Llm/
 │    │    ├─ BuildContextAction.php
 │    │    ├─ CallLlmAction.php
 │    │    └─ RetryRepairAction.php
 │    └─ Tool/
 │         ├─ CreateTaskAction.php
 │         ├─ UpdateTaskAction.php
 │         └─ CreateScheduleAction.php
 ├─ Services/
 │    └─ Llm/
 │         ├─ ChatService.php
 │         ├─ ContextBuilderService.php
 │         ├─ PromptManagerService.php
 │         ├─ PostProcessorService.php
 │         └─ ToolExecutorService.php
 ├─ DataTransferObjects/
 │    ├─ Llm/ContextDto.php
 │    ├─ Llm/LlmRequestDto.php
 │    ├─ Llm/LlmResponseDto.php
 │    └─ Ui/RecommendationDisplayDto.php
 └─ Livewire/
      └─ chat-flyout.php
```

---

## Concrete design details (per component)

### 1) `ContextBuilderService` / `BuildContextAction`
**Responsibility:** fetch minimal, relevant state for reasoning and return a typed `ContextDto`.

**Rules:**
- Always include: `now` (server time, Asia/Manila), top `N` active tasks (configurable, default 8), upcoming events (next 24h), last 4 messages of the conversation.  
- If user or DB indicates a referenced entity (e.g., "that physics task"), fetch the referenced item explicitly.  
- If user has > `MAX_TASKS_THRESHOLD` (e.g., 50 tasks), return a short summary instead of raw list: counts, nearest deadlines, top 3 urgent items.

**Return (ContextDto):**
```php
{ DateTimeImmutable $now, array $tasks, array $events, array $recentMessages, array $userPreferences }
```

**Implementation notes:**
- Use repository queries with `->limit(8)` and `orderBy('due_date')`.  
- Keep payload sizes small — prune long descriptions.  

---

### 2) `PromptManagerService`
**Responsibility:** centralize **system prompt** (instructions, tone, guardrails, examples), output schema, and build the `LlmRequestDto` (system + user payload merged with context).

**What lives here:**
- System role (assistant identity).  
- Tone & persona bullets (friendly, concise, student-centric).  
- Guardrails (never invent IDs, respect explicit times, never schedule in the past).  
- Output schema (intent-specific shapes) — include exact JSON schema strings.  
- Few-shot examples: 2–3 concise examples per primary intent (`schedule`, `prioritize`, `create`, `list`, `general`).

**Design:**
- Provide `systemPrompt(): string` and `buildUserPayload(string $message, ContextDto $ctx): string` that returns a compact JSON user payload.  
- Keep system prompt short — prioritize clarity and strict schema contract.  

**Schema example (simplified):**
```json
{
  "intent":"schedule|create|update|prioritize|list|general",
  "data":{},
  "tool_call": null | {"tool":"create_task","args":{...}},
  "message":"user-facing text"
}
```

**PrismPHP-specific:** If PrismPHP supports `withSchema()` or `withTools()` (function-like tool defs), use them so the runtime enforces structure.

---

### 3) `CallLlmAction` (PrismPHP → Ollama)
**Responsibility:** call PrismPHP client which forwards to Ollama local runtime and returns raw text + meta.

**Configuration:**
- Model: `hermes3:3b` (exact string used by Ollama).  
- Temperature: `0.2` (low for deterministic outputs).  
- top_p: `0.9` (optional).  
- Max tokens: keep conservative to avoid truncation (based on Ollama/Prism defaults).  

**Return:** `['text'=>$text, 'meta'=>$meta]`.

**Observability:** measure latency, token usage if available.  

**Retry strategy:** **do not** retry blindly; instead return raw response to `PostProcessor` which may decide to attempt a single `RetryRepairAction` to ask the model to fix broken JSON.

---

### 4) `PostProcessorService`
**Responsibility:** parse LLM output, enforce schema, run deterministic guards, and prepare `LlmResponseDto` or structured error.

**Checks to run:**
1. JSON parseable.  
2. `intent` present and valid.  
3. `data` shape matches `intent`-specific expectations.  
4. All referenced IDs exist in `context.tasks` or `context.events`.  
5. `start_datetime` not in the past (Asia/Manila).  
6. `tool_call` only for whitelisted tool names.

**Repair flow:** If JSON invalid — run `RetryRepairAction` once with a short prompt: "The assistant output is invalid JSON. Please fix it to match schema X." If still invalid, return structured error to UI and log full payload to secure logs.

**Output:** `LlmResponseDto` with either `->isError()` flagged or `->intent()`, `->data()`, `->toolCall()`.

**Important:** never execute tool calls before server validation.

---

### 5) `ToolExecutorService` / Tool Actions
**Responsibility:** safely execute requested actions using existing domain `Actions` (create task, schedule task, update task).

**Rules:**
- Validate `ToolCallDto` server-side: types, ranges, timezone handling.  
- Run inside DB transaction; ensure idempotency (use safeguards like `client_request_id` or detect existing identical rows).  
- Return structured `tool_result` with `success`, `id`, `timestamp`, and any human message.

**Example flow:**
1. LLM returns `tool_call` to `ToolExecutor` (e.g., `{tool: 'create_schedule', args:{task_id:'t1', start:'2026-03-15T19:00:00+08:00'}}`).  
2. `ToolExecutor` validates `task_id` exists and `start` not in past.  
3. Execute `CreateScheduleAction` under transaction.  
4. Return result to `ChatService`.

**Follow-up LLM call (optional):** After tool execution the orchestrator may issue a single follow-up LLM call to craft the final human-facing message, passing `tool_result` back into the prompt.

---

## DTOs & Typed Contracts
- `ContextDto` — typed arrays for `tasks`, `events`, `now`, `recentMessages`.  
- `LlmRequestDto` — `systemPrompt`, `userPayload` (JSON string), `metadata`.  
- `LlmResponseDto` — parsed `intent`, `data`, `toolCall`, `raw`.
- `ToolCallDto` — `tool` and `args`.  
- `RecommendationDisplayDto` — UI-friendly DTO (message, cards, actions).

Keep DTOs small and add `->toArray()` for Livewire consumption.

---

## Schema examples (intent-specific)

**Schedule**
```json
{ "intent":"schedule", "data":{"scheduled_items":[{"id":"task_123","start_datetime":"2026-03-14T19:00:00+08:00","duration_minutes":60}]}, "tool_call":{"tool":"create_schedule","args":{...}}, "message":"" }
```

**Prioritize**
```json
{ "intent":"prioritize", "data":{"ranked_ids":["task_3","task_12","task_4"]}, "tool_call":null, "message":"" }
```

**Create**
```json
{ "intent":"create", "data":{"title":"Read chapter 4","due_date":"2026-03-18"}, "tool_call":{"tool":"create_task","args":{...}}, "message":"" }
```

---

## Tests (unit + integration)

**Unit tests:**
- `ContextBuilderTest`: verify limits, summary mode, referenced entity load.  
- `PromptManagerTest`: ensure system prompt includes schema & examples, output payload valid JSON.  
- `PostProcessorTest`: valid/invalid JSON, missing id, past datetime, unknown tool.  
- `ToolExecutorTest`: transactional DB writes & idempotency.

**Integration tests (mock Ollama):**
- `ScheduleFlowTest`: mock LLM response for `schedule` and assert `CreateScheduleAction` called.  
- `PrioritizeFlowTest`: mock LLM response for `prioritize` and assert no DB writes and DTO correctness.

**CI rule:** do **not** call real Ollama in CI by default. Use a stubbed Return for `CallLlmAction`.

---

## Observability & Metrics
Emit Prometheus metrics or Laravel metrics:
- `llm.request.latency` (histogram)
- `llm.validation_fail_rate` (counter, per intent)
- `llm.tool_execution.count` (counter)
- `llm.retry.count` (counter)

Logging:
- Save `traceId`, `user_id`, `intent`, trimmed prompt & response in app logs; store full prompt/response in secure logs if needed.  
- Sentry exceptions with `traceId` and `llm_context` breadcrumb.

---

## Security & Safety
- **Whitelist tools** and strictly validate args.  
- **Mask PII** in prompts (if any).  
- **Limit scope** of tool side-effects (no arbitrary SQL or shell).  
- **Run DB writes in transactions** and ensure idempotency.  
- **Store full LLM outputs** only in secure logs with restricted access.

---

## Migration & Rollout
1. Create new `app/Services/LlmV2` (or `app/Services/AI`) and implement services there.  
2. Add feature flag `llm_refactor_v1`.  
3. Mirror 5–10% traffic to new flow and log diffs vs old system for 72 hours.  
4. Monitor `llm.validation_fail_rate` & user-impact metrics.  
5. Ramp to 50% → 100% when stable.  
6. Delete legacy LLM orchestration files.

---

## Example minimal `ChatService` (pseudo-code)

```php
class ChatService {
  public function handle(User $user, string $threadId, string $message): RecommendationDisplayDto {
    $ctx = (new BuildContextAction())($user, $threadId, $message);
    $req = PromptManagerService::buildRequest($message, $ctx);
    $raw = (new CallLlmAction())($req);
    $resp = (new PostProcessorService())->parseAndValidate($raw, $ctx);
    if ($resp->hasToolCall()) {
      $toolResult = (new ToolExecutorService())->execute($resp->toolCall());
      // optional follow-up to create final message
      $followUpReq = PromptManagerService::buildToolResultRequest($toolResult, $ctx);
      $followUpRaw = (new CallLlmAction())($followUpReq);
      $resp = (new PostProcessorService())->parseAndValidate($followUpRaw, $ctx);
    }
    return RecommendationDisplayDto::fromResponse($resp);
  }
}
```

---

## PrismPHP + Ollama practical tips
- Run Ollama locally (or in a private host) and point Prism to it.  
- Use `hermes3:3b` exact model id.  
- Keep temperature low (`0.2`) to improve schema adherence.  
- If Prism supports function/tool typing, use it: register `create_task`, `create_schedule`, etc., as discrete tools with argument schemas.  

---

## Tips tuned for **Hermes 3:3B**
- Keep prompts short and structured; prefer explicit examples.  
- Keep context small (recommended total < 2000 tokens).  
- Use shallow schemas (avoid deeply nested objects).  
- Prefer structured outputs like `ranked_ids[]` instead of per-item long justification.  

---


