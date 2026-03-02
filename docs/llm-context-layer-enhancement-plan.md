# Context Layer Enhancement Plan

## 1. Outline: What We’re Improving

- **Goal:** Make the context we send to the LLM **intent-specific**: only the fields and sizes each intent needs, so we use fewer tokens and reduce noise.
- **Approach:** Intent-conditioned payload shapes (slim vs full) and, where useful, intent-specific description length. No new infra (no embeddings or vector DB).
- **Out of scope for this plan:** Semantic RAG (embedding-based retrieval); that can be a later phase.

---

## 2. Current State (Baseline)

| Area | Current behavior |
|------|-------------------|
| **What we fetch** | Entity type (task/event/project) + intent-specific **limits** (e.g. AdjustTaskDeadline → 5 tasks, GeneralQuery → 8 tasks). ResolveDependency uses a dedicated mixed tasks+events payload. |
| **Task payload** | Same 11 fields for every task intent: `id`, `title`, `description` (200 chars), `status`, `priority`, `complexity`, `duration`, `start_datetime`, `end_datetime`, `project_id`, `event_id`, `is_recurring`. |
| **Event payload** | Same 8 fields for every event intent. |
| **Project payload** | Same shape; nested tasks have 5 fields: `id`, `title`, `end_datetime`, `priority`, `is_recurring`. |
| **ResolveDependency** | Already slimmer: tasks (entity_type, id, title, end_datetime, status, is_recurring), events (entity_type, id, title, start/end, is_recurring). |

So we already vary **how many** items per intent; we do **not** yet vary **which fields** per intent.

---

## 3. Intent–Context Contract (What Each Intent Needs)

Derived from prompt templates and schemas:

| Intent | Entity | Fields actually needed (slim) | Notes |
|--------|--------|------------------------------|--------|
| **ScheduleTask** | Task | id, title, description (short), end_datetime, duration, priority, start_datetime?, is_recurring. Optional: project_id, event_id (conflicts). | Drop: complexity; keep description for blockers. |
| **AdjustTaskDeadline** | Task | Same as ScheduleTask; often single task (entityId). | Can use same shape as ScheduleTask. |
| **PrioritizeTasks** | Task | id, title, end_datetime, priority, is_recurring. Optional: status, description (very short). | Drop: complexity, duration, start_datetime, project_id, event_id for ranking. |
| **ScheduleEvent** | Event | id, title, description, start_datetime, end_datetime, all_day, is_recurring. | status optional. |
| **AdjustEventTime** | Event | Same as ScheduleEvent. | |
| **PrioritizeEvents** | Event | id, title, start_datetime, end_datetime, is_recurring. | Drop: description, all_day, status for ranking. |
| **ScheduleProject** / **AdjustProjectTimeline** | Project | id, name, description, start_datetime, end_datetime; tasks: id, title, end_datetime, priority, is_recurring. | Already relatively slim. |
| **PrioritizeProjects** | Project | id, name; tasks: id, title, end_datetime, priority. | Can drop project description/dates if we want. |
| **ResolveDependency** | Mixed | Already slimmer; no change. | |
| **GeneralQuery** | Task/Event/Project | Keep **full** (or near-full) so the model can answer “list tasks with no due date”, “low priority”, etc. | |

---

## 4. Concrete Implementation Plan

### Phase A: Define field sets (no behavior change yet)

1. **Add a contract in code**
   - In `ContextBuilder`, or in a dedicated class (e.g. `ContextFieldSets`), define which fields are included per (intent, entity_type).
   - Options:
     - **Option A (recommended):** Add private methods or a small map in `ContextBuilder`: e.g. `taskFieldsForIntent(LlmIntent $intent): array` returning the list of field keys to include for tasks. Same for events and for project (top-level vs nested task).
     - **Option B:** New class `App\Services\Llm\ContextFieldSets` with static or instance methods like `taskFields(LlmIntent $intent): array`. `ContextBuilder` depends on it.
   - Recommendation: start with **Option A** inside `ContextBuilder` to avoid new files; extract to `ContextFieldSets` later if it grows.

2. **Field set definitions**
   - **Task – slim (schedule/adjust):** `id`, `title`, `description`, `end_datetime`, `duration`, `priority`, `start_datetime`, `is_recurring`, optionally `project_id`, `event_id`. Omit: `complexity`, or include only for GeneralQuery.
   - **Task – slim (prioritize):** `id`, `title`, `end_datetime`, `priority`, `is_recurring`, `status`. Omit: `description`, `complexity`, `duration`, `start_datetime`, `project_id`, `event_id`.
   - **Task – full (general_query):** current full set (all 11 fields).
   - **Event – slim (schedule/adjust):** current set is already lean; optionally drop `status` for schedule if not needed.
   - **Event – slim (prioritize):** `id`, `title`, `start_datetime`, `end_datetime`, `is_recurring`. Omit: `description`, `all_day`, `status`.
   - **Event – full (general_query):** current full set.
   - **Project:** Keep current; optionally add a “slim” for PrioritizeProjects (name + tasks with id, title, end_datetime, priority only).

3. **Description length per intent**
   - Add intent-specific description max length (e.g. prioritization intents: 80 chars; schedule/adjust: 200; general_query: 200). Either constants in `ContextBuilder` or config keys like `tasklyst.context.description_max_chars_by_intent` (could be a map). Start simple: one constant for “slim” (80) and use 200 for “full”.

### Phase B: Use field sets in ContextBuilder

4. **Refactor task/event/project building**
   - In `buildTaskContext`: instead of one big `map(fn (Task $t) => [...])` with all keys, compute `$fields = $this->taskFieldsForIntent($intent)` and build the task array only with those keys (and only include `description` if in `$fields`, using the appropriate max length).
   - Same for `buildEventContext` and `buildProjectContext` (and nested task shape inside projects).
   - Keep `buildResolveDependencyContext` as-is (already slim).

5. **Implement field builders**
   - Add private methods, e.g. `taskPayloadItem(Task $t, LlmIntent $intent): array` that:
     - Resolves the field set for `$intent`.
     - Returns only those keys; for `description` use intent-based max length.
   - Same idea for events and for project/task nested items.

6. **Backward compatibility**
   - Ensure every intent that currently receives a field still receives it (no removal of fields that prompts/schemas rely on). PrioritizeTasks only needs title, end_datetime, priority, etc., so dropping complexity/project_id is safe; GeneralQuery must keep full set for filters like “no due date” / “low priority”.

### Phase C: Config and documentation

7. **Config (optional)**
   - If we want to tune without code changes later, add config keys for “slim description max chars” and “full description max chars” (or per-intent). Not required for first version.

8. **Documentation**
   - In `ContextBuilder`, add a PHPDoc block at the top describing the “context contract”: e.g. “For each intent we send only the entity type and fields needed: schedule intents get time + description; prioritize intents get id/title/end_datetime/priority; GeneralQuery gets full payload for list/filter.”
   - Optionally reference this plan doc in a one-line comment.

### Phase D: Tests and cleanup

9. **Tests**
   - Update or add tests in `tests/Feature/Llm/LlmContextTest.php`:
     - Assert that for a given intent (e.g. `PrioritizeTasks`), task items in context do **not** contain `complexity` (or `project_id` if we drop it for prioritization).
     - Assert that for `GeneralQuery`, task items still contain the full set (e.g. `description`, `complexity`).
     - Assert that `ScheduleTask` or `AdjustTaskDeadline` task items contain `duration`, `priority`, `description`, `end_datetime`, etc.
   - Keep existing tests that check keys like `id`, `title`, `is_recurring`, `end_datetime`; adjust expected keys per intent where we slim down.

10. **Pint and final check**
    - Run `vendor/bin/pint --dirty` and full LLM test suite (`LlmContextTest`, `LlmInferenceTest`, etc.).

---

## 5. Summary Checklist

- [x] Phase A: Add field-set logic (task/event/project + intent → list of keys; description length rule).
- [x] Phase B: Refactor `buildTaskContext` / `buildEventContext` / `buildProjectContext` to use field sets and intent-based description length.
- [x] Phase C: Document context contract in `ContextBuilder`; optional config for description length.
- [x] Phase D: Tests for intent-specific shapes; Pint; run LLM tests.

---

## 6. Frontend UI Flow (Optimistic / Hybrid via Alpine + Livewire)

Although this document focuses on the backend context layer, the LLM assistant is consumed through a **chat UI in a Flux flyout modal**. The frontend should follow the **optimistic UI pattern** described in `docs/optimistic-ui-guide.md`, similar to how collaborators and calendar feeds work:

- `resources/views/components/workspace/collaborators-popover.blade.php`
- `resources/views/components/workspace/calendar-feeds-popover.blade.php`

### 6.1. High-level frontend pattern

- **Livewire** provides server-side methods:
  - e.g. `processAssistantMessage(string $message, ?int $threadId)` which internally uses `ProcessAssistantMessageAction` and the LLM pipeline.
- **Alpine.js** owns the chat UI state in the browser:
  - `messages` array (user + assistant messages, including metadata).
  - `input` string for the current message.
  - `sending` / `error` flags and optional `pendingTraceId`.
- **Flux UI** renders the flyout and components:
  - `flux:modal flyout` + `flux:textarea` + `flux:button` for the chat surface (see `llm-assistant-chat-ui-guidelines.md`).

Key rule: **treat Livewire as a transport** and Alpine as the source of truth for visible chat state, just like in the collaborators and calendar feeds popovers.

### 6.2. Optimistic send message flow

Use the 5‑phase optimistic pattern from `optimistic-ui-guide.md` for sending a message:

1. **Snapshot**  
   - Keep a backup of the `messages` array before changes:  
     `const messagesBackup = [...this.messages];`
2. **Optimistic UI update**  
   - Push the user message into `messages` immediately.
   - Append a **temporary assistant “thinking” bubble** (e.g. `{ id: 'temp-'+Date.now(), role: 'assistant', status: 'pending' }`).
   - Clear the input field and set `sending = true`.
3. **Call server (Livewire)**  
   - Use `$wire.call()` (or `$wire.$call()` from Alpine) to dispatch the backend action without blocking UI:  
     `const promise = $wire.call('processAssistantMessage', this.input, this.threadId);`
4. **Handle response**  
   - `await promise` and replace the temporary assistant bubble with the real assistant message payload returned from the Livewire method (including `RecommendationDisplayDto` data).
   - Keep `messages` as-is on success; set `sending = false`.
5. **Rollback on error**  
   - In `catch`, restore `this.messages = messagesBackup;`
   - Show a toast or inline error (similar to `inviteInlineError` and calendar `inlineError` patterns).
   - Set `sending = false`.

This mirrors:

- **Collaborators popover**: optimistic removal / permission change with rollback and toasts.
- **Calendar feeds popover**: hybrid pattern with inline error, `connecting` / `loadingFeeds` flags, and `$wire.$call` for backend actions.

### 6.3. Loading and streaming behavior

- While `sending` is `true`:
  - Disable the send button (`x-bind:disabled="sending || !input.trim()"`).
  - Show a “typing” indicator in the assistant bubble (e.g. spinner icon).
- When the backend finishes:
  - Replace the pending assistant bubble with the full `message` and `structured` data from `RecommendationDisplayDto`.
  - Optionally listen for browser events (`@assistant-message-created.window`) if Livewire dispatches events after the queued job completes; Alpine can then merge new messages into its local `messages` array.

### 6.4. Alignment with context layer

The context layer remains a **pure backend concern**. The frontend:

- Never constructs or sends the context JSON itself.
- Only sends:
  - `userMessage` text,
  - optional `threadId`,
  - any lightweight hints (if needed later, e.g. targeting a specific task/event/project).
- Renders assistant replies using the **display DTO** and **structured output**:
  - `message` as the main bubble.
  - `structured.ranked_tasks` / `listed_items` / `next_steps` as secondary sections.

This separation ensures:

- The **optimistic/hybrid Alpine + Livewire UX** can evolve independently of the context payload.
- The LLM context optimization work in `ContextBuilder` can change without breaking the chat UI contract.

---

## 7. Out of Scope (Future)

- **Semantic RAG:** Embedding the user message and retrieving “most relevant” tasks/events (e.g. for “schedule my essay”) — would require an embedding model and store.
- **Config-driven field sets:** Defining field sets in config files instead of code — can be added later if we want non-developers to tune.

This plan keeps the same public API (`ContextBuilder::build(...)`) and the same overall flow (intent → entity type → fetch → enforce token awareness); it only narrows **what** we put in each payload per intent.
