## Assistant actionable recommendations – backend + optimistic UI

This document explains how TaskLyst’s assistant produces **actionable recommendations** (schedule, create, update) and how the chat flyout should apply or dismiss them using an **optimistic UI** built with Alpine.js and Livewire.

This guide builds on the general patterns in `docs/optimistic-ui-guide.md`. Read that first for the core 5‑phase optimistic pattern.

---

## 1. Backend pipeline (high level)

The assistant backend has three main phases:

- **Intent classification**
  - `ClassifyLlmIntentAction` (regex + optional LLM fallback) picks:
    - an `LlmIntent` (e.g. `schedule_task`, `update_task_properties`, `create_event`), and
    - an `LlmEntityType` (`task`, `event`, `project`, or `multiple`).
  - This is used by `ProcessAssistantMessageAction` to decide which prompt and schema to use.

- **Inference + validation**
  - `RunLlmInferenceAction`:
    - Builds the system prompt via `LlmPromptService`.
    - Builds context (tasks, events, projects, conversation history).
    - Calls `LlmInferenceService::infer()` (Prism structured call using the intent‑specific schema from `LlmSchemaFactory`).
    - Normalises and sanitises structured output with `StructuredOutputSanitizer` (drops hallucinated items, applies time guards, etc.).
    - Logs to `LlmInteractionLogger`.
  - `RecommendationDisplayBuilder` turns the `LlmInferenceResult` into a `RecommendationDisplayDto` that contains:
    - `message` – the text shown to the user.
    - `structured` – a safe subset of the structured payload for UI (ranked lists, scheduled items, etc.).
    - `validationConfidence` – backend‑computed confidence based on required fields, parseable dates, enums, etc.
    - `followupSuggestions` – suggested next prompts.
    - `appliableChanges` – **machine‑readable properties that can be applied to a concrete entity**, for actionable intents only.

- **Assistant message snapshot**
  - When the assistant replies, the Livewire component (see `resources/views/components/assistant/⚡chat-flyout/chat-flyout.php`) stores a `recommendation_snapshot` array in `AssistantMessage.metadata`.
  - A typical `recommendation_snapshot` looks like:

    ```php
    [
        'intent' => 'schedule_task',              // LlmIntent value
        'entity_type' => 'task',                  // LlmEntityType value
        'used_fallback' => false,
        'validation_confidence' => 0.86,
        'structured' => [ /* trimmed structured payload for display */ ],
        'appliable_changes' => [
            'entity_type' => 'task',
            'properties' => [
                'startDatetime' => '2026-03-06T14:00:00+00:00',
                'endDatetime' => '2026-03-06T16:00:00+00:00',
                'duration' => 120,
                'priority' => 'high',
            ],
        ],
        // Populated after the user responds:
        'user_action' => 'accept'|'reject'|null,
        'applied' => true|false|null,
        'reasoning' => 'Short explanation shown to the user',
        'followup_suggestions' => ['…', '…'],
    ]
    ```

  - **Readonly intents** (e.g. multi‑entity prioritisation) will typically have `structured` but no `appliable_changes`.
  - **Actionable intents** (schedule/adjust/create/update) will include `appliable_changes` if the DTO parsing and validation passed.

---

## 2. When a recommendation is “actionable”

Actionability is inferred from the intent and from the presence of `appliable_changes`:

- Backend:
  - `RecommendationDisplayBuilder::buildAppliableChanges()` only returns non‑empty data when:
    - The intent is one of:
      - `schedule_task`, `adjust_task_deadline`, `create_task`, `update_task_properties`
      - `schedule_event`, `adjust_event_time`, `create_event`, `update_event_properties`
      - `schedule_project`, `adjust_project_timeline`, `create_project`, `update_project_properties`
    - And the entity type is **not** `multiple`.
  - Structured payloads for these intents are parsed into DTOs (`TaskScheduleRecommendationDto`, `TaskUpdatePropertiesRecommendationDto`, etc.), which expose a safe `proposedProperties()` array.

- Frontend:
  - `assistantChatFlyout` uses:
    - `isActionableIntent(message)` – checks the intent string (e.g. `schedule_task`, `update_task_properties`, `create_task`, …).
    - `hasAppliableChanges(message)` – checks whether `recommendation_snapshot.appliable_changes.properties` is a non‑empty object.
  - Only when **both** are true, and the snapshot is not already marked as applied/dismissed, the UI shows the “Apply changes / Dismiss” call‑to‑action bar.

This ensures the assistant can produce rich, structured answers without ever auto‑mutating data: the user must always choose to accept or reject.

---

## 3. Backend apply / reject flow

The Livewire component in `resources/views/components/assistant/⚡chat-flyout/chat-flyout.php` exposes two public methods:

- `acceptRecommendation(int $assistantMessageId)`
- `rejectRecommendation(int $assistantMessageId)`

Both delegate to a private method:

```php
private function applyRecommendation(int $assistantMessageId, string $userAction): void
{
    // 1. Resolve user + thread and load AssistantMessage for this thread.
    // 2. Read $snapshot = $message->metadata['recommendation_snapshot'].
    // 3. Parse LlmIntent + LlmEntityType from snapshot.
    // 4. Branch by intent:
    //    - Create* intents → ApplyAssistant*CreateRecommendationAction
    //    - All others → find a target entity for the given entity_type
    //                    and call ApplyAssistant*RecommendationAction.
    // 5. Update snapshot['user_action'] and snapshot['applied']
    //    and save it back to $message->metadata.
}
```

Key points:

- **Create intents** (`CreateTask`, `CreateEvent`, `CreateProject`):
  - Use the dedicated `ApplyAssistant*CreateRecommendationAction` classes.
  - These call into the normal create actions (e.g. `CreateTaskAction`) with full Laravel validation, policies and activity logging.

- **Existing‑item intents** (schedule/adjust/update properties):
  - Look up a concrete entity (`Task`, `Event`, `Project`) for the current user using the entity‑specific query helpers.
  - Delegate to the appropriate Apply*RecommendationAction:
    - Task: `ApplyAssistantTaskRecommendationAction` / `ApplyAssistantTaskPropertiesRecommendationAction`
    - Event: `ApplyAssistantEventRecommendationAction`
    - Project: `ApplyAssistantProjectRecommendationAction`
  - These in turn wrap the DTOs (`*UpdatePropertiesRecommendationDto`) and call the lower‑level `Apply*PropertiesRecommendationAction`.
  - All writes are guarded by:
    - DTO parsing (invalid structures are ignored),
    - Laravel validation rules,
    - `Gate`‑based authorisation,
    - Activity logging (records what the LLM suggested and what the user chose).

If anything fails (missing entity, invalid intent/entity_type, DTO parse failure), `applyRecommendation` returns early and **no data is changed**.

On success, `applyRecommendation` updates `recommendation_snapshot.user_action` and `recommendation_snapshot.applied` and persists the metadata. The frontend reads these fields to render the “Applied / Dismissed” chips.

---

## 4. Snapshot shape for the chat flyout

Inside Alpine, each assistant message is represented as:

```js
{
  id: number|string,
  role: 'assistant'|'user',
  content: string,
  created_at: string|null,
  metadata: {
    recommendation_snapshot?: {
      intent: string,           // e.g. 'schedule_task'
      entity_type: string,      // e.g. 'task'
      used_fallback?: boolean,
      validation_confidence?: number,
      structured?: object,      // only keys whitelisted for display
      appliable_changes?: {
        entity_type: string,    // 'task' | 'event' | 'project'
        properties: object,     // e.g. { startDatetime, endDatetime, duration, priority, ... }
      },
      followup_suggestions?: string[],
      user_action?: 'accept'|'reject'|null,
      applied?: boolean|null,
      reasoning?: string,
    },
    // other metadata keys, including llm_trace_id for in‑flight requests
  }
}
```

Helpers in `assistantChatFlyout` assume:

- `getSnapshot(message)` returns `message.metadata.recommendation_snapshot || {}`.
- `getStructured(message)` returns `snapshot.structured || {}`.
- `isActionableIntent(message)` reads `snapshot.intent` or `metadata.intent`.
- `hasAppliableChanges(message)` checks for a non‑empty `snapshot.appliable_changes.properties` object.
- `isRecommendationApplied(message)` reads `snapshot.user_action` / `snapshot.applied`.

When the user interacts with “Apply changes” or “Dismiss”, we **only mutate these snapshot fields** in the frontend; everything else remains untouched.

---

## 5. Optimistic apply / dismiss pattern (Alpine + Livewire)

For actionable recommendations, the chat flyout must follow the 5‑phase pattern from `docs/optimistic-ui-guide.md`:

1. **Snapshot** (before any change)
2. **Optimistic update** (mark applied/dismissed in the UI)
3. **Call server** (`$wire.$call('acceptRecommendation', id)` / `$wire.$call('rejectRecommendation', id)`)
4. **Keep state on success**
5. **Rollback on error** (restore snapshot and show an error)

In `assistantChatFlyout`, this is implemented by two Alpine methods:

```js
async acceptRecommendation(message) { /* … */ }
async rejectRecommendation(message) { /* … */ }
```

They must:

- **Snapshot state**
  - Clone `this.messages` (shallow) so we can restore the exact message list on error.
  - Clone the message’s `recommendation_snapshot` before mutating it.
- **Optimistic update**
  - Update `recommendation_snapshot.user_action` and `recommendation_snapshot.applied`:
    - Accept: `user_action = 'accept'`, `applied = true`.
    - Reject: `user_action = 'reject'`, `applied = false`.
  - Write the updated snapshot back into `message.metadata.recommendation_snapshot`.
  - Track the in‑flight request using `pendingRecommendationIds` so buttons can be disabled.
- **Call Livewire**
  - Use `$wire.$call('acceptRecommendation', message.id)` or `$wire.$call('rejectRecommendation', message.id)` and `await` it.
  - On success:
    - Leave the optimistic state as‑is (backend will persist the same snapshot).
  - On error:
    - Restore `this.messages` from the earlier snapshot.
    - Remove the id from `pendingRecommendationIds`.
    - Set `errorMessage` using the error‑handling rules from `docs/optimistic-ui-guide.md` (inspect `error.status`, `error.data`, etc. to distinguish validation, 403, 404, etc.).

Buttons in the Blade template should call these Alpine methods and honour the pending set:

```blade
<flux:button
    type="button"
    size="xs"
    variant="primary"
    :disabled="pendingRecommendationIds && pendingRecommendationIds.has(message.id)"
    @click="acceptRecommendation(message)"
>
    <span>{{ __('Apply changes') }}</span>
</flux:button>

<flux:button
    type="button"
    size="xs"
    variant="ghost"
    :disabled="pendingRecommendationIds && pendingRecommendationIds.has(message.id)"
    @click="rejectRecommendation(message)"
>
    <span>{{ __('Dismiss') }}</span>
</flux:button>
```

This gives the user instant visual feedback while keeping the backend as the source of truth.

---

## 6. Error handling and edge cases

When applying or rejecting a recommendation, the Alpine layer should:

- **Handle validation / authorisation errors**
  - Backend may throw validation or policy exceptions.
  - In those cases, rollback to the snapshot and show a short message, for example:
    - “We couldn’t apply this suggestion. The task might have changed or you no longer have access.”

- **Handle stale context**
  - If the referenced task/event/project no longer exists, the backend will fail to find it and return an error.
  - Treat HTTP 404 specially:
    - Rollback snapshot.
    - Optionally remove any now‑invalid chips and show: “This item no longer exists.”

- **Prevent race conditions**
  - Use a `pendingRecommendationIds` `Set` to avoid multiple concurrent apply/dismiss calls on the same message.
  - Disable both buttons while the request is in flight.

All error handling should follow the patterns in `docs/optimistic-ui-guide.md`, including:

- Always snapshot before changes.
- Always use `try/catch` around `$wire.$call`.
- Prefer specific messages for known status codes (422, 403, 404) and a generic fallback otherwise.

---

## 7. Contract summary for frontend code

When working on `resources/views/components/assistant/⚡chat-flyout/chat-flyout.blade.php` and `resources/js/alpine/assistant-chat-flyout.js`, keep these contracts in mind:

- **Do not mutate server state directly** – all mutations go through:
  - `acceptRecommendation(int $assistantMessageId)`, or
  - `rejectRecommendation(int $assistantMessageId)`.
- **Never apply changes without user confirmation** – suggestions must always be accepted or dismissed explicitly.
- **Only treat a suggestion as applied/dismissed based on the snapshot**:
  - `snapshot.user_action` and `snapshot.applied` are the single source of truth for the chip state.
- **Optimistic UI is optional but encouraged**:
  - The UX should feel instant and responsive.
  - Backend remains authoritative; if something goes wrong, rollback and inform the user.

Following these rules keeps the assistant’s actionable recommendations **predictable, auditable, and safe**, while still delivering a fast, optimistic UI experience in the chat flyout.

