# Hermes 3:3B Task Assistant — Implementation Plan (AI Agent Guide)

**Stack:** Laravel 12, Livewire 4, [PrismPHP](https://prismphp.com), Ollama, Reverb

This document is the **single source of truth** for implementing the LLM task-assistant module. Implement in **Phase order**; do not skip phases. Use the paths, class names, and contracts below. Follow existing project conventions (Laravel 12, Livewire 4 multifile components, Pest, Pint). All tools **MUST** delegate to existing `app/Actions` — do not re-implement business logic inside tools.

---

## Quick reference (for implementation)

| Item | Value |
|------|--------|
| **Provider** | `Prism\Prism\Enums\Provider::Ollama` |
| **Model** | `'hermes3:3b'` |
| **Prism config** | `config/prism.php` → `providers.ollama` (use `OLLAMA_URL` in `.env`) |
| **Tool mapping config** | `config/prism-tools.php` (create) — tool name → wrapper class |
| **Orchestration service** | `App\Services\TaskAssistantService` (create) |
| **Tools namespace** | `App\Tools\TaskAssistant` |
| **System prompt view** | `resources/views/prompts/task-assistant-system.blade.php` |
| **Chat UI (Livewire)** | `resources/views/components/assistant/⚡chat-flyout/` (multifile: `.blade.php` + `.php`) |
| **DB tables** | `task_assistant_threads`, `task_assistant_messages`, `llm_tool_calls` |
| **Prism text + tools** | `->withMaxSteps(2)` or higher when using tools; tools **MUST** return a string (JSON). |

---

## PrismPHP conventions (from [official intro](https://prismphp.com/getting-started/introduction.html))

This plan follows the main practices from the Prism docs:

- **Unified provider interface:** Use `Prism::text()->using(Provider::Ollama, 'hermes3:3b')`. The same pattern works for other providers (Anthropic, OpenAI, etc.) by changing provider and model only.
- **System prompt via view:** Use `->withSystemPrompt(view('prompts.task-assistant-system', $data))` for complex prompts, as in the [Prism intro example](https://prismphp.com/getting-started/introduction.html).
- **Tool system:** Tools extend application capabilities; define tools with the fluent API (`Tool::as()->for()->withXParameter()->using($this)`) and pass them with `->withTools($tools)`. Tools interact with your business logic (here: `app/Actions` and services).
- **Fluent API:** Chain `->using()`, `->withSystemPrompt()`, `->withPrompt()` / `->withMessages()`, `->withTools()`, `->withMaxSteps()`, then a terminal method (`->asText()`, `->asEventStreamResponse()`, `->asBroadcast()`). Use `prism()->text()->...` instead of `Prism::text()->...` if you prefer the helper.
- **Streaming:** For real-time UI use `->asEventStreamResponse()` or `->asBroadcast(Channel)`; see [Streaming Output](https://prismphp.com/core-concepts/streaming-output.html).

When in doubt, prefer the patterns shown in the [Prism Introduction](https://prismphp.com/getting-started/introduction.html) and the linked core-concept pages.

---

## Implementation phases (execute in order)

### Phase 1: Foundation — DB, config, Ollama

1. **Migrations**
   - Create migrations for `task_assistant_threads`, `task_assistant_messages`, `llm_tool_calls` (see [§ Data model](#data-model-schema) below). Run migrations.
2. **Config**
   - Ensure `config/prism.php` has the Ollama provider (`providers.ollama` with `url` from `OLLAMA_URL`). Add `OLLAMA_URL` to `.env.example` if missing.
   - Create `config/prism-tools.php` with a mapping array: tool name (string) → wrapper class (e.g. `'create_task' => App\Tools\TaskAssistant\CreateTaskTool::class`). Start with an empty array or placeholders; fill in Phase 3.

**Exit condition:** Migrations run; config files exist; Ollama reachable locally (optional for CI).

---

### Phase 2: Models and persistence

1. **Eloquent models**
   - Create `App\Models\TaskAssistantThread` (belongsTo User; hasMany TaskAssistantMessage).
   - Create `App\Models\TaskAssistantMessage` (belongsTo TaskAssistantThread; role, content, tool_calls JSON, metadata JSON).
   - Create `App\Models\LlmToolCall` (belongsTo thread, nullable message, user; tool_name, params_json, result_json, status enum, operation_token). Add relationships and casts per [§ Data model](#data-model-schema).

**Exit condition:** Models exist, relationships and casts defined; factories optional.

---

### Phase 3: Tools (wrapper layer)

1. **Base class**
   - Create `App\Tools\TaskAssistant\DelegatingTool` extending `Prism\Prism\Tool`. Implement idempotency via `operation_token` and audit via `llm_tool_calls` (create row pending → run delegate → update success/failed). See [§ Tool mapping and DelegatingTool](#tool-mapping-and-delegatingtool) for the pattern.
2. **First tool**
   - Implement `CreateTaskTool` in `App\Tools\TaskAssistant`: delegate to `App\Actions\Task\CreateTaskAction::execute(User, CreateTaskDto)`. Build `CreateTaskDto::fromValidated($validated)` from tool parameters. Return a JSON string: `{"ok":true,"message":"...","task":{...}}`. Register in `config/prism-tools.php` as `'create_task' => CreateTaskTool::class`.
3. **Remaining tools**
   - Add wrappers for each row in the [§ Tool → Action mapping table](#tool--action-mapping-table). Each tool MUST: extend `Prism\Prism\Tool` (or `DelegatingTool`), use `Tool::as('name')->for('description')->withXParameter(...)->using($this)`, return a string (JSON), persist `llm_tool_calls` for write operations, support `operation_token` for idempotency. Resolve tools via `Tool::make(YourToolClass::class)` when injecting dependencies.
4. **Read-only tools**
   - For list/search tools (e.g. `list_tasks`), call `TaskService::getTasksForProject` or equivalent; return JSON summary. Optionally audit as read-only (no `pending` write to `llm_tool_calls` or use a `read` type).

**Exit condition:** All planned tools implemented and listed in `config/prism-tools.php`; each delegates to `app/Actions` or services; unit test for at least one tool (e.g. CreateTaskTool).

---

### Phase 4: Context and system prompt

1. **System prompt view**
   - Create `resources/views/prompts/task-assistant-system.blade.php`. Include: short role (“You are Hermes 3:3B, a task assistant”), user context (id, timezone, date format — pass from service), TOOL_MANIFEST (name + one-line “when to use”), and rules (use tools for side-effects; for structured requests output JSON only). Keep minimal and token-bounded (~8k tokens target).
2. **TOOL_MANIFEST**
   - Generate from `config/prism-tools.php` and tool instances (e.g. `->as()` and `->for()`) so the system prompt always lists current tools.

**Exit condition:** Blade view exists and is renderable; TOOL_MANIFEST is injectable (variable or partial).

---

### Phase 5: Orchestration service and streaming

1. **TaskAssistantService**
   - Create `App\Services\TaskAssistantService` (or equivalent). Responsibilities:
     - Load conversation: load recent `TaskAssistantMessage` for the thread; map to Prism message objects (`UserMessage`, `AssistantMessage`, `ToolResultMessage`).
     - Resolve tools: read `config('prism-tools')` and instantiate with `Tool::make($class)` (pass `User` and other deps).
     - Build Prism request: `Prism::text()->using(Provider::Ollama, 'hermes3:3b')->withSystemPrompt(view('prompts.task-assistant-system', $data))->withMessages($messages)->withPrompt($currentUserMessage)->withTools($tools)->withMaxSteps(3)->withClientOptions(['timeout' => 60])`.
     - Stream: use `->asEventStreamResponse($callback)` or `->asBroadcast(new Channel("task-assistant.{$threadId}"))`. In the completion callback: reconstruct full assistant text from `TextDeltaEvent` instances; persist to `TaskAssistantMessage` (role `assistant`, content, metadata with tool_calls if needed).
   - Create assistant message row (empty content) before starting the stream; update it in the callback when stream ends.
2. **Live data via tools**
   - Do NOT dump the full DB into the system prompt. Live task/event/project data is provided when the model calls tools (e.g. `list_tasks`); the tool returns JSON and Prism feeds it back as the tool result.

**Exit condition:** Service exists; can run a single request with tools and streaming; completion callback persists the assistant message.

---

### Phase 6: Livewire UI and Reverb

1. **Chat flyout**
   - Implement or extend the assistant chat in `resources/views/components/assistant/⚡chat-flyout/` (Livewire 4 multifile: `chat-flyout.blade.php` + `chat-flyout.php`). User submits message → Livewire calls service (or dispatches job) to append user message and start Prism stream.
2. **Streaming to UI**
   - Subscribe to Reverb channel `task-assistant.{thread_id}` (Laravel Echo). On `.text_delta`, append to the current assistant message in the UI. On `.stream_end`, mark message complete. Optionally show “working” on `.tool_call` / `.tool_result`.

**Exit condition:** User can send a message and see streamed assistant reply in the chat UI.

---

### Phase 7: Tests, observability, security

1. **Tests**
   - Unit: each tool — happy path, idempotency (same `operation_token` returns cached result), permission denied. Use Pest; use factories for User/Task/Thread/Message.
   - Feature: mock Ollama/Prism or use a test double; submit a message and assert `task_assistant_messages` and optionally `llm_tool_calls` state.
2. **Security**
   - Tools MUST enforce `User` context (no cross-user access). Validate and sanitize all tool parameters. Destructive actions (delete, force delete) SHOULD require explicit confirm and/or undo window.
3. **Observability (optional)**
   - Structured logs (thread_id, operation_token, tool_name). Optional: trace UI (system prompt, message history, tool call timeline) for developers only.

**Exit condition:** Tests pass; security rules applied; logging in place as desired.

---

## Data model (schema)

Create these tables (and corresponding Eloquent models in Phase 2).

### `task_assistant_threads`

- `id`, `user_id` (FK users), `title`, `metadata` (JSON), `created_at`, `updated_at`

### `task_assistant_messages`

- `id`, `thread_id` (FK task_assistant_threads), `role` (user|assistant|system|tool), `content` (TEXT), `tool_calls` (JSON, nullable), `metadata` (JSON), `created_at`, `updated_at`

### `llm_tool_calls`

- `id`, `thread_id` (FK task_assistant_threads), `message_id` (nullable, FK task_assistant_messages), `tool_name`, `params_json`, `result_json` (nullable), `status` (enum: pending|success|failed), `operation_token` (string, nullable, index), `user_id` (FK users), `created_at`, `updated_at` (or `completed_at` if preferred)

Existing domain models (`Task`, `Project`, `Event`, `Tag`, etc.) and `app/Actions` remain the source of truth; see `docs/task-management-models-and-schema.md`.

---

## Context layer (what the LLM sees)

| Source | Prism API | Who builds it |
|--------|-----------|----------------|
| **System context** | `->withSystemPrompt(view('prompts.task-assistant-system', $data))` | Blade view: role, TOOL_MANIFEST, user_context (id, timezone, date format). Keep minimal. |
| **Conversation history** | `->withMessages($messages)` + current turn via `->withPrompt($text)` or last message | Service: load recent `TaskAssistantMessage` for thread; map to Prism `UserMessage` / `AssistantMessage` / `ToolResultMessage`. |
| **Live domain data** | Tool results (automatic) | When the model calls e.g. `list_tasks`, the tool runs `TaskService` (or equivalent), returns JSON; Prism injects as tool result. Do NOT dump full DB into system prompt. |

---

## Tool contract (Prism)

- Each tool **MUST** return a **string** (or `ToolOutput` for artifacts). Prefer a JSON string so the LLM and audit can parse it (e.g. `{"ok":true,"message":"...","task":{...}}`).
- Use fluent API: `Tool::as('name')->for('description')->withStringParameter(...)->using($this)`. Class-based tools extend `Prism\Prism\Tool`; resolve with `Tool::make(YourToolClass::class)` for DI.
- **Idempotency:** Accept `operation_token`; if `llm_tool_calls` already has a row with that token and `status = success`, return the stored `result_json` and do not re-run the action.
- **Audit:** For write tools, create an `llm_tool_calls` row with `status = pending` before running the action; update to `success` or `failed` after; store `params_json` and `result_json`.

---

## Tool → Action mapping table

Every tool name MUST map to a wrapper class that delegates to an existing action (or service) in the codebase. Maintain the mapping in `config/prism-tools.php`.

| Tool name | Wrapper class | Delegates to |
|-----------|---------------|--------------|
| `create_task` | `CreateTaskTool` | `App\Actions\Task\CreateTaskAction::execute(User, CreateTaskDto)` |
| `update_task` | `UpdateTaskTool` | `App\Actions\Task\UpdateTaskPropertyAction::execute(Task, property, value, …)` |
| `delete_task` | `DeleteTaskTool` | `App\Actions\Task\DeleteTaskAction::execute(Task, actor)` |
| `restore_task` | `RestoreTaskTool` | `App\Actions\Task\RestoreTaskAction::execute(Task, actor)` |
| `force_delete_task` | `ForceDeleteTaskTool` | `App\Actions\Task\ForceDeleteTaskAction::execute(Task, actor)` |
| `list_tasks` | `ListTasksTool` | `TaskService::getTasksForProject` / `getTasksForEvent` or Task scopes (read-only) |
| `create_event` | `CreateEventTool` | `App\Actions\Event\CreateEventAction::execute(User, CreateEventDto)` |
| `update_event` | `UpdateEventTool` | `App\Actions\Event\UpdateEventPropertyAction::execute(Event, property, value, …)` |
| `delete_event` | `DeleteEventTool` | `App\Actions\Event\DeleteEventAction::execute(Event, actor)` |
| `restore_event` | `RestoreEventTool` | `App\Actions\Event\RestoreEventAction::execute(Event, actor)` |
| `create_project` | `CreateProjectTool` | `App\Actions\Project\CreateProjectAction::execute(User, CreateProjectDto)` |
| `update_project` | `UpdateProjectTool` | `App\Actions\Project\UpdateProjectPropertyAction::execute(Project, property, value, …)` |
| `delete_project` | `DeleteProjectTool` | `App\Actions\Project\DeleteProjectAction::execute(Project, actor)` |
| `restore_project` | `RestoreProjectTool` | `App\Actions\Project\RestoreProjectAction::execute(Project, actor)` |
| `create_tag` | `CreateTagTool` | `App\Actions\Tag\CreateTagAction` |
| `delete_tag` | `DeleteTagTool` | `App\Actions\Tag\DeleteTagAction` |
| `create_comment` | `CreateCommentTool` | `App\Actions\Comment\CreateCommentAction` |
| `update_comment` | `UpdateCommentTool` | `App\Actions\Comment\UpdateCommentAction` |
| `delete_comment` | `DeleteCommentTool` | `App\Actions\Comment\DeleteCommentAction` |

Add recurrence/exception/collaboration tools later if needed. Do not re-implement CRUD inside tools — wrappers only.

---

## Tool mapping and DelegatingTool

- **Config:** `config/prism-tools.php` returns an array: `'tool_name' => App\Tools\TaskAssistant\SomeTool::class`. The service resolves instances with `Tool::make($class)` so the container can inject `User` and actions.
- **Base class:** `App\Tools\TaskAssistant\DelegatingTool` extends `Prism\Prism\Tool`. It handles: (1) idempotency via `operation_token` (return existing `result_json` if already success), (2) create `LlmToolCall` with `status = pending`, (3) run a callable that invokes the real action and returns an array, (4) update `LlmToolCall` to `success` or `failed` and set `result_json`, (5) return the JSON string to Prism.
- **Concrete tool:** e.g. `CreateTaskTool extends DelegatingTool`. In `register()`: `$this->as('create_task')->for('...')->withStringParameter('title', ...)->...->using($this)`; set `$this->action` to a callable that maps params to `CreateTaskDto::fromValidated()` and calls `CreateTaskAction::execute($user, $dto)`. In `__invoke(...)`: pass params and `operation_token` to `runDelegatedAction($params, 'create_task', $operationToken)` and return the string result.

---

## Streaming and persistence

- **Options:** `->asEventStreamResponse($callback)` (SSE) or `->asBroadcast(new Channel("task-assistant.{$threadId}"))` (Reverb). Use completion callback to persist: receive `(PendingRequest $request, Collection $events)`; filter `TextDeltaEvent` and concatenate `delta` to get full text; save to `TaskAssistantMessage` (role assistant).
- **Events:** `stream_start`, `text_delta`, `tool_call`, `tool_result`, `step_finish`, `stream_end`. A “step” is one AI generation cycle (optionally with tool calls).
- **Warning:** Laravel Telescope or other HTTP interceptors can consume the stream; disable or exclude Prism requests when using streaming.

---

## Prism + Ollama specifics

- **Provider:** `Provider::Ollama`; model `'hermes3:3b'`. Config: `config/prism.php` → `providers.ollama.url` (e.g. `env('OLLAMA_URL', 'http://localhost:11434')`).
- **Timeout:** Use `->withClientOptions(['timeout' => 60])` (or higher) for local Ollama.
- **Options:** Optional `->withProviderOptions(['num_ctx' => 4096, 'top_p' => 0.9])` for model behavior.
- **Limitations:** Ollama does not support tool choice (required tools). Structured output with Ollama is prompt-based (Prism appends JSON instructions); prefer `Prism::text()` for chat and use `Prism::structured()->withSchema()` only for explicit “export as JSON” flows.

---

## Example: Prism request (canonical pattern)

```php
use Illuminate\Support\Collection;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Text\PendingRequest;

$tools = array_map(fn ($class) => Tool::make($class), array_values(config('prism-tools')));

Prism::text()
    ->using(Provider::Ollama, 'hermes3:3b')
    ->withSystemPrompt(view('prompts.task-assistant-system', ['userContext' => $userContext, 'toolManifest' => $toolManifest]))
    ->withTools($tools)
    ->withMaxSteps(4)
    ->withClientOptions(['timeout' => 60])
    ->withMessages($conversationMessages)
    ->withPrompt($currentUserMessage)
    ->asEventStreamResponse(function (PendingRequest $request, Collection $events) use ($assistantMessageId) {
        // Reconstruct full text from TextDeltaEvent; update TaskAssistantMessage $assistantMessageId
    });
```

---

## Example: migration for `llm_tool_calls`

```php
Schema::create('llm_tool_calls', function (Blueprint $table) {
    $table->id();
    $table->foreignId('thread_id')->constrained('task_assistant_threads')->cascadeOnDelete();
    $table->foreignId('message_id')->nullable()->constrained('task_assistant_messages')->nullOnDelete();
    $table->string('tool_name');
    $table->json('params_json');
    $table->json('result_json')->nullable();
    $table->string('status')->default('pending'); // or enum
    $table->string('operation_token')->nullable()->index();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->timestamps();
});
```

---

## Appendix: In-depth reference

Use this section when the agent needs full code or extra detail. The main phases above are enough to drive implementation; the appendix avoids guesswork.

### Idempotency flow (detailed)

1. LLM includes `operation_token` (e.g. UUID) with every tool call.
2. Tool looks up `llm_tool_calls` by `operation_token` + `tool_name` + `user_id`. If a row exists with `status = success`, return the stored `result_json` and do not run the action again.
3. If not found: create `llm_tool_calls` row with `status = pending`, run the delegated action inside a DB transaction, then update the row to `success` or `failed` and set `result_json`.
4. This prevents duplicate side-effects on retries or network glitches.

### create_task contract (DTO-aligned)

- **Tool params (before DTO):** `title` (required), `description`, `status`, `priority`, `complexity`, `duration` (minutes), `startDatetime`, `endDatetime` (ISO8601), `projectId`, `eventId`, `tagIds` (array), `recurrence` (object), `operation_token`.
- **Mapping:** Normalize into the array shape expected by `App\DataTransferObjects\Task\CreateTaskDto::fromValidated($validated)`. Then call `CreateTaskAction::execute(User $user, CreateTaskDto $dto)`; the action delegates to `TaskService::createTask($user, $dto->toServiceAttributes())`.
- **Result shape:** `{ "ok": true, "message": "...", "task": { "id", "title", "status", "priority", "project_id", "event_id", "start_datetime", "end_datetime" } }`. Return `json_encode(...)`.

### DelegatingTool base class (full example)

```php
<?php

namespace App\Tools\TaskAssistant;

use App\Models\LlmToolCall;
use Illuminate\Support\Facades\DB;
use Prism\Prism\Tool;

abstract class DelegatingTool extends Tool
{
    /** @var callable */
    protected $action;

    public function __construct(protected readonly \App\Models\User $user) {}

    protected function runDelegatedAction(array $params, string $toolName, ?string $operationToken = null): string
    {
        if ($operationToken) {
            $existing = LlmToolCall::query()
                ->where('operation_token', $operationToken)
                ->where('tool_name', $toolName)
                ->where('user_id', $this->user->id)
                ->first();
            if ($existing && $existing->status === 'success') {
                return $existing->result_json ?? json_encode(['ok' => true, 'message' => 'Already executed']);
            }
        }

        $call = LlmToolCall::create([
            'thread_id' => null,
            'message_id' => null,
            'tool_name' => $toolName,
            'params_json' => json_encode($params),
            'status' => 'pending',
            'operation_token' => $operationToken,
            'user_id' => $this->user->id,
        ]);

        try {
            $result = ($this->action)($params);
            $resultJson = json_encode($result);
            $call->update(['result_json' => $resultJson, 'status' => 'success']);
            return $resultJson;
        } catch (\Throwable $e) {
            $call->update([
                'result_json' => json_encode(['error' => $e->getMessage()]),
                'status' => 'failed',
            ]);
            return json_encode(['ok' => false, 'message' => 'Tool execution failed', 'error' => $e->getMessage()]);
        }
    }
}
```

### CreateTaskTool extending DelegatingTool

- In `register()`: call `$this->as('create_task')->for('Create a new task...')->withStringParameter('title', ..., required: true)->...->withStringParameter('operation_token', ..., nullable: true)->using($this)`. Set `$this->action` to a callable that (1) builds `$validated` from `$params` (align keys with `CreateTaskDto::fromValidated`), (2) builds `CreateTaskDto::fromValidated($validated)`, (3) calls `$this->createTaskAction->execute($this->user, $dto)`, (4) returns `['ok' => true, 'message' => '...', 'task' => ['id' => $task->id, ...]]`.
- In `__invoke(...)`: pass `compact('title', 'description', ...)` and `$operationToken` to `$this->runDelegatedAction($params, 'create_task', $operationToken)` and return the string. Constructor: `User $user`, `CreateTaskAction $createTaskAction`; call `parent::__construct($user)`.

### config/prism-tools.php (full example)

```php
return [
    'create_task'    => App\Tools\TaskAssistant\CreateTaskTool::class,
    'update_task'    => App\Tools\TaskAssistant\UpdateTaskTool::class,
    'delete_task'    => App\Tools\TaskAssistant\DeleteTaskTool::class,
    'restore_task'   => App\Tools\TaskAssistant\RestoreTaskTool::class,
    'list_tasks'     => App\Tools\TaskAssistant\ListTasksTool::class,
    'create_event'   => App\Tools\TaskAssistant\CreateEventTool::class,
    'update_event'   => App\Tools\TaskAssistant\UpdateEventTool::class,
    'delete_event'   => App\Tools\TaskAssistant\DeleteEventTool::class,
    'restore_event'  => App\Tools\TaskAssistant\RestoreEventTool::class,
    'create_project' => App\Tools\TaskAssistant\CreateProjectTool::class,
    'update_project' => App\Tools\TaskAssistant\UpdateProjectTool::class,
    'delete_project' => App\Tools\TaskAssistant\DeleteProjectTool::class,
    'restore_project'=> App\Tools\TaskAssistant\RestoreProjectTool::class,
    'create_tag'     => App\Tools\TaskAssistant\CreateTagTool::class,
    'delete_tag'     => App\Tools\TaskAssistant\DeleteTagTool::class,
    'create_comment' => App\Tools\TaskAssistant\CreateCommentTool::class,
    'update_comment' => App\Tools\TaskAssistant\UpdateCommentTool::class,
    'delete_comment' => App\Tools\TaskAssistant\DeleteCommentTool::class,
];
```

### Security and observability

- **Authorization:** Every tool MUST enforce the current `User`; never allow cross-user access. Re-check policies/gates inside the delegated action.
- **Destructive ops:** For delete/force-delete tools, require a `confirm: true` (or similar) parameter and/or a two-step (plan → confirm) flow; support an undo window (e.g. 5–10 minutes) where applicable.
- **Input validation:** Sanitize and validate all tool parameters; return structured errors to the LLM instead of stack traces.
- **Logging:** Structured logs per run: `thread_id`, `operation_token`, `tool_name`, outcome. Optional: metrics (tool call count, step count, failed tools).
- **Trace UI:** Internal-only page showing system prompt, message history, tool call timeline, tool results, and final assistant text for debugging.

### Rollout and feature flags

- Ship behind a feature flag for internal/alpha users first. Monitor tool errors and step counts. Gradually enable for more users after idempotency and safety are stable.

---

## Goals and non-goals

- **Goals:** Local, private assistant with `hermes3:3b`; natural chat UI with streaming; tools for CRUD backed by existing actions; safe destructive ops (confirm/undo).
- **Non-goals:** Full autonomy over unrelated data; exposing raw LLM responses to end users (developer trace only).

---

## References

- [Prism Introduction](https://prismphp.com/getting-started/introduction.html)
- [Prism Tools & Function Calling](https://prismphp.com/core-concepts/tools-function-calling.html)
- [Prism Streaming Output](https://prismphp.com/core-concepts/streaming-output.html)
- [Prism Ollama Provider](https://prismphp.com/providers/ollama.html)
- Project schema: `docs/task-management-models-and-schema.md`

---

*Document formatted as an AI-agent implementation guide. Last updated: March 2026.*
