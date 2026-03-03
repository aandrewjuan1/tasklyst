# TaskLyst LLM Assistant — Frontend UI Implementation Plan (AI Agent Context)

This document is the **authoritative implementation plan** for the TaskLyst Hermes 3B (hermes3:3b) assistant chat UI. It is written for **AI agents and implementers**: backend-aligned data contracts, phased steps, and concrete file/component decisions. Use it as context when implementing the frontend; do not rely on the old high-level “guidelines” version.

---

## 1. Backend alignment (source of truth)

The frontend MUST consume only what the backend provides. The following are the exact contracts.

### 1.1. Entry point: processing a user message

- **Action**: `App\Actions\Llm\ProcessAssistantMessageAction::execute(User $user, string $userMessage, ?int $threadId = null): AssistantMessage`
- **Behaviour**:
  - Resolves or creates thread via `GetOrCreateAssistantThreadAction` (uses `AssistantConversationService`).
  - Appends the **user** message to the thread.
  - **Guardrail-only responses** (no LLM call): returns the **assistant** message immediately when:
    - `QueryRelevanceService::isSocialClosing()` → friendly goodbye; metadata `recommendation_snapshot.reasoning` = `'social_closing'`, `used_guardrail` = true.
    - Relevance guardrail (off-topic) → “I'm focused on helping you manage tasks…”; metadata `reasoning` = `'off_topic_query'`, `used_guardrail` = true.
    - Rate limit → “You've sent quite a few requests…”; metadata `reasoning` = `'rate_limited'`, `used_guardrail` = true.
  - **LLM path**: dispatches `App\Jobs\Llm\RunLlmInferenceJob` and returns the **user** message model (assistant reply arrives later via broadcast).
- **Implication for UI**: If the API returns an `AssistantMessage` with `role === 'assistant'`, render it and do not show a loading state. If it returns a message with `role === 'user'`, show the user bubble and a loading indicator, then wait for the broadcast for the assistant reply.

### 1.2. Assistant reply delivery (real-time)

- **Event**: `App\Events\AssistantMessageCreated` (implements `ShouldBroadcast`).
- **Channel**: `private-assistant.thread.{threadId}`. Authorisation in `routes/channels.php`: user must own the thread (`AssistantThread::forUser($user->id)->whereKey($threadId)->exists()`).
- **Broadcast payload** (use these exact keys when rendering):

```json
{
  "id": 123,
  "thread_id": 1,
  "role": "assistant",
  "content": "Full plain-text reply (RecommendationDisplayDto.message).",
  "metadata": {
    "intent": "prioritize_tasks",
    "entity_type": "task",
    "recommendation_snapshot": { ... }
  },
  "created_at": "2026-03-03T12:00:00.000000Z"
}
```

- **Echo + Reverb**: Frontend must subscribe (using Laravel Echo with Reverb as the WebSocket broadcast driver) to `private-assistant.thread.{threadId}` and listen for `AssistantMessageCreated` (or the broadcast event name Laravel assigns). When a message with `role === 'assistant'` is received, append it to the thread’s message list and stop the loading indicator.

### 1.3. Recommendation snapshot (metadata.recommendation_snapshot)

This is the payload from `RecommendationDisplayDto::toArray()`. All keys are **snake_case** in JSON.

| Key | Type | Description |
|-----|------|-------------|
| `intent` | string | One of: `schedule_task`, `schedule_event`, `schedule_project`, `prioritize_tasks`, `prioritize_events`, `prioritize_projects`, `resolve_dependency`, `adjust_task_deadline`, `adjust_event_time`, `adjust_project_timeline`, `general_query`. |
| `entity_type` | string | One of: `task`, `event`, `project`. |
| `recommended_action` | string | First paragraph / summary (student-facing). |
| `reasoning` | string | Explanation paragraph. |
| `message` | string | **Primary display**: combined reply (action + reasoning + any formatted lists). Use this for the main assistant bubble text; preserve newlines. |
| `validation_confidence` | float | 0–1, server-computed; do not use model self-reported confidence. |
| `used_fallback` | bool | True if rule-based or generic fallback was used (e.g. LLM unreachable, invalid structured output). |
| `fallback_reason` | string \| null | e.g. `health_unreachable`, `connection_exception`, `invalid_structured`. |
| `structured` | object | See below. |

**structured** (allowed keys for display; only these may be present):

| Key | When present | Shape (for UI) |
|-----|----------------|----------------|
| `ranked_tasks` | PrioritizeTasks | Array of `{ rank: number, title: string, end_datetime?: string }` |
| `ranked_events` | PrioritizeEvents | Array of `{ rank: number, title: string, start_datetime?: string, end_datetime?: string }` |
| `ranked_projects` | PrioritizeProjects | Array of `{ rank: number, name: string, end_datetime?: string }` |
| `listed_items` | GeneralQuery (list/filter) | Array of `{ title: string, priority?: string, end_datetime?: string }` |
| `next_steps` | ResolveDependency | Array of strings (ordered steps). |
| `start_datetime` | Schedule/Adjust (task/event/project) | ISO 8601 string or null. |
| `end_datetime` | Schedule/Adjust | ISO 8601 string or null. |
| `priority` | Task schedule/adjust | `low` \| `medium` \| `high` \| `urgent`. |
| `duration` | Task | Minutes (number). |
| `timezone` | Event | string. |
| `location` | Event | string. |
| `blockers` | Task / ResolveDependency | Array of strings. |

Guardrail-only messages may have minimal or no `recommendation_snapshot`; they include `used_guardrail: true` and `reasoning` (e.g. `social_closing`, `off_topic_query`, `rate_limited`). Render `content` as the main text.

### 1.4. Models and API surface (to be exposed to frontend)

- **AssistantThread**: `id`, `user_id`, `title`, `updated_at`. Relationship: `messages()` (ordered by `created_at`).
- **AssistantMessage**: `id`, `assistant_thread_id`, `role` (`'user'` \| `'assistant'`), `content`, `metadata` (JSON), `created_at` (no `updated_at`).

The frontend will need at least:

- **Get or create thread**: by `threadId` or “latest” for current user.
- **List messages**: for a given thread (e.g. for initial load and scroll).
- **Send message**: submit user text → backend runs `ProcessAssistantMessageAction`; response is the created user message (or the guardrail assistant message). No synchronous assistant reply for the LLM path.

Implement these as Livewire component methods calling the same actions, or as a dedicated controller + routes; keep a single source of truth (the actions above).

---

## 2. UI stack and shell

- **Stack**: Laravel + Livewire + Flux UI (free). Tailwind CSS v4. Layout: `resources/views/layouts/app.blade.php` (Flux header, sidebar, slot).
- **Assistant shell**: Flux **flyout** modal (see Flux docs: `flux:modal` with `flyout`). Position: right side; size equivalent to a side panel (e.g. `md:w-lg` or similar), full height.
- **Trigger**: A trigger in the app header (e.g. “Assistant” or “Need help?”) that opens the flyout. Prefer `flux:modal.trigger` + `flux:modal` with `flyout` so the chat lives in one place from any page.
- **Interaction pattern (Livewire 4 + Alpine v3)**: The chat UI should follow the same pattern as `workspace/collaborators-popover` and `workspace/calendar-feeds-popover`:
  - Wrap the interactive chat body in `wire:ignore`.
  - Use `x-data="{ ... }` to own **all rich UI state** (open/closed, placement, messages array, input text, loading/error flags).
  - Call Livewire methods **only via** `$wire.call(...)` / `$wire.$call(...)` from Alpine, following the optimistic UI rules in `docs/optimistic-ui-guide.md` (snapshot → optimistic update → server call → handle response → rollback on error).

**Concrete structure inside the modal:**

1. **Header zone** (top, fixed): Title “TaskLyst Assistant”, one-line subtitle (“Helps you prioritise and schedule tasks, events, and projects.”), optional small badge (“Hermes 3B” or “Beta”). Use `flux:heading`, `flux:text`, optionally `flux:badge`.
2. **Messages zone** (middle): Scrollable area (`flex-1 overflow-y-auto`), flex column, messages in chronological order (newest at bottom). Padding for readability.
3. **Composer zone** (bottom, fixed): Border-top, textarea + send button in a row. Use `flux:textarea` and `flux:button` (primary for send).

Use `flex flex-col h-full` on the modal content wrapper so header and composer stay fixed and the messages area scrolls.

---

## 3. Phased implementation plan

Implement in this order. Each phase is testable on its own.

### Phase 1: Shell, trigger, and empty state (COMPLETED)

**Goal**: Open a flyout from the header; show header + empty message area + composer; no backend yet.

1. **Create Livewire component** for the assistant chat (e.g. `App\Livewire\Assistant\ChatFlyout` or `AssistantChat`). The component will own: open/close state (or delegate to Flux modal), thread id, messages list, input value, loading state.
   - Inside the component view, wrap the chat flyout body in a `wire:ignore` root with `x-data="{ ... }"` so Alpine controls local UI state (open, placement, messages, input, loading) just like the collaborators and calendar-feeds popovers.
   - Flux `modal flyout` provides the shell; Alpine controls the internals (panel behaviour, message list, optimistic send), and Livewire stays responsible for server calls.
2. **Add trigger in header**: In `resources/views/layouts/app.blade.php` (or the header partial), add a “Assistant” / “Need help?” control that opens the Flux flyout containing the Livewire component.
3. **Modal layout**: Inside the flyout, render the three zones (header, messages, composer). Messages zone shows an **empty state** when there are no messages: short copy (“Ask about tasks, events, or projects”) and **suggested prompt chips** (buttons) such as:
   - “What should I focus on today?”
   - “Show my tasks with no due date.”
   - “Help me plan study time for my exam.”
   - “Which tasks can I drop if I’m overwhelmed?”
4. **Composer**: Textarea (placeholder: e.g. “Ask about your tasks, events, or projects…”) and a Send button; no submit logic yet (or submit that only appends locally for testing). Prefer 2–3 visible lines, optional max height (e.g. 3–4 lines).

**Acceptance**: Opening the flyout shows the layout; clicking a chip could set the input text or be wired later to “send”.

---

### Phase 2: Load thread and list messages (COMPLETED)

**Goal**: On open (or when thread is set), load the thread and its messages from the backend; render user and assistant bubbles.

1. **Backend**: Expose “get or create thread” and “list messages” (e.g. via Livewire component that uses `GetOrCreateAssistantThreadAction` and `AssistantThread::messages`). If no route/controller exists, implement a minimal API or keep everything inside Livewire (recommended).
2. **Component + Alpine state**: On mount or when flyout opens, call get-or-create thread (e.g. by `threadId` from URL or null for “current user’s latest”). Store `threadId` in the Livewire component and pass the initial messages into Alpine via `@js()` so `x-data` can maintain a **pure JS messages array**, similar to how `people`/`feeds` are handled in existing workspace components.
3. **Message list**: For each `AssistantMessage`, render a bubble:
   - **User**: right-aligned, stronger accent; content = `content`.
   - **Assistant**: left-aligned, softer background; content = `content` (this is already the full `message` from the backend). Preserve line breaks (e.g. `nl2br` or CSS `whitespace: pre-wrap`).
4. **Timestamps**: Optional; show `created_at` under or beside each bubble (muted, small).
5. **Scroll**: After loading or after appending a message, scroll the messages zone to the bottom.

**Acceptance**: Re-opening the flyout shows the same thread and history; new messages appear when added (e.g. via tinker or a simple test send).

---

### Phase 3: Send message and handle guardrails (COMPLETED)

**Goal**: User can send a message; backend is called; guardrail responses (social closing, off-topic, rate limit) appear immediately.

1. **Send action (optimistic pattern)**: On submit (Enter or Send button), have Alpine execute the full optimistic flow described in `docs/optimistic-ui-guide.md`:
   - **Snapshot**: Create a backup of the current `messages` array before any changes.
   - **Optimistic append**: Immediately push a local “pending” user message object into the Alpine `messages` array (with a temporary id and a `status` flag if helpful) so the UI updates instantly.
   - **Server call**: Call `ProcessAssistantMessageAction::execute($user, $input, $threadId)` via `$wire.call(...)` / `$wire.$call(...)` from Alpine. Use the current user (auth) and the resolved thread id.
   - **Handle response**:
     - If the returned message has `role === 'user'`, treat it as confirmation of the pending user message (you can update its id/status or simply rely on subsequent reloads), and keep the optimistic UI.
     - If the returned message has `role === 'assistant'` (guardrail), replace or augment the optimistic user message as appropriate and append the assistant message immediately.
   - **Rollback on error**: If the `$wire.call(...)` throws, restore the `messages` array from the snapshot and surface an error (inline text in the composer area and/or a toast), exactly as you do for collaborators/calendar feeds.
2. **Response handling**:
   - If returned message has `role === 'user'`: append that user message to the local list; clear input; **show loading indicator** (typing/processing) for the assistant; do not expect an immediate assistant message from the same response.
   - If returned message has `role === 'assistant'`: append that assistant message to the local list; **do not** show loading. This is the guardrail case (social closing, off-topic, rate limit).
3. **Guardrail styling** (optional but recommended): If `metadata.recommendation_snapshot.reasoning` is `off_topic_query`, `rate_limited`, or `metadata.used_guardrail` is true, render the bubble with a subtle different style (e.g. neutral/warning tone, or small “Info” icon) so it’s clear it’s a system-style reply.
4. **Composer rules**: Enter → send; Shift+Enter → newline. Disable Send (and optionally show loading on button) while a request is in progress. Optionally allow typing during load.

**Acceptance**: Sending “thanks” yields an immediate assistant goodbye; sending an off-topic phrase yields the “I’m focused on…” message; sending a normal question yields the user bubble + loading state.

---

### Phase 4: Real-time assistant reply (Echo)

**Goal**: When the LLM job finishes, the assistant reply appears in the UI without refresh.

1. **Subscribe to channel**: After thread is resolved, subscribe to `private-assistant.thread.{threadId}` (Laravel Echo). Ensure broadcasting auth is configured and the channel is authorised for the current user. This subscription can be wired from Alpine (e.g. in an `x-init` hook that calls a small JS helper to register the Echo listener) so that incoming messages update the same `messages` array used for optimistic UI.
2. **Listen for new message**: On the event that carries the broadcast payload (e.g. `AssistantMessageCreated` or the name configured in broadcasting), check `role === 'assistant'`. Append the payload as a new message to the thread’s message list: `id`, `thread_id`, `role`, `content`, `metadata`, `created_at`.
3. **Stop loading**: When such a message is received, remove the “typing” / loading indicator and scroll to bottom.
4. **Persistence**: Closing the flyout does not clear the thread; re-opening loads the same thread and messages (Phase 2). No “delete conversation” in this phase.

**Acceptance**: Send “What should I focus on today?”; after a short delay the assistant reply appears and loading stops, without reload.

---

### Phase 5: Render structured content and fallbacks

**Goal**: Use `metadata.recommendation_snapshot` to enrich the assistant bubble (ranked lists, listed items, next steps) and show fallback/confidence hints.

1. **Primary text**: Continue to use `content` (or `recommendation_snapshot.message`) as the main body of the assistant bubble; preserve paragraphs.
2. **Ranked lists**: If `recommendation_snapshot.structured.ranked_tasks` exists, render a numbered list below the main text (e.g. “#1 Title (end_datetime)”). Same for `ranked_events` (title + optional start/end), `ranked_projects` (name + optional end_datetime).
3. **Listed items** (GeneralQuery): If `structured.listed_items` exists, render a bullet list; each item: title, optional small badges for priority or end_datetime.
4. **Next steps** (ResolveDependency): If `structured.next_steps` exists, render a “Next steps” subtitle and a numbered list of the steps.
5. **Fallback/confidence**: If `recommendation_snapshot.used_fallback` is true, show a small label (e.g. “Rule-based suggestion” or “Fallback”) near the bubble. If `validation_confidence` < 0.5, optional subtle hint (“Check details before acting”); avoid alarming wording.
6. **Schedule/Adjust fields**: If you add “quick view” details for schedule or adjust intents, use `structured.start_datetime`, `end_datetime`, `priority`, `duration`, `blockers` per the table in §1.3; do not invent keys.

**Acceptance**: Prioritize-tasks reply shows numbered task list; general query with “tasks with no due date” shows bullet list; resolve_dependency shows next steps; fallback replies show the small fallback label.

---

### Phase 6: Composer polish and UX

**Goal**: Input behaviour, helper text, and accessibility.

1. **Textarea**: Multi-line, auto-resize up to a small max (e.g. 3–4 lines); placeholder as above.
2. **Short helper text** under the composer: e.g. “Ask about tasks, events, or projects. Example: ‘Prioritise my tasks for today.’”
3. **Keyboard**: Enter → send; Shift+Enter → newline (already in Phase 3; confirm and document).
4. **Disabled state**: Send disabled while request in progress; optional loading spinner on button.
5. **Empty state**: When there are no messages, show suggested chips; when there are messages, chips can be hidden or moved to a “Suggestions” dropdown to save space.

**Acceptance**: UX matches the behaviour described in the original guidelines (no new backend behaviour).

---

### Phase 7 (optional): Context chips and follow-up actions

**Goal**: Optional context awareness and links into the app.

1. **Context chips**: Under some assistant messages, optional small chip(s) derived from `intent` and `entity_type`, e.g. “Tasks · Prioritisation”, “Events · This week”. Do not invent backend fields; use only `intent` and `entity_type` from the snapshot.
2. **Follow-up actions**: Buttons such as “Open task list”, “Open calendar”, “View this project” that navigate to existing app routes (e.g. workspace, calendar) with optional query params. Use `intent` / `entity_type` and, if needed, IDs from `structured` (e.g. from ranked/listed items) to pre-filter where the app supports it.

**Acceptance**: Optional; can be skipped or done later without breaking the core chat.

---

## 4. Component and file checklist (for AI agent)

Use this as a quick reference when generating or modifying files.

- **Livewire component**: e.g. `app/Livewire/Assistant/ChatFlyout.php` (or `AssistantChat.php`) — state: threadId, messages, input, loading, optional error.
- **Blade view**: Corresponding view in `resources/views/livewire/assistant/` — three zones, message loop, composer, empty state with chips.
- **Trigger**: In `resources/views/layouts/app.blade.php` or `resources/views/components/.../header.blade.php` — button/link that opens the modal.
- **Flux usage**: `flux:modal.trigger`, `flux:modal` (flyout), `flux:heading`, `flux:text`, `flux:badge`, `flux:textarea`, `flux:button`, optionally `flux:callout` for guardrail messages, `flux:avatar` for assistant if desired.
- **Echo + Reverb**: Subscribe (via Laravel Echo using Reverb as the WebSocket transport) to `private-assistant.thread.{threadId}`; listen for `AssistantMessageCreated` (or configured event name); append message when `role === 'assistant'`.
- **Backend**: Use only `ProcessAssistantMessageAction`, `GetOrCreateAssistantThreadAction`, and `AssistantThread`/`AssistantMessage`; do not bypass or duplicate logic. Message metadata and `recommendation_snapshot` shape must match §1.3.

---

## 5. Intent and entity reference (backend enum values)

Use these when rendering context chips, routing, or conditional UI.

**LlmIntent (intent):**  
`schedule_task`, `schedule_event`, `schedule_project`, `prioritize_tasks`, `prioritize_events`, `prioritize_projects`, `resolve_dependency`, `adjust_task_deadline`, `adjust_event_time`, `adjust_project_timeline`, `general_query`.

**LlmEntityType (entity_type):**  
`task`, `event`, `project`.

---

## 6. Summary

- **Backend contract**: §1 defines exactly what the frontend receives (process flow, broadcast payload, `recommendation_snapshot` and `structured` keys). Do not assume extra fields.
- **Phases**: 1 → Shell and empty state. 2 → Load thread and messages. 3 → Send and guardrails. 4 → Real-time reply via Echo. 5 → Structured rendering and fallback/confidence. 6 → Composer polish. 7 → Optional context and follow-up actions.
- **Stack**: Livewire + Flux (flyout modal) + Tailwind; follow existing app layout and Flux patterns.
- **Tone**: Student-focused, warm, assistive; match backend prompts and copy (e.g. “prioritise”, “tasks, events, and projects”).

This file is the single context document for implementing the LLM assistant chat frontend; keep it in sync with backend changes (e.g. new intents or snapshot fields) and use it to drive phase-by-phase implementation by an AI agent or developer.
