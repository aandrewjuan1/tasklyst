## Task Assistant Orchestration Guide  
### (Laravel 12 + PrismPHP + Hermes 3:3B)

This file is an **implementation plan and reference guide for AI agents (like this one) and Laravel engineers** working on the Task Assistant.

It explains:

- **What exists today** in the Laravel 12 / PrismPHP / Hermes 3:3B stack.
- **What needs to be made more robust**, especially for small local models.
- **How to implement those improvements step‑by‑step**, following the best practices captured in `@.cursor/rules/laravel-boost.mdc` (Laravel 12, PrismPHP, Tailwind v4, Pest, etc.).

All details from the previous version of this document are preserved but reorganized as an **actionable plan**.

---

## 1. Stack & Responsibilities

### 1.1 Core stack

- **Framework**: Laravel 12 (see `laravel-boost.mdc` for project‑wide conventions).
- **LLM orchestration**: PrismPHP.
- **Model**: `hermes3:3b` via PrismPHP (`Provider::Ollama, 'hermes3:3b'`).
- **Assistant domain**: student‑focused Task Assistant (prioritization, planning, breaking tasks into steps).

### 1.2 Task Assistant components

- **Service layer**
  - `TaskAssistantService`: main orchestration and streaming service.
  - `TaskAssistantSnapshotService::buildForUser($user)`: builds the snapshot injected into the prompt.

- **Context sources**
  - System prompt: `resources/views/prompts/task-assistant-system.blade.php`.
  - Conversation history: `TaskAssistantMessage` records.
  - Snapshot of user state: tasks, events, projects (per user).
  - Prism tools: class list from `config('prism-tools')`.

- **Prism integration**
  - Uses Prism message classes (`UserMessage`, `AssistantMessage`, `ToolResultMessage`).
  - Uses Prism response streaming: `asEventStreamResponse` or `asBroadcast`.
  - Uses Prism response formats for structured JSON (target state – see section 3).

---

## 2. Current Behavior (Baseline)

This section captures **how the system works today**. Future changes should treat this as the baseline behavior to preserve unless explicitly changed.

### 2.1 Request lifecycle (per user message)

When a user sends a new message, `TaskAssistantService`:

1. **Persists messages**
   - Saves the user message.
   - Creates a placeholder assistant message to later update with streamed content.

2. **Loads recent history**
   - Loads up to `MESSAGE_LIMIT` messages from `TaskAssistantMessage`.

3. **Maps to Prism messages**
   - Transforms stored messages into:
     - `UserMessage` for user turns.
     - `AssistantMessage` for assistant turns (including deserialized tool calls).
     - `ToolResultMessage` for tool results chained after each assistant tool call.

4. **Builds prompt context**
   - System prompt view: `task-assistant-system.blade.php`.
   - Snapshot via `TaskAssistantSnapshotService::buildForUser($user)`.
   - Tool manifest (`TOOL_MANIFEST`) from `config('prism-tools')`.
   - User metadata (id, timezone, date format).

5. **Calls Prism**
   - Passes:
     - System prompt.
     - Messages: history + latest user message.
     - Tools (from `resolveTools`).
     - Model `hermes3:3b` + timeout.
     - Streaming mode (`asEventStreamResponse` / `asBroadcast`).

6. **Streams and finalizes**
   - Streams deltas from Prism.
   - Joins them into final assistant content.
   - Updates the placeholder assistant message in the DB.

### 2.2 System prompt + snapshot details

The system prompt file `task-assistant-system.blade.php`:

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
  - Uses `TOOL_MANIFEST` built from `config('prism-tools')`.
  - Gives short descriptions of each tool.

- **Defines behavior rules**
  - Use snapshot as single source of truth.
  - Prefer short answers.
  - Use tools only for real side effects.
  - No chain‑of‑thought in user‑visible output.

The snapshot builder `TaskAssistantSnapshotService::buildForUser($user)`:

- Uses the **application timezone**.
- Calls scope methods like `forAssistantSnapshot($userId, $now)` on:
  - `Task`.
  - `Event`.
  - `Project`.
- Normalizes models into compact arrays suitable for serialization into the system prompt.

### 2.3 Tools and history plumbing

- **Tools**
  - `resolveTools(User $user)`:
    - Iterates over classes defined in `config('prism-tools')`.
    - Instantiates each tool with the current user.

- **Conversation history**
  - `mapToPrismMessages(Collection $messages)`:
    - User messages → `UserMessage`.
    - Assistant messages → `AssistantMessage` (including any serialized tool calls).
    - Tool result records → `ToolResultMessage` chained after each tool call.

The model therefore sees on every call:

- System prompt with **snapshot JSON** and **tool manifest**.
- Full recent history of:
  - Text.
  - Tool calls and tool results.
- The current user utterance.

### 2.4 Known limitations (today)

These are the gaps this guide expects you (and the AI agent) to close.

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

## 3. Target Orchestration Design (Small‑Model Friendly)

Goal: **Wrap** Hermes 3:3B with strong contracts and server‑side safeguards, instead of relying only on prompt obedience.

All changes in this section must follow the Laravel 12 + PrismPHP conventions in `@.cursor/rules/laravel-boost.mdc`:

- Use Eloquent scopes and services instead of inline logic where appropriate.
- Use Pest for tests.
- Keep Tailwind v4 and frontend behavior consistent.

### 3.1 Introduce structured output (JSON schemas)

**Objective**: Replace free‑form responses with JSON that the backend can validate.

Example flow: *“Choose my next task and break it into steps”*.

- **Response schema**

  ```json
  {
    "chosen_task_id": 23,
    "chosen_task_title": "Review today’s lecture notes",
    "summary": "Short summary of the plan.",
    "reason": "Why this is the best task now.",
    "suggested_next_steps": [
      "Open your notes for today's lecture.",
      "Skim the headings to recall the structure.",
      "Spend 20 minutes reviewing examples."
    ]
  }
  ```

- **Implementation (PrismPHP pseudo‑code)**

  ```php
  use Prism\Prism\Enums\ResponseFormat;

  Prism::text()
      ->using(Provider::Ollama, 'hermes3:3b')
      ->withResponseFormat(
          ResponseFormat::json([
              'type' => 'object',
              'properties' => [
                  'chosen_task_id' => ['type' => 'integer', 'nullable' => true],
                  'chosen_task_title' => ['type' => 'string', 'nullable' => true],
                  'summary' => ['type' => 'string'],
                  'reason' => ['type' => 'string'],
                  'suggested_next_steps' => [
                      'type' => 'array',
                      'items' => ['type' => 'string'],
                  ],
              ],
              'required' => ['summary', 'reason', 'suggested_next_steps'],
          ])
      );
  ```

**What this guarantees**

- Output has a **predictable shape**.
- All required fields are present.
- Laravel code can safely parse and inspect it.

### 3.2 Add backend validation for structured output

Once JSON is returned (via Prism’s `ResponseFormat::json`):

1. **Parse JSON** into a PHP array (using Laravel’s helpers).
2. **Validate against snapshot and DB**:
   - `chosen_task_id`
     - Must be `null` (no suitable task) **or** present in the set of IDs from `snapshot.tasks`.
   - `chosen_task_title`
     - Should match the title for that ID in `snapshot.tasks` if provided.
   - `suggested_next_steps`
     - Non‑empty array of strings.
     - Each string within a reasonable length limit.
3. **On validation success**
   - Use JSON to drive the UI:
     - Highlight `chosen_task_id`.
     - Display `summary`, `reason`, and `suggested_next_steps`.
4. **On validation failure**
   - Treat the output as **invalid**.
   - Either:
     - Retry with corrective feedback (see 3.3).
     - Or fall back to deterministic backend logic (see 3.4).

### 3.3 Implement a validate → retry → fallback loop

**Pattern**: `validate → retry (with corrective instructions) → deterministic fallback`.

Steps:

1. Compute:
   - `invalidReason` (short string describing why validation failed).
   - Set of valid task IDs, e.g. `[3, 5, 9, 17, 21, 22, 23, 24, 25, 30, 31]`.
2. Add a corrective system/assistant message for a retry call, for example:

   > Your previous JSON output referenced `chosen_task_id = 999`, which is not present in `snapshot.tasks`. Retry the same task and respond with the same JSON schema, but this time you MUST choose `chosen_task_id` from this exact set of IDs only: [3, 5, 9, 17, 21, 22, 23, 24, 25, 30, 31]. Do not invent any new tasks.

3. Call Prism again with:
   - Same system prompt and snapshot.
   - Same conversation history.
   - Same user message.
   - Additional corrective message.
   - Same JSON response schema.
4. Re‑validate the new output.
5. **Cap retries**:
   - `maxRetries` in the range `1–2`.
6. If output is still invalid:
   - Use deterministic backend selection (see 3.4).
   - Optionally ask the user to choose directly.

This loop is especially important for small models, which are more likely to ignore strict constraints on the first attempt.

### 3.4 Deterministic fallback: backend decides, LLM explains

To reduce hallucination risk:

- **Move decision logic into PHP**
  - Compute the “best” task using deterministic logic:
    - Priority.
    - Deadline (`ends_at`).
    - Duration (`duration_minutes`).
    - Other heuristics defined in Laravel code.
  - The chosen ID becomes the canonical `chosen_task_id`.

- **Constrain the model to explanation + decomposition**
  - Inject into the system/assistant message:

    > The chosen task from `snapshot.tasks` is the one with `id = 23`. You MUST use this `chosen_task_id` and must not change it. Produce JSON with fields `summary`, `reason`, and `suggested_next_steps` describing why this task is best and how to start.

  - The final JSON shown to the frontend uses:
    - `chosen_task_id` from backend logic.
    - `summary`, `reason`, `suggested_next_steps` from the model.

**Effect**

- Laravel code owns **core decisions**.
- Hermes 3:3B focuses on **phrasing and flow**.

### 3.5 Tool gating strategy

**Goal**: Only expose tools when they are genuinely needed.

- For **pure prioritization / planning**:
  - Set `$tools = []` when creating the Prism request.
  - Prevents hallucinated tool calls and side effects.

- For **mutating intents** (create/update/delete tasks/events):
  - Pass **only the relevant tools** from `config('prism-tools')`.
  - Enforce backend validation of tool arguments:
    - Ownership.
    - Rate limits.
    - ID existence.

This keeps interactions predictable and safer, especially with small models.

---

## 4. PrismPHP + Small Models: Operational Playbook

This section is a **checklist‑style playbook** for working with Hermes 3:3B and PrismPHP in this Laravel 12 app.

### 4.1 Tool orchestration pattern (recommended)

Hermes 3:3B is not reliably able to emit our exact Prism tool envelope `{"tool": "...", "arguments": {...}}`. The robust pattern:

- **Pattern A – LLM suggests, PHP executes**
  - Model outputs either:
    - Plain text: `"I should list your tasks first."`
    - Or lightweight JSON:

      ```json
      { "action": "list_tasks", "args": { "projectId": 12 } }
      ```

  - Laravel interprets `action` and `args`:
    - Maps to concrete Prism tool classes:
      - `ListTasksTool`.
      - `CreateTaskTool`.
      - etc.
  - All validation (ownership, limits, IDs) is done in PHP **before** any side effects.

- **Pattern B – JSON normalizer for “almost‑correct” tool calls**
  - If the assistant responds with:

    ```json
    { "name": "list_tasks", "arguments": { "limit": 50, "projectId": null } }
    ```

    or similar OpenAI‑style function shapes:

    ```json
    { "function": "list_tasks", "parameters": { "limit": 50 } }
    ```

  - Use a small normalizer to map to:

    ```json
    { "tool": "list_tasks", "arguments": { "limit": 50, "projectId": null } }
    ```

  - Then treat this as a valid tool call and execute the mapped Prism tool.

**Key constraint**

- Hermes 3:3B only needs to indicate **what** should happen.
- The Laravel backend:
  - Translates suggestions to real tool invocations.
  - Enforces all business rules.

### 4.2 Push logic into PHP

- Use **PHP** for:
  - Task selection and ranking.
  - Time and schedule feasibility (what fits in 30/60 minutes).
  - State validation (existence, ownership).
  - All destructive operations (create, update, delete).

- Use the **LLM** for:
  - Natural language phrasing: summaries, reasons, encouragement.
  - Decomposition: breaking a chosen task into steps.
  - Light prioritization **within** a pre‑filtered subset if needed.

This aligns with Laravel 12 best practices (per `laravel-boost.mdc`): keep business rules in PHP, not in prompt text.

### 4.3 Use structured output wherever possible

- Define JSON schemas for:
  - Task choice and explanation.
  - Daily schedule proposals.
  - Summaries and review plans.

- Enforce schemas via Prism’s response formats.
- Always:
  - Parse JSON.
  - Validate IDs and required fields.
  - Reject/Retry on violations.

This creates a stable contract between Hermes 3:3B and the Laravel backend.

### 4.4 Standard validate → retry → fallback loop

- **Validate**
  - IDs exist in snapshot/DB.
  - Types are correct (integers, strings, arrays).
  - Required fields are not empty.

- **Retry**
  - Exactly one short corrective message.
  - Explicitly lists the valid IDs or constraints.
  - `maxRetries` low (1–2).

- **Fallback**
  - Backend selects `chosen_task_id` deterministically.
  - LLM only explains/decomposes the backend choice.

Treat the model as a **best‑effort suggestion engine**, not a source of authority for core decisions.

### 4.5 Disable tools when not needed

- For read‑only and advisory questions:
  - Pass an empty tool list to Prism.

- For tool‑driven flows:
  - Scope the tool list to only what is needed.
  - Validate tool arguments in PHP before performing any side effects.

This reduces exposure to hallucinated tools and improves safety.

### 4.6 Prompt design for small models

- Small models do better with:
  - Short, explicit rule lists.
  - Concrete examples with placeholders (e.g. `[task_id_from_snapshot]`).

- Guidelines for `task-assistant-system.blade.php`:
  - Prefer a **small number of numbered rules**.
  - Keep examples aligned with the **real snapshot shape**.
  - Avoid hard‑coded fake tasks that look “real”.
  - Remove redundant instructions; keep only the clearest constraints.

### 4.7 Temperature settings

- Use **low temperature** for:
  - Task choice.
  - Concrete plans and schedules.
  - Any flow where stability matters.

- Consider slightly **higher temperature** only for:
  - Motivational or “chatty” text.

Low temperature helps Hermes 3:3B stay closer to the center of its learned behavior.

### 4.8 Snapshot as single source of truth

- Treat the snapshot JSON in the system prompt as:
  - The **only legal** set of tasks/events/projects for that turn.
  - The canonical source for both backend and LLM.

- Enforce this via:
  - System prompt rules:
    - The model must not reference tasks/events/projects outside `snapshot`.
  - Backend validation:
    - Reject outputs that mention unknown IDs/titles.
    - Trigger the validate → retry → fallback loop when violated.

This directly targets a common small‑model failure mode: inventing plausible but nonexistent tasks or events.

---

## 5. Implementation Checklist for Agents & Engineers

Use this as a **step‑by‑step checklist** when making the assistant more robust.

### 5.1 Before coding

- **Confirm stack assumptions**
  - Laravel 12 conventions from `@.cursor/rules/laravel-boost.mdc`.
  - PrismPHP usage patterns.
  - Hermes 3:3B model via `Provider::Ollama`.

- **Locate key files**
  - `TaskAssistantService`.
  - `TaskAssistantSnapshotService`.
  - `task-assistant-system.blade.php`.
  - `config('prism-tools')` and tool classes.

### 5.2 Add or update features

When implementing a new behavior (e.g. “choose next task and break into steps”):

1. **Define the JSON schema** (section 3.1).
2. **Configure PrismPHP** to use `ResponseFormat::json(...)`.
3. **Parse and validate** the JSON in PHP (section 3.2).
4. **Add the validate → retry → fallback loop** (section 3.3).
5. **Push core logic into PHP** (section 4.2).
6. **Gate tools** appropriately (section 3.5 / 4.5).
7. **Update the system prompt** to:
   - Reference the schema conceptually (no huge JSON inside the prompt).
   - Re‑emphasize snapshot as single source of truth.

### 5.3 Testing (per `laravel-boost.mdc`)

- Write or update **Pest tests** for:
  - Successful structured responses.
  - Invalid responses that trigger retry.
  - Fallback logic when retries fail.
  - Tool orchestration and tool gating.
- Run minimal relevant tests:
  - `php artisan test --compact --filter=...`.
- Ensure formatting with:
  - `vendor/bin/pint --dirty`.

---

## 6. High‑Level Summary

- The current assistant has a **solid foundation**:
  - Snapshot injected into the system prompt.
  - Clear behavior rules.
  - Stable Laravel 12 service layer for streaming and persistence.

- To make it robust with **Hermes 3:3B + PrismPHP** while respecting `laravel-boost.mdc` best practices:
  1. Introduce **structured output schemas** for key flows.
  2. Add **backend validation** of IDs and required fields.
  3. Implement a **validate → retry → fallback** loop.
  4. Move decision logic into Laravel/PHP and let the model handle explanation and decomposition.
  5. **Gate tools** tightly and keep prompts **short, concrete, and snapshot‑driven**.

With these changes, the small local model behaves like a reliable **assistant engine** while the Laravel 12 backend (guided by `laravel-boost.mdc`) remains in full control of correctness, safety, and user experience.


