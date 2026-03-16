## Task Assistant Orchestration Guide  
### (Laravel 12 + PrismPHP + Hermes 3:3B)

This file is an **implementation roadmap and reference guide for AI agents and Laravel engineers** working on the Task Assistant.

It explains:

- **What exists today** in the Laravel 12 / PrismPHP / Hermes 3:3B stack.
- **How to evolve the assistant in phases**, each with clear goals and tasks.
- **How to keep everything aligned** with `@.cursor/rules/laravel-boost.mdc` (Laravel 12, PrismPHP, Tailwind v4, Pest, etc.).

All details from the previous version of this document are preserved but reorganized into **phased iterations**.

---

## 1. Overview & Current State (Phase 0 / Baseline)

### 1.1 Core stack

- **Framework**: Laravel 12 (see `laravel-boost.mdc` for project‑wide conventions).
- **LLM orchestration**: PrismPHP.
- **Model**: `hermes3:3b` via PrismPHP (`Provider::Ollama, 'hermes3:3b'`).
- **Assistant domain**: student‑focused Task Assistant (prioritization, planning, breaking tasks into steps).

### 1.2 Task Assistant components

- **Service layer**
  - `TaskAssistantService`: main orchestration and streaming service. Streams or broadcasts responses via Prism.
  - `TaskAssistantSnapshotService::buildForUser($user)`: builds the snapshot injected into the system prompt.
  - `TaskAssistantPromptData`: builds `userContext` and `toolManifest` from `config('prism-tools')`.
  - `BroadcastTaskAssistantStreamJob`: queued job that runs the Prism stream and broadcasts to Reverb.

- **Context sources**
  - System prompt: `resources/views/prompts/task-assistant-system.blade.php`.
  - Conversation history: `TaskAssistantMessage` records (roles: user, assistant, tool).
  - Snapshot of user state: tasks, events, projects (per user) via Eloquent scopes like `forAssistantSnapshot`.
  - Prism tools: class list from `[config/prism-tools.php](config/prism-tools.php)`, resolved into `Tool` instances.

- **Prism integration**
  - Uses Prism message classes: `UserMessage`, `AssistantMessage`, `ToolResultMessage`.
  - Uses Prism streaming: `asEventStreamResponse` and `asBroadcast`.
  - Currently calls Hermes 3:3B as a **text‑only assistant** (no structured response format yet).

### 1.3 Request lifecycle (per user message)

When a user sends a new message, `TaskAssistantService`:

1. **Persists messages**
   - Saves the user message on the thread.
   - Creates a placeholder assistant message to later update with streamed content.

2. **Loads recent history**
   - Loads up to `MESSAGE_LIMIT` messages from `TaskAssistantMessage`.

3. **Maps to Prism messages**
   - Transforms stored messages into:
     - `UserMessage` for user turns.
     - `AssistantMessage` for assistant turns (including deserialized tool calls).
     - `ToolResultMessage` for tool results chained after each assistant tool call.

4. **Builds prompt context**
   - System prompt view: `[resources/views/prompts/task-assistant-system.blade.php](resources/views/prompts/task-assistant-system.blade.php)`.
   - Snapshot via `[app/Services/TaskAssistantSnapshotService.php](app/Services/TaskAssistantSnapshotService.php)`.
   - Tool manifest (`TOOL_MANIFEST`) from `[config/prism-tools.php](config/prism-tools.php)`.
   - User metadata (id, timezone, date format) from `TaskAssistantPromptData`.

5. **Calls Prism**
   - Passes:
     - System prompt.
     - Messages: history + latest user message.
     - Tools (from `resolveTools`).
     - Model `hermes3:3b` + timeout from `config('prism.request_timeout')`.
     - Streaming mode: `asEventStreamResponse` for HTTP, `asBroadcast` for Reverb.

6. **Streams and finalizes**
   - Streams deltas from Prism via `TextDeltaEvent`.
   - Joins them into final assistant content.
   - Updates the placeholder assistant message in the DB.

### 1.4 System prompt, snapshot, and tools

The system prompt file `[resources/views/prompts/task-assistant-system.blade.php](resources/views/prompts/task-assistant-system.blade.php)`:

- **Defines assistant role and tone**
  - Student task assistant.
  - Short, practical answers; avoids chain‑of‑thought in user‑visible text.

- **Injects user metadata**
  - `user_id`, `timezone`, preferred date format.

- **Embeds snapshot JSON**
  - Fields:
    - `today`, `timezone`.
    - `tasks`: each task includes at least
      - `id`, `title`, `status`, `priority`, `ends_at`, `project_id`, `event_id`, `duration_minutes`.
    - `events`: each event includes at least
      - `id`, `title`, `starts_at`, `ends_at`, `all_day`, `status`.
    - `projects`: each project includes at least
      - `id`, `name`, `start_at`, `end_at`.

- **Lists available tools**
  - Uses `TOOL_MANIFEST` built from `[config/prism-tools.php](config/prism-tools.php)`.
  - Gives short descriptions of each tool (name + description from the tool instance).

- **Defines behavior rules**
  - Use snapshot as single source of truth.
  - Prefer short answers.
  - Use tools only for real side effects.
  - No chain‑of‑thought in user‑visible output.

The snapshot builder `[app/Services/TaskAssistantSnapshotService.php](app/Services/TaskAssistantSnapshotService.php)`:

- Uses the **application timezone** (`config('app.timezone')`).
- Calls scope methods like `forAssistantSnapshot($userId, $now)` on:
  - `Task`.
  - `Event`.
  - `Project`.
- Normalizes models into compact arrays suitable for serialization into the system prompt.

### 1.5 Known limitations (baseline)

These are the gaps the later phases will address:

- **No structured output schema**
  - Assistant replies are free‑form text.
  - There is no explicit contract for fields such as:
    - `chosen_task_id`.
    - `chosen_task_title`.
    - `summary`.
    - `reason`.
    - `suggested_next_steps`.

- **No backend validation of assistant content**
  - We do **not** currently:
    - Parse the response.
    - Check that referenced task IDs exist in `snapshot.tasks` / DB.
    - Check that the assistant has not invented tasks, events, or tools.
  - Whatever the model responds with is streamed directly to the user.

- **No retry / corrective loop**
  - If the model violates rules (e.g. invents “Schedule Doctor Appointment”), there is no second pass with targeted feedback.

- **Tools are always enabled**
  - For purely advisory questions (e.g. “What should I do next?”), tools are still available.
  - This increases the chance of hallucinated or misused tools.

- **Small‑model specific issues (Hermes 3:3B)**
  - It is **not reliably capable of emitting the exact Prism tool envelope**:
    - Expected: `{"tool": "list_tasks", "arguments": {...}}`.
    - Even with examples:
      - It often falls back to OpenAI‑style function shapes (`"name"`, `"type"`, `"properties"`).
      - Or explains how it would call tools instead of emitting the correct envelope.
  - As a result:
    - Tool calls suggested by the model are **not executed**.
    - They must **not** be trusted as if side‑effects actually occurred.

**Conclusion for today’s system**  
Treat Hermes 3:3B primarily as a **text‑only reasoning and suggestion engine**. Any real use of tools must be driven or normalized in PHP rather than trusting the raw model output.

---

## 2. Phase 1 – Structured Output & Validation Core

**Objective**: Introduce **structured JSON output** for the main “choose my next task and break it into steps” flow, and add **backend validation** against the snapshot and DB, while keeping the current streaming and prompt behavior.

All changes in this phase must follow the Laravel 12 + PrismPHP conventions in `@.cursor/rules/laravel-boost.mdc`:

- Use Eloquent scopes and services instead of inline logic where appropriate.
- Use Pest for tests.
- Keep Tailwind v4 and frontend behavior consistent.

### 2.1 Phase 1 goals

- **Define a JSON response schema** for the core flow:
  - Fields:
    - `chosen_task_id` (nullable integer, referencing `snapshot.tasks`).
    - `chosen_task_title` (nullable string, matching the chosen task’s title).
    - `summary` (string).
    - `reason` (string).
    - `suggested_next_steps` (array of strings).
- **Configure PrismPHP** to use `ResponseFormat::json([...])` for this flow, via `Prism::text()` and `ResponseFormat::json`:
  - Use a PHP array schema with `type`, `properties`, and `required` keys.
- **Parse and validate JSON** on the backend:
  - Type checks and required fields.
  - Reasonable length limits for strings and arrays.
- **Validate IDs** against the snapshot / DB:
  - `chosen_task_id` must be `null` or an ID present in `snapshot.tasks`.
  - `chosen_task_title` should match the corresponding task title when provided.
- **Preserve the current streaming behavior**:
  - Continue using `asEventStreamResponse` and `asBroadcast` for delivery.

### 2.2 Required code changes

When implementing this phase, focus on:

- **Where to add structured-output calls**
  - In `[app/Services/TaskAssistantService.php](app/Services/TaskAssistantService.php)`, introduce a clear place (new method or mode) to call Hermes 3:3B with `ResponseFormat::json` for the “choose next task and break into steps” flow.
  - Keep the separation between:
    - Building messages (`mapToPrismMessages`).
    - Building prompt data (`TaskAssistantPromptData`).
    - Snapshot building (`TaskAssistantSnapshotService`).

- **How to define and reuse the JSON schema**
  - Prefer a reusable definition:
    - A dedicated helper (e.g. `TaskAssistantSchemas::taskChoiceSchema()`).
    - Or a constant array in a small Laravel-12 style service class.
  - Ensure the schema definition is simple enough for Hermes 3:3B while still enforcing:
    - Types (integer/string/array).
    - Nullable identifiers and titles where appropriate.
    - Required fields: at least `summary`, `reason`, `suggested_next_steps`.

- **Integrating parsing and validation**
  - Use Laravel’s validation facilities rather than manual `if` chains:
    - Write a small validator class or service (e.g. `TaskAssistantResponseValidator`) that:
      - Accepts the decoded JSON and the snapshot.
      - Applies Laravel validation rules for:
        - Types (`integer`, `string`, `array`).
        - Required fields.
        - Membership in allowed IDs.
        - Max lengths (e.g. `max:500` for strings, limited number of steps).
      - Returns a result object/array such as:
        - `['valid' => true, 'data' => [...]]` or
        - `['valid' => false, 'errors' => [...]]`.
  - Align with Laravel 12 best practices:
    - Prefer well‑typed methods and clear class responsibilities.
    - Avoid mixing validation details directly into `TaskAssistantService`.

### 2.3 Error handling and user feedback

In Phase 1, do not implement the full retry loop yet, but **define how invalid responses are handled**:

- **Malformed or missing JSON**
  - If the response cannot be parsed as JSON or fails schema validation:
    - Log the failure with context (user id, thread id, validation errors).
    - Fall back to a short, safe user‑facing message (e.g. “I had trouble understanding that. Try asking again or choosing a task directly.”).

- **Invalid IDs or titles**
  - If `chosen_task_id` is not in `snapshot.tasks` or `chosen_task_title` does not match:
    - Treat as invalid in Phase 1.
    - Do not highlight or act on the invalid ID.
    - Provide a user‑facing explanation that clearly tells the user no task was chosen and they may select one directly.

- **Successful validation**
  - When validation passes:
    - Use the structured output to drive the UI:
      - Highlight `chosen_task_id` in the UI where appropriate.
      - Display `summary`, `reason`, and `suggested_next_steps` as structured content.

### 2.4 Testing for Phase 1

All testing in this phase should follow `@.cursor/rules/laravel-boost.mdc` and use Pest:

- **Pest test scenarios**
  - Valid JSON:
    - Correct types and required fields.
    - `chosen_task_id` present in the snapshot and matching title.
  - Invalid payloads:
    - Missing required fields (e.g. no `summary`).
    - Incorrect types (e.g. `suggested_next_steps` not an array).
    - Too many steps or excessively long strings, if limits are enforced.
  - ID mismatches:
    - `chosen_task_id` not present in `snapshot.tasks`.
    - `chosen_task_title` not matching the task title.

- **Execution**
  - Add or update tests under `tests/Feature` or `tests/Unit` in Pest style.
  - Run minimal relevant tests:
    - `php artisan test --compact --filter=...`.
  - Ensure formatting via:
    - `vendor/bin/pint --dirty`.

---

## 3. Phase 2 – Validate → Retry → Fallback Loop

**Objective**: Wrap the structured‑output flow from Phase 1 in a **validate → retry → deterministic fallback** loop, so small‑model mistakes are corrected or safely handled.

### 3.1 Phase 2 goals

- **Compute rich validation results**
  - On validation failure, produce:
    - An `invalidReason` string describing the main issue.
    - A list of allowed IDs, e.g. `[3, 5, 9, 17, 21, 22, 23, 24, 25, 30, 31]` from `snapshot.tasks`.

- **Add a corrective message and retry**
  - When the first response is invalid:
    - Add a corrective system/assistant message that:
      - Explains what was wrong (e.g. invalid ID).
      - Lists the exact allowed IDs.
      - Restates the requirement to respond with the same JSON schema.
    - Call Prism again with:
      - Same system prompt and snapshot.
      - Same conversation history.
      - Same user message.
      - Additional corrective message.
      - Same JSON response schema.

- **Cap retries and ensure determinism**
  - Introduce `maxRetries` (e.g. 1–2).
  - Ensure the loop terminates deterministically:
    - After `maxRetries` attempts, stop calling the model.

- **Deterministic backend fallback**
  - When all attempts fail validation:
    - Use a deterministic backend algorithm to choose a task (see below).
    - Use the model only for explanation and decomposition of that choice (Phase 3 can refine this further).

### 3.2 Required code changes

To keep `[app/Services/TaskAssistantService.php](app/Services/TaskAssistantService.php)` tidy, introduce a small orchestration helper responsible for the loop:

- **Orchestration helper**
  - Example responsibility:
    - Method like `runTaskChoiceFlow($user, $thread, $snapshot, $messages)` that:
      - Calls Prism with the JSON schema.
      - Parses and validates the response.
      - On failure, computes `invalidReason` and allowed IDs.
      - Assembles and sends a corrective message.
      - Enforces `maxRetries`.
      - Invokes deterministic fallback when needed.
  - This helper should:
    - Be well‑typed.
    - Encapsulate the detailed loop logic.
    - Leave `TaskAssistantService` focused on:
      - Thread/message persistence.
      - Streaming/broadcasting.
      - Top‑level orchestration.

- **Corrective message template**
  - Define a short, clear template, for example:

    > Your previous JSON output referenced `chosen_task_id = 999`, which is not present in `snapshot.tasks`. Retry the same task and respond with the same JSON schema, but this time you MUST choose `chosen_task_id` from this exact set of IDs only: [3, 5, 9, 17, 21, 22, 23, 24, 25, 30, 31]. Do not invent any new tasks.

  - The helper should format this message by:
    - Injecting the invalid ID (if any).
    - Injecting the allowed IDs from the snapshot.
    - Keeping language concise for Hermes 3:3B.

- **Configuration and logging**
  - Store `maxRetries` in configuration or a clearly named constant.
  - Add structured logging for:
    - First‑attempt validation failures.
    - Retry attempts.
    - Final fallback usage.

- **Deterministic task ranking**
  - Implement a simple, deterministic ranking:
    - Use fields from the snapshot: `priority`, `ends_at`, `duration_minutes`, etc.
    - Encapsulate in a PHP service or helper (e.g. `TaskRankingService` or a dedicated method on `TaskAssistantSnapshotService`).
  - Ensure:
    - The same snapshot always produces the same chosen `task_id`.
    - The ranking logic is concentrated in PHP, not embedded in prompts.

### 3.3 Prompt and snapshot updates

In this phase, slightly refine `[resources/views/prompts/task-assistant-system.blade.php](resources/views/prompts/task-assistant-system.blade.php)` to make the loop more effective for Hermes 3:3B:

- **Reinforce snapshot as source of truth**
  - Keep the rule that:
    - The model must not reference tasks/events/projects outside `snapshot`.
  - Keep examples aligned with actual snapshot shape and identifiers.

- **Reference the JSON contract conceptually**
  - Do not paste large JSON schemas into the prompt.
  - Instead, describe the structure briefly in natural language and rely on `ResponseFormat::json` to enforce details.

- **Stay small‑model‑friendly**
  - Maintain a small set of numbered behavior rules.
  - Remove redundant or conflicting instructions if necessary.

### 3.4 Testing for Phase 2

Add Pest tests to verify the loop’s behavior:

- **Scenarios**
  - First attempt invalid → second attempt valid:
    - Simulate an invalid first JSON and a valid second JSON.
    - Assert that:
      - Validation is re‑run.
      - The final accepted result is the second output.
  - All attempts invalid → backend fallback used:
    - Simulate multiple invalid JSON outputs.
    - Assert that:
      - Fallback logic chooses a task.
      - The chosen `task_id` comes from the deterministic ranking.
  - Logging and error handling:
    - Ensure important events are logged (failures, retries, fallback).

- **Execution**
  - Use Pest helpers and mocking where appropriate.
  - Run relevant tests via:
    - `php artisan test --compact --filter=...`.

---

## 4. Phase 3 – Tool Gating & Small‑Model‑Oriented Orchestration

**Objective**: Implement **tool gating**, **PHP‑driven tool execution**, and small‑model‑friendly patterns for core flows, so Hermes 3:3B focuses on reasoning and phrasing while Laravel 12 owns side effects.

### 4.1 Phase 3 goals

- **Disable tools for advisory flows**
  - For prioritization and planning questions (e.g. “What should I work on next?”):
    - Pass an empty tools array to Prism.
    - Prevent hallucinated tool calls and side effects.

- **Tightly scope tools for mutating flows**
  - For create/update/delete flows:
    - Pass only the relevant tools from `[config/prism-tools.php](config/prism-tools.php)`.
    - Validate tool arguments in PHP:
      - Ownership.
      - Rate limits.
      - ID existence.

- **Adopt PHP‑driven tool patterns**
  - Pattern A – **LLM suggests, PHP executes**:
    - LLM outputs plain text or lightweight JSON:

      ```json
      { "action": "list_tasks", "args": { "projectId": 12 } }
      ```

    - PHP:
      - Interprets `action` and `args`.
      - Maps them to concrete Prism tools:
        - `ListTasksTool`.
        - `CreateTaskTool`.
        - etc.
      - Enforces all validation before calling any tool.
  - Pattern B – **JSON normalizer for “almost‑correct” tool calls**:
    - If the model emits:

      ```json
      { "name": "list_tasks", "arguments": { "limit": 50, "projectId": null } }
      ```

      or:

      ```json
      { "function": "list_tasks", "parameters": { "limit": 50 } }
      ```

    - A normalizer maps this to:

      ```json
      { "tool": "list_tasks", "arguments": { "limit": 50, "projectId": null } }
      ```

    - The backend then treats this as a valid Prism tool call.

### 4.2 Required code changes

- **Conditional tools in `TaskAssistantService`**
  - Introduce a notion of **intent** or **mode** for each request:
    - Advisory / planning (no tools).
    - Mutating (specific tools only).
  - Adjust the logic that currently calls `resolveTools($user)` to:
    - Sometimes pass `[]`.
    - Sometimes pass a filtered list of tools based on the mode.

- **Tool suggestion interpreter / normalizer**
  - Implement a small interpreter that:
    - Reads lightweight JSON suggestions from the model.
    - Maps them to:
      - Tool class names from `[config/prism-tools.php](config/prism-tools.php)`.
      - Argument arrays validated against Laravel validation rules.
  - Implement a normalizer for “almost‑correct” envelopes as described in Pattern B.

- **Validation and execution**
  - All destructive operations should:
    - Be validated via Laravel’s validation and authorization facilities.
    - Use existing tools and actions (e.g. task creation/update/delete) rather than ad‑hoc DB calls.

### 4.3 Prompt and system instructions

Refine `[resources/views/prompts/task-assistant-system.blade.php](resources/views/prompts/task-assistant-system.blade.php)` for Hermes 3:3B:

- **Simplify tool instructions**
  - Keep a short, clear description of the required tool envelope (e.g. `"tool"` + `"arguments"`).
  - Avoid long schemas or multiple competing formats.

- **Use concrete examples**
  - Provide 1–2 examples of:
    - A correct tool envelope when tools are allowed.
    - A purely advisory response when tools are disabled.

- **Avoid redundant text**
  - Remove repeated or conflicting instructions, keeping the prompt:
    - Short.
    - Snapshot‑driven.
    - Easy for a small model to follow.

### 4.4 Testing for Phase 3

Add Pest tests that verify orchestration around tools:

- **Scenarios**
  - Advisory flow:
    - Tools array is empty.
    - No tool calls are executed, even if the model suggests some.
  - Mutating flow:
    - Only specific tools are exposed.
    - Suggested tool‑like JSON is normalized and executed only when valid.
    - Invalid tool suggestions are safely ignored or rejected.

- **Execution**
  - Use Pest and mocks to simulate tool suggestions and execution.
  - Run the minimal relevant tests with:
    - `php artisan test --compact --filter=...`.

---

## 5. Phase 4 – Advanced Flows & UX Integration (Optional / Later)

**Objective**: Extend the structured orchestration pattern from Phases 1–3 to additional flows (daily schedules, reviews, summaries), and clarify how the frontend should use structured outputs.

### 5.1 Additional structured schemas

- **Daily schedule proposal**
  - Define JSON describing:
    - Time blocks.
    - Associated tasks or events (`task_id` / `event_id` from the snapshot).
    - Brief rationales per block.

- **Study plan / revision plan**
  - Define JSON describing:
    - Topics or tasks to review.
    - Suggested time allocations.
    - Ordered steps with short rationales.

- **Task review summaries**
  - Define JSON summarizing:
    - Completed work over a period.
    - Remaining tasks grouped by project or status.
    - Suggested follow‑up actions.

### 5.2 Broader use of validate → retry → fallback

- **Reuse orchestration utilities**
  - Apply the same validate → retry → fallback helper to:
    - Daily schedule proposals.
    - Study/revision plans.
    - Review summaries.
  - Ensure all flows:
    - Use snapshot IDs.
    - Enforce JSON schemas via `ResponseFormat::json`.
    - Never accept invented tasks/events/projects.

### 5.3 Frontend / UI integration notes

- **Structured output consumption**
  - The UI should:
    - Highlight `chosen_task_id` from the assistant response.
    - Render `suggested_next_steps` as a small checklist or ordered list.
    - For schedules, render time blocks visually using existing Tailwind v4 conventions.

- **Alignment with existing stack**
  - Use existing Livewire components and Flux UI components where available.
  - Follow Tailwind v4 guidelines from `laravel-boost.mdc`.
  - Keep UX changes small and consistent with current patterns.

### 5.4 Testing for Phase 4

- **Tests for new flows**
  - Add Pest tests for each new structured flow:
    - Schema enforcement.
    - Validation of IDs and required fields.
    - Retry and fallback behavior.

- **Execution**
  - Run relevant test filters via:
    - `php artisan test --compact --filter=...`.

---

## 6. Global Guidelines & Checklist

Use this checklist whenever you add or modify Task Assistant behavior with Hermes 3:3B and PrismPHP.

### 6.1 Global guidelines

- **Do things the Laravel 12 way**
  - Use service classes, Eloquent scopes, and Laravel validation.
  - Keep business rules in PHP, not only in prompts.
- **Use PrismPHP correctly**
  - Prefer `ResponseFormat::json` for flows that need structure.
  - Use streaming (`asEventStreamResponse`, `asBroadcast`) where appropriate.
- **Treat Hermes 3:3B as a suggestion engine**
  - Let Laravel own:
    - Task selection and ranking.
    - Validation of IDs and state.
    - All destructive operations.

### 6.2 Checklist for any new assistant behavior

When implementing a new behavior (for example, “choose next task and break into steps” or “propose today’s study plan”):

1. **Define the JSON schema**
   - Decide required and optional fields.
   - Reference snapshot fields and IDs.
2. **Configure PrismPHP**
   - Use `ResponseFormat::json([...])` with the chosen schema.
3. **Parse and validate output**
   - Decode JSON.
   - Validate with Laravel rules (types, required fields, length limits).
   - Verify IDs against the snapshot / DB.
4. **Add validate → retry → fallback**
   - Implement the loop with:
     - Clear `invalidReason`.
     - Allowed ID lists.
     - `maxRetries`.
     - Deterministic fallback logic.
5. **Push core logic into PHP**
   - Keep ranking, selection, and side‑effects in PHP.
   - Use the LLM for explanation, decomposition, and phrasing.
6. **Gate tools appropriately**
   - Disable tools where they are not needed.
   - Expose only the minimal required tools where they are needed.
   - Normalize and validate any tool suggestions before execution.
7. **Write or update Pest tests**
   - Cover:
     - Successful structured responses.
     - Invalid responses that trigger retry.
     - Fallback behavior.
     - Tool orchestration and gating.
8. **Run formatter and minimal tests**
   - `vendor/bin/pint --dirty`.
   - `php artisan test --compact --filter=...`.

With this phased roadmap, the Task Assistant can be iterated safely and predictably, while staying fully aligned with Laravel 12, PrismPHP, and the small‑model constraints of Hermes 3:3B.

