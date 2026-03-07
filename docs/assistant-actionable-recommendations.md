# Assistant Actionable Recommendations – Implementation Spec

**Purpose:** Implement or verify the frontend layer for assistant **Apply / Dismiss** recommendations with **optimistic UI**. Use this document as the single source of truth for implementation phases. It is written for AI agents and developers who need to implement or refactor the chat flyout actionable UI.

**Prerequisites:**
- Read `docs/optimistic-ui-guide.md` for the core 5-phase optimistic pattern.
- Reference implementations (same pattern): `resources/views/components/workspace/comments.blade.php`, `resources/views/components/workspace/collaborators-popover.blade.php`.

**Files to modify:**

| Role | Path |
|------|------|
| Alpine (state + actions) | `resources/js/alpine/assistant-chat-flyout.js` |
| Blade (UI + wiring) | `resources/views/components/assistant/⚡chat-flyout/chat-flyout.blade.php` |

**Backend (read-only for frontend):** Livewire component `resources/views/components/assistant/⚡chat-flyout/chat-flyout.php` exposes:
- `acceptRecommendation(int $assistantMessageId)`
- `rejectRecommendation(int $assistantMessageId)`

The backend decides create vs update from the message’s stored `recommendation_snapshot`; the frontend only passes the message id.

---

## Data contracts

### Assistant message shape (Alpine)

Each assistant message in `this.messages` has this shape:

```js
{
  id: number | string,
  role: 'assistant' | 'user',
  content: string,
  created_at: string | null,
  metadata: {
    recommendation_snapshot?: {
      intent: string,              // e.g. 'schedule_task', 'create_task', 'update_task_properties'
      entity_type: string,         // 'task' | 'event' | 'project'
      used_fallback?: boolean,
      validation_confidence?: number,
      structured?: object,
      appliable_changes?: {
        entity_type: string,
        properties: object,         // e.g. { startDatetime, endDatetime, duration, priority }
      },
      followup_suggestions?: string[],
      user_action?: 'accept' | 'reject' | null,
      applied?: boolean | null,
      reasoning?: string,
    },
  }
}
```

### When the backend sets `appliable_changes`

Backend sets non-empty `appliable_changes` only when:
- Intent is one of: `schedule_task`, `adjust_task_deadline`, `create_task`, `update_task_properties`, and same for event/project.
- Entity type is **not** `multiple`.

Readonly intents (e.g. multi-entity prioritisation) have `structured` but no `appliable_changes`.

### Helper contracts (Alpine)

| Helper | Returns | Contract |
|--------|--------|----------|
| `getSnapshot(message)` | `object` | `message.metadata.recommendation_snapshot \|\| {}` |
| `getStructured(message)` | `object` | `getSnapshot(message).structured \|\| {}` |
| `isActionableIntent(message)` | `boolean` | `true` iff `snapshot.intent` is in the actionable intents list (see Phase 1). |
| `hasAppliableChanges(message)` | `boolean` | `true` iff `snapshot.appliable_changes.properties` is a non-empty object. |
| `isRecommendationApplied(message)` | `boolean` | `true` iff `snapshot.applied === true` or `snapshot.user_action` is a non-empty string. |

When applying/dismissing, **only** mutate `recommendation_snapshot.user_action` and `recommendation_snapshot.applied` in the frontend; do not change other message or snapshot fields.

---

## Implementation Phase 1: Actionability detection

**Goal:** Show the “Apply changes / Dismiss” bar only when the recommendation is actionable and not already applied or dismissed.

**Files:** `resources/js/alpine/assistant-chat-flyout.js`, `resources/views/components/assistant/⚡chat-flyout/chat-flyout.blade.php`

### 1.1 Alpine – actionable intent list

Implement `isActionableIntent(message)` so it returns `true` when `getSnapshot(message).intent` (or `message.metadata.intent`) is one of:

- `schedule_task`, `adjust_task_deadline`, `create_task`, `update_task_properties`
- `schedule_event`, `adjust_event_time`, `create_event`, `update_event_properties`
- `schedule_project`, `adjust_project_timeline`, `create_project`, `update_project_properties`
- `resolve_dependency`, `prioritize_tasks` (if you need to show bar for these; backend may not set `appliable_changes` for all)

Use a single source list (array) so the list is easy to keep in sync with the backend.

### 1.2 Alpine – appliable changes check

Implement `hasAppliableChanges(message)`:

- Read `snapshot = getSnapshot(message)` and `changes = snapshot.appliable_changes || {}`.
- Return `true` iff `changes.properties` is an object and `Object.keys(changes.properties || {}).length > 0`.

### 1.3 Alpine – already applied/dismissed

Implement `isRecommendationApplied(message)`:

- Read `snapshot = getSnapshot(message)`.
- Return `true` if `snapshot.applied === true` or if `typeof snapshot.user_action === 'string' && snapshot.user_action.length > 0`.

### 1.4 Blade – show CTA bar only when actionable and not applied

Render the block that contains “Apply changes” and “Dismiss” buttons only when **all** are true:

- `isActionableIntent(message)`
- `hasAppliableChanges(message)`
- `!isRecommendationApplied(message)`

Use a single condition in the template (e.g. `x-if` or `x-show`) so the logic is in one place.

**Acceptance:** The Apply/Dismiss bar is visible only for messages that have an actionable intent, non-empty appliable changes, and have not yet been accepted or rejected.

---

## Implementation Phase 2: Optimistic apply / dismiss (Alpine)

**Goal:** Implement `acceptRecommendation(message)` and `rejectRecommendation(message)` following the 5-phase optimistic pattern from `docs/optimistic-ui-guide.md`. Same pattern as in `comments.blade.php` and `collaborators-popover.blade.php`.

**Files:** `resources/js/alpine/assistant-chat-flyout.js`

**Required Alpine state:**

- `pendingRecommendationIds`: `Set` (or equivalent) of message ids currently in flight. Used to disable both buttons and prevent double submit.
- `errorMessage`: string shown to the user on failure (clear on new apply/dismiss attempt).

### 2.1 Exact sequence for both methods

Use this order for **both** `acceptRecommendation(message)` and `rejectRecommendation(message)`:

1. **Guard**
   - If `!message || !message.id` → return.
   - If `pendingRecommendationIds.has(message.id)` → return.

2. **Snapshot**
   - `backupMessages = cloneMessages()` (or equivalent shallow clone of `this.messages`). Do **not** mutate any state before this.

3. **try**
   - Find index: `idx = findMessageIndexById(message.id)`. If `idx === -1` return.
   - Clone the message’s `metadata` and `recommendation_snapshot` (do not mutate the original message object in the array).
   - Set snapshot fields:
     - **Accept:** `snapshot.user_action = 'accept'`, `snapshot.applied = true`.
     - **Reject:** `snapshot.user_action = 'reject'`, `snapshot.applied = false`.
   - Write updated snapshot back into a new metadata object and assign: `this.messages[idx] = { ...current, metadata: { ...meta, recommendation_snapshot: snapshot } }`.
   - Add `message.id` to `pendingRecommendationIds`.
   - Set `this.errorMessage = ''`.
   - **Call server:** `await $wire.$call('acceptRecommendation', message.id)` or `await $wire.$call('rejectRecommendation', message.id)`.
   - Remove `message.id` from `pendingRecommendationIds`.

4. **catch**
   - Restore: `this.messages = backupMessages`.
   - Remove `message.id` from `pendingRecommendationIds`.
   - Set `this.errorMessage` from the error (see Phase 3).

Always wrap the Livewire call in `try/catch`; never let errors fail silently.

### 2.2 Clone helper

Ensure a `cloneMessages()` (or equivalent) exists that returns a shallow copy of `this.messages` suitable for full restore on rollback (so that restoring `this.messages = backupMessages` brings back the pre-optimistic state).

**Acceptance:** Clicking Apply or Dismiss updates the UI immediately (chip or bar reflects applied/dismissed); on server error, the message list and bar state roll back and an error message is shown. Buttons are disabled while the request is in flight.

---

## Implementation Phase 3: Error handling (Alpine)

**Goal:** On any thrown error from `$wire.$call`, rollback is already done in Phase 2 catch; set a user-visible `errorMessage` by status code and optional payload.

**Files:** `resources/js/alpine/assistant-chat-flyout.js`

**Rules (inside the catch block):**

| Condition | Set `errorMessage` to |
|-----------|------------------------|
| `error.status === 422` | Validation error message; prefer `error.data?.message \|\| error.message \|\| 'Validation failed'`. |
| `error.status === 403` | e.g. “Permission denied while applying this suggestion.” (or similar for dismiss). |
| `error.status === 404` | e.g. “The referenced item no longer exists. The suggestion could not be applied.” |
| Else | `error?.message \|\| 'Something went wrong while applying this suggestion. Please try again.'` (or equivalent for dismiss). |

Follow the same patterns as `docs/optimistic-ui-guide.md` (inspect `error.status`, `error.data`, `error.message`).

**Acceptance:** User sees a clear, non-generic message for 422/403/404 and a generic fallback otherwise; no silent failures.

---

## Implementation Phase 4: Blade – buttons and pending state

**Goal:** Wire “Apply changes” and “Dismiss” to the Alpine methods and disable both buttons while the request is in flight.

**Files:** `resources/views/components/assistant/⚡chat-flyout/chat-flyout.blade.php`

**Requirements:**

- “Apply changes” button: `@click="acceptRecommendation(message)"`, `:disabled="pendingRecommendationIds && pendingRecommendationIds.has(message.id)"`.
- “Dismiss” button: `@click="rejectRecommendation(message)"`, same `:disabled` binding.
- Use Flux button components (e.g. `flux:button`) with size `xs`; primary variant for Apply, ghost for Dismiss. Labels: `__('Apply changes')`, `__('Dismiss')`.

**Acceptance:** Both buttons trigger the correct Alpine method and are disabled when `message.id` is in `pendingRecommendationIds`.

---

## Implementation Phase 5: Blade – post-action chip (Applied / Dismissed)

**Goal:** When a recommendation has been accepted or dismissed, show a single chip instead of the Apply/Dismiss bar.

**Files:** `resources/views/components/assistant/⚡chat-flyout/chat-flyout.blade.php`

**Condition to show the chip:**

- `isActionableIntent(message) && hasAppliableChanges(message) && isRecommendationApplied(message)`

**Content:**

- If `snapshot.user_action === 'accept'`: show text equivalent to “Changes applied from this suggestion” (use `__()`).
- If `snapshot.user_action === 'reject'`: show text equivalent to “Suggestion dismissed”.

Ensure the chip block is mutually exclusive with the Apply/Dismiss bar (only one of the two is visible for a given message).

**Acceptance:** After a successful apply or dismiss, the bar is replaced by the corresponding chip; state is driven by `snapshot.user_action` and `snapshot.applied`.

---

## Invariants (contract summary)

When implementing or changing the frontend, keep these rules:

- **Single mutation path:** All server-side mutations for recommendations go through `acceptRecommendation(int)` or `rejectRecommendation(int)` on the Livewire component. The frontend does not send intent or payload; it only sends the assistant message id.
- **No auto-apply:** Do not apply or dismiss a recommendation without an explicit user action (click).
- **Snapshot is source of truth:** Whether the bar or the chip is shown, and which chip text, is determined only by `recommendation_snapshot.user_action` and `recommendation_snapshot.applied`.
- **Optimistic UI:** Update the UI immediately on click; on server error, rollback and set `errorMessage`. Backend remains authoritative.

---

## Backend context (minimal, for reference)

- The assistant stores `recommendation_snapshot` in `AssistantMessage.metadata` when the reply is created (`RunLlmInferenceJob` → `RecommendationDisplayBuilder::build()` → `AppendAssistantMessageAction`). There are no separate “events”; actionability is derived from the message data.
- `applyRecommendation($assistantMessageId, $userAction)` in the Livewire component loads the message, reads the snapshot, branches by intent (create vs schedule/update), and calls the appropriate Apply* action. On success it updates `user_action` and `applied` in the snapshot and saves the message.

This section is for context only; the frontend does not need to know backend details beyond the data contracts and the two Livewire method names.
