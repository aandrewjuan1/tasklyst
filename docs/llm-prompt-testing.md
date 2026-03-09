## LLM Prompt Testing Guide

This file lists example user prompts and the **expected behaviour** of the LLM + backend so you can quickly spot bugs or regressions.

### Conventions and Guarantees

- **Structured output is source of truth**  
  All display and apply logic uses the structured JSON from inference (`structured` + `appliable_changes`) as the canonical reference. `raw_structured_from_llm` is only a fallback when fields are missing.

- **User prompt is the top authority**  
  - When the user clearly specifies **exact date/time/duration** (e.g. “tomorrow at 3pm for 90 minutes”), scheduling prompts must use **that exact slot** unless it is literally impossible.  
  - When the user says “top / most urgent / most important”, prioritization and scheduling prompts use the **shared top-task/event/project criteria** consistently across intents.
  - For multiturn, the **latest user message** plus the **previous list context** (ordered tasks/events/projects) are the primary guides.

- **Apply button behaviour (Accept)**  
  - Uses `recommendation_snapshot` as single reference.  
  - If `appliable_changes.properties` is present and valid, those properties are applied.  
  - If properties are empty but structured schedule fields exist, the apply actions derive properties from structured.  
  - A task/event/project is only marked `applied=true` when **actual DB changes** occurred.

- **Overdue tasks**  
  - You are allowed to set `startDatetime` **after an overdue `end_datetime`** (working on something late).  
  - Validation does *not* block this; only obvious invalid ranges (end before start for non-overdue) are rejected.

---

## 1. Single-Intent Prompts

### 1.1 Prioritize tasks

**Prompt:**  
> Prioritize my tasks for this week and show me the top 5 I should do first.

**Expected behaviour:**  
- Intent: `prioritize_tasks` / entity: `task`.  
- ContextBuilder limits tasks to this week; `requested_top_n = 5`.  
- Structured output contains `ranked_tasks` with **exactly 5 items when at least 5 tasks are available in context** (otherwise all available tasks, up to 5), ordered by shared top-task criteria (overdue → due_today → deadlines, priority, complexity, duration, realistic energy).  
- UI message shows a short recommendation + numbered list. **No Apply bar**, because this is readonly.

**Known bugs we fixed for this scenario:**  
- **Bug 1 – LLM returned fewer than requested top N even when more tasks were available:**  
  - Symptom: `raw_structured_from_llm.ranked_tasks` sometimes had only 2 items even though Context contained more tasks and the user requested “top 5”.  
  - Root cause: Prompt text only said “return at most that many items”, which allowed the model to stop early.  
  - Fix: Updated `PrioritizeTasksPrompt` (and sibling prioritize prompts) + `ContextBuilder` so that when `requested_top_n` is present and there are at least N items in context, the model is instructed to **always return exactly N ranked items** (only fewer when fewer items exist).
- **Bug 2 – Sanitizer dropped one ranked item due to title mismatch (emoji vs Greek text):**  
  - Symptom: `raw_structured_from_llm.ranked_tasks` had 5 items but `recommendation_snapshot.structured.ranked_tasks` only had 4; the 5th task title differed slightly (emoji vs `ἀρετή`).  
  - Root cause: `StructuredOutputSanitizer` used a very strict fuzzy-title similarity threshold (85), so small differences caused valid context tasks to be filtered out.  
  - Fix: Relaxed the similarity threshold to 70 so minor differences in punctuation/emoji still match the real DB title; now all 5 ranked tasks survive sanitization when present in context.

---

### 1.2 Schedule a single task (explicit time)

**Prompt:**  
> Schedule my most important task that is not overdue for tomorrow at 2pm for 90 minutes.

**Expected behaviour:**  
- Intent: `schedule_task` / entity: `task`.  
- Model picks the “most important” *non-overdue* task using top-task criteria.  
- Because the user specified time/duration:
  - `structured.start_datetime` is exactly “tomorrow at 14:00” in ISO 8601.  
  - `structured.duration` is `90`.  
  - `proposed_properties.start_datetime` and `duration` mirror those values.  
- `appliable_changes` has:
  - `entity_type: "task"`  
  - `properties.startDatetime` = same ISO string  
  - `properties.duration` = 90  
- UI shows:
  - “Proposed schedule” with **When = tomorrow 2pm** and **Duration = 90**.  
  - Apply/Dismiss bar visible (has id + properties).
- After clicking **Accept**:
  - `tasks.start_datetime` updated to the requested time.  
  - `tasks.duration` set to 90.  
  - `tasks.end_datetime` **unchanged** (task due date is never altered by scheduling).  
  - Assistant message snapshot updated with `user_action: "accept", applied: true` and “Changes applied from this suggestion” chip.

**Known bugs we fixed / hardened for this scenario:**  
- **Bug 1 – Narrative contradicted explicit user time:**  
  - Symptom: User said “tomorrow at 2pm for 90 minutes”, `structured.start_datetime` was correct, but `recommended_action` / `reasoning` talked about “tonight” or “later today at 2pm”, effectively changing the day in the text.  
  - Fix: Strengthened the shared `RESPECT_EXPLICIT_USER_TIME` guardrail in `AbstractLlmPromptTemplate` and ensured all schedule/adjust prompts use it via `outputAndGuardrailsForScheduling()`. The guardrail now requires BOTH JSON fields and narrative text to describe the same explicit slot and forbids silently moving the time.  
- **Bug 2 – LLM claimed it “chose” a time the user explicitly provided:**  
  - Symptom: For prompts like “Schedule … for tomorrow at 2pm for 90 minutes”, reasoning contained phrases like “I chose 14:00 because…”, even though that time came directly from the user.  
  - Fix: Updated `ScheduleTaskPrompt`, `ScheduleEventPrompt`, and `ScheduleProjectPrompt` so that when the user supplies an explicit date/time (via `user_scheduling_request` in Context), the model is instructed to explain **why the user’s chosen slot works** (e.g. “Since you’re planning to work at 2pm tomorrow and your afternoon is free…”) instead of saying it “chose” that time. The “I chose…” phrasing is now only appropriate when the user did not specify a time and the model is actually picking a slot.

**Known bugs we are hardening against in this scenario:**  
- **Narrative contradicts explicit time:**  
  - Symptom: User says “tomorrow at 2pm”, `structured.start_datetime` is correct, but `recommended_action` / `reasoning` talk about “tonight” or “later today at 2pm”.  
  - Fix: Strengthened the shared `RESPECT_EXPLICIT_USER_TIME` guardrail in `AbstractLlmPromptTemplate` and ensured all schedule/adjust prompts for tasks, events, and projects include it via `outputAndGuardrailsForScheduling()`. The guardrail now explicitly requires that **both JSON fields and narrative text describe the same explicit slot**, and forbids silently moving or rephrasing the time to a different day.

---

### 1.3 Reschedule an event

**Prompt:**  
> Reschedule my next exam to Friday at 9am and avoid overlapping any other events.

**Expected behaviour:**  
- Intent: `adjust_event_time` / entity: `event`.  
- ContextBuilder selects the “next exam” event via title + time.  
- Structured output:
  - `entity_type: "event"`  
  - `id` = exact event id from context  
  - `title` = exact event title from context  
  - `start_datetime` = Friday 09:00 (exact)  
  - `end_datetime` chosen to preserve event duration or reasonable default  
  - `proposed_properties.start_datetime`/`end_datetime` mirror those.  
- `appliable_changes.entity_type = "event"`, and `properties` contain `startDatetime` (and `endDatetime` if needed).  
- Accept updates the `events.start_datetime`/`end_datetime` row and records an audit entry.

---

## 2. General Query / Listing Prompts

### 2.1 List tasks with no due date and low priority

**Prompt:**  
> List all my tasks that have no due date and low priority.

**Expected behaviour:**  
- Intent: `general_query` / entity: `task`.  
- ContextBuilder filters tasks to those with `end_datetime = null` and `priority = "low"`.  
- Structured output:
  - `entity_type: "task"`  
  - `listed_items`: array with only matching tasks (exact titles, optional priority/end_datetime).  
- `recommended_action` summarises the list (“You have N low-priority tasks without due dates”).  
- UI shows the narrative + bullets. **No Apply bar** (readonly listing).

---

## 3. Multiturn & Previous-List Scenarios

### 3.1 Prioritize then schedule top 1

**Step 1 – Prompt:**  
> Prioritize my tasks for this week and show me the top 5.

**Expected behaviour:**  
- As in 1.1, `ranked_tasks` with top 5; UI shows list.
- ContextBuilder stores this list into `previous_list_context` and reorders `tasks` arrays accordingly.

**Step 2 – Prompt:**  
> In the previous list, schedule the top 1 for today at 7pm for 60 minutes.

**Expected behaviour:**  
- Intent: `schedule_task` / entity: `task`.  
- ContextBuilder:
  - Detects reference to previous list.  
  - Restricts tasks to that list and treats index 0 as **top 1**.  
- Prompt instructions ensure:
  - The same top-task criteria are used for “top 1”.  
  - `previous_list_context.items_in_order[0]` maps to the first task in `tasks`.  
- Structured output:
  - Targets exactly the **rank #1** task from previous message.  
  - `start_datetime` = today at 19:00.  
  - `duration` = 60.  
  - `id` and `title` from that task.  
- Accept updates only that one task’s `start_datetime`/`duration`.

---

### 3.2 Cross-entity “what should I do first?”

**Step 1 – Prompt:**  
> Across my tasks, events, and projects, what should I do first?

**Expected behaviour:**  
- Intent: `prioritize_all` / entity: `multiple`.  
- Structured output includes `ranked_tasks`, `ranked_events`, and `ranked_projects` with a combined, consistent notion of “top/urgent”.

**Step 2 – Prompt:**  
> From that list, schedule the top task for tomorrow at 3pm for 90 minutes.

**Expected behaviour:**  
- Intent: `schedule_task` / entity: `task`.  
- ContextBuilder:
  - Uses `previous_list_context` from the PrioritizeAll response.  
  - Picks the **top task** from the previous combined list (not an event or project).  
- Structured output:
  - `id`/`title` exactly for that task.  
  - `start_datetime` = tomorrow 15:00; `duration` = 90 (user-specified).  
- Accept updates that task only.

---

## 4. Edge Cases to Watch For

Use these to spot bugs or misalignment quickly.

### 4.1 Explicit time ignored or changed

**Smell:**  
- User says “tomorrow at 3pm”, but structured output uses **today** or a different time (e.g. 7pm) *without* explaining why.

**Expectation:**  
- With the `RESPECT_EXPLICIT_USER_TIME` guardrail:
  - This should no longer happen.  
  - If a different time is absolutely necessary, `recommended_action`/`reasoning` must explicitly say why and ask for a new choice, not silently change it.

### 4.2 Wrong entity scheduled after PrioritizeAll

**Smell:**  
- After “Across my tasks, events, and projects, what should I do first?”, a follow-up “schedule the top task” accidentally targets an **event** or **project**.

**Expectation:**  
- For “top task”, scheduler must:
  - Use `previous_list_context` and the **task** subset only.  
  - Pick the correct task that was #1 in the prior mixed list.

### 4.3 Apply shows “Changes applied” but DB didn’t change

**Smell:**  
- Snapshot has `user_action: "accept", applied: true`, but the corresponding task/event/project row is unchanged.

**Expectation:**  
- The `applied` flag is now tied to whether `Apply*PropertiesRecommendationAction` actually wrote **at least one changed field**.  
- If no changes were made (e.g. validation blocked or properties empty), `applied` should be false and the UI should say **“No changes were applied”** instead.

---

## 5. How to Use This File

- When you see behaviour that feels wrong:
  - Find the closest scenario in this file.  
  - Compare the **actual structured output and DB state** with the **Expected behaviour** here.  
  - If they differ, you likely have either:
    - A prompt issue (model not following rules), or  
    - A backend issue (ContextBuilder / DisplayBuilder / apply actions).

- When adding new LLM features:
  - Add a new example prompt + expected behaviour here.  
  - Add or update a corresponding test in `tests/Feature/Llm*` so the behaviour is locked in.

