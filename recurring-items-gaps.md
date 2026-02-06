# Recurring Tasks & Events — Implementation Gaps

This document outlines what is missing to achieve proper recurring items behavior in a task management system. Split by backend (specific) and frontend (logic/flow only).

---

## Backend — What's Missing

### 1. Models

| Model | Status | Purpose |
|-------|--------|---------|
| `TaskInstance` | **Missing** | Represents a single occurrence of a recurring task. Links `recurring_task_id` → `task_instances`, `task_id` (nullable). Has `instance_date`, `status`, `completed_at`. |
| `EventInstance` | **Missing** | Represents a single occurrence of a recurring event. Links `recurring_event_id` → `event_instances`, `event_id` (nullable). Has `instance_date`, `status`, `cancelled`, `completed_at`. |
| `TaskException` | **Missing** | Represents an exception for a recurring task (skip or replace). Links `recurring_task_id`, `exception_date`, `is_deleted`, `replacement_instance_id` (nullable). |
| `EventException` | **Missing** | Same as TaskException but for events. Links `recurring_event_id`, `exception_date`, etc. |

**Relationships to add:**
- `RecurringTask` → `hasMany(TaskInstance::class)`
- `RecurringTask` → `hasMany(TaskException::class)`
- `RecurringEvent` → `hasMany(EventInstance::class)`
- `RecurringEvent` → `hasMany(EventException::class)`
- `TaskInstance` → `belongsTo(RecurringTask::class)`, `belongsTo(Task::class)`
- `EventInstance` → `belongsTo(RecurringEvent::class)`, `belongsTo(Event::class)`

---

### 2. Services

| Service | Method(s) | Purpose |
|---------|-----------|---------|
| `TaskService` | `completeRecurringOccurrence(Task $task, CarbonInterface $date)` | Create or update a `TaskInstance` for the given date, set `status = done` and `completed_at = now()`. Do not touch the parent `Task`. |
| `TaskService` | `getOccurrencesForDateRange(RecurringTask $recurringTask, CarbonInterface $start, CarbonInterface $end)` | Expand recurrence pattern into concrete dates within the range. Respect `start_datetime`, `end_datetime`, `TaskException` (skip deleted, apply replacements). Return array of dates. |
| `EventService` | `completeRecurringOccurrence(Event $event, CarbonInterface $date)` | Same as task: create/update `EventInstance` for that date, mark completed. |
| `EventService` | `getOccurrencesForDateRange(RecurringEvent $recurringEvent, CarbonInterface $start, CarbonInterface $end)` | Same as task: expand recurrence into dates, respect exceptions. |
| `TaskService` | `createTaskException(RecurringTask $recurringTask, CarbonInterface $date, bool $isDeleted, ?TaskInstance $replacement = null)` | Create a `TaskException` to skip or replace an occurrence. |
| `EventService` | `createEventException(RecurringEvent $recurringEvent, CarbonInterface $date, bool $isDeleted, ?EventInstance $replacement = null)` | Same for events. |

---

### 3. Recurrence Expansion Logic

**New class:** `App\Services\RecurrenceExpander` (or similar)

- **Method:** `expand(RecurringTask|RecurringEvent $recurring, CarbonInterface $start, CarbonInterface $end): array<CarbonInterface>`
- **Logic:**
  - **Daily:** Every day from `start_datetime` to `end_datetime` (or `end` param), stepping by `interval` days.
  - **Weekly:** Every `interval` weeks, on `days_of_week`, from `start_datetime` to `end_datetime`/`end`.
  - **Monthly:** Same day-of-month every `interval` months (e.g. 15th of each month). Handle months with fewer days (e.g. Feb 30 → Feb 28/29).
  - **Yearly:** Same date every `interval` years.
- **Apply exceptions:** For each candidate date, check `TaskException`/`EventException`. If `is_deleted`, exclude. If `replacement_instance_id` set, use that instance’s date instead.
- **Respect bounds:** `start_datetime` and `end_datetime` on the recurring record must be honored.

---

### 4. Scopes & Queries

**Task model — `scopeRelevantForDate`:**

- Currently only handles `TaskRecurrenceType::Daily`.
- **Change:** For recurring tasks, use `RecurrenceExpander` (or equivalent) to check if `$date` is in the expanded occurrence list. Include the task if:
  - It has a `recurringTask` and `$date` is in the expanded dates, **and**
  - Either no `TaskInstance` for that date exists, or the instance is not `done` (for incomplete tasks).
- For `scopeIncomplete` + recurring: a recurring task is "incomplete for date X" if there is no completed `TaskInstance` for X, or if the instance exists and is not done.

**Event model — `scopeActiveForDate`:**

- Same idea: expand recurrence for `$date`, respect `EventException`, and optionally `EventInstance` status (scheduled/cancelled/completed).

**Alternative approach:** Keep the parent task/event as the "template" and use a separate query or computed property to join/filter by instances. The core requirement is: **weekly/monthly/yearly recurring items must appear on the correct days in the workspace.**

---

### 5. Completion Flow for Recurring Items

**When user marks a recurring task as "done" for a specific date:**

1. Resolve the `date` (e.g. today or the selected workspace date).
2. Call `TaskService::completeRecurringOccurrence($task, $date)`.
3. Create or update `TaskInstance` for `recurring_task_id` + `instance_date`:
   - Set `status = done`, `completed_at = now()`, `task_id = $task->id`.
4. **Do not** set `completed_at` on the parent `Task`. The parent stays as the template.
5. The task disappears from the workspace for that date (because it is now "complete for that occurrence").

**For events:** Same logic with `EventInstance` and `EventService::completeRecurringOccurrence`.

---

### 6. API / Livewire Actions

**HandlesWorkspaceItems (or equivalent):**

- `updateTaskProperty($taskId, 'status', 'done')` — when the task is recurring:
  - Determine the effective date (e.g. `$this->selectedDate`).
  - Call `completeRecurringOccurrence` instead of updating the parent task’s status.
  - For non-recurring tasks, keep current behavior (update task status/completed_at).
- `updateEventProperty($eventId, 'status', 'completed')` — same for recurring events.

**New optional actions:**

- `skipRecurringOccurrence($taskId|$eventId, $date)` — create a `TaskException`/`EventException` with `is_deleted = true`.
- `rescheduleRecurringOccurrence($taskId|$eventId, $fromDate, $toDate)` — create exception + replacement instance.

---

### 7. Dependencies

- **Carbon** (already used): For date math and recurrence expansion.
- **No new packages required** if you implement recurrence expansion in PHP. Optional: `recurr/recurr` or similar for complex RRULE-style patterns if you add iCal support later.

---

### 8. Database

- `task_instances`, `event_instances`, `task_exceptions`, `event_exceptions` tables exist but are unused.
- Ensure migrations have correct indexes for:
  - `(recurring_task_id, instance_date)` on `task_instances`
  - `(recurring_event_id, instance_date)` on `event_instances`
  - `(recurring_task_id, exception_date)` on `task_exceptions` (unique already exists)
  - `(recurring_event_id, exception_date)` on `event_exceptions`

---

## Frontend — Logic & Flow (No Code)

### 1. Completion Behavior

- **Non-recurring:** Marking done updates the task; it disappears from Today (via `scopeIncomplete`).
- **Recurring:** Marking done should complete **only today’s occurrence** (or the selected date’s occurrence). The item disappears from that date but reappears on the next occurrence.
- **Flow:** User clicks done → frontend sends completion (with effective date or relies on backend using selected date) → backend creates/updates `TaskInstance`/`EventInstance` → item is removed from Today for that date.

---

### 2. Date Context

- The workspace has a `selectedDate`. When completing a recurring item, the backend must know which date’s occurrence is being completed (e.g. pass `selectedDate` or use `today` if viewing Today).
- Ensure the completion action includes the selected date when the task/event is recurring.

---

### 3. Recurrence Display

- **Recurring badge:** Already shown; keep it.
- **Per-occurrence state:** When an occurrence is completed, the list item for that date should disappear. No extra UI needed if the backend filters correctly.
- **Optional:** Show "Completed today" or similar for recurring items that are done for the selected date — only if you add a "Completed" section.

---

### 4. Exceptions (Skip / Reschedule)

- **Skip occurrence:** User chooses "Skip this occurrence" → frontend sends `skipRecurringOccurrence(taskId, date)` → backend creates exception with `is_deleted = true` → that occurrence no longer appears.
- **Reschedule:** User chooses "Move to [date]" → frontend sends `rescheduleRecurringOccurrence(taskId, fromDate, toDate)` → backend creates exception + replacement instance → occurrence appears on new date instead.
- **Flow:** These actions can live in a context menu or dropdown on the list item when it’s a recurring task/event.

---

### 5. "This Occurrence" vs "All Future"

- **Edit this occurrence only:** Opens a form that creates a `TaskInstance`/`EventInstance` with overrides (e.g. different title, time) and links it. The template stays unchanged.
- **Edit all future:** Updates the `RecurringTask`/`RecurringEvent` (and possibly the parent `Task`/`Event`). Existing instances may need rules (e.g. don’t change past instances).
- **Delete this occurrence:** Same as "Skip" — create exception with `is_deleted = true`.
- **Delete all future:** Update `end_datetime` on the recurring record to the day before, or add a "series end" concept.
- **Flow:** When user edits/deletes a recurring item, show a choice: "This occurrence" | "This and future" | "All" (if applicable). Each option maps to a different backend action.

---

### 6. Loading & Filtering

- Backend returns tasks/events for the selected date. Frontend does not need to expand recurrence; it only displays what the backend returns.
- When `selectedDate` changes, refetch tasks/events. Recurring items appear or disappear based on backend scopes and instance completion.

---

### 7. Empty States & Feedback

- After completing a recurring occurrence, show brief feedback (e.g. "Done. See you next [day/date].").
- If the user tries to complete an already-completed occurrence (e.g. by navigating back), backend should idempotently handle it; frontend can show "Already completed for this date" if needed.

---

## Summary Checklist

| Area | Item |
|------|------|
| Backend | `TaskInstance`, `EventInstance` models + relationships |
| Backend | `TaskException`, `EventException` models + relationships |
| Backend | `RecurrenceExpander` (or equivalent) for weekly/monthly/yearly |
| Backend | `TaskService::completeRecurringOccurrence`, `getOccurrencesForDateRange` |
| Backend | `EventService::completeRecurringOccurrence`, `getOccurrencesForDateRange` |
| Backend | Exception creation methods |
| Backend | Update `scopeRelevantForDate` / `scopeActiveForDate` to use expansion + instances |
| Backend | Route completion of recurring items to instance creation, not parent update |
| Frontend | Pass selected date when completing recurring items |
| Frontend | Optional: "Skip" / "Reschedule" actions and flows |
| Frontend | Optional: "This occurrence" vs "All future" choice when editing/deleting |
