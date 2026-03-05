# Multi-entity scheduling (next iteration)

## Scope

Mirror the **multi-entity prioritization** implementation for **scheduling** intents: allow the user to ask for schedule suggestions across two or three entity types in one request (tasks + events, tasks + projects, events + projects, or all).

- **New intents (4):**
  - `ScheduleTasksAndEvents` — "schedule my tasks and events", "when should I do my tasks and events"
  - `ScheduleTasksAndProjects` — "schedule tasks and projects", "when to work on tasks and projects"
  - `ScheduleEventsAndProjects` — "schedule events and projects"
  - `ScheduleAll` — "schedule all my items", "when should I do everything", "schedule my tasks, events, and projects"
- **Entity type:** Use `LlmEntityType::Multiple` for all four (no new enum value).
- **Output shape:** Per-entity arrays of scheduled items (mirroring `ranked_*`), e.g. `scheduled_tasks`, `scheduled_events`, `scheduled_projects`, each item with `title`/`name`, `start_datetime`, `end_datetime`, and optionally `sessions` for tasks. Same layers as prioritization: classification, context, prompt, schema, inference, DTOs, sanitizer, display; chat flyout may need to render these sections if not already generic.

---

## 1. Enums

**app/Enums/LlmIntent.php**

- Add: `ScheduleTasksAndEvents`, `ScheduleTasksAndProjects`, `ScheduleEventsAndProjects`, `ScheduleAll` (with appropriate string values, e.g. `schedule_tasks_and_events`).
- In `isReadonly()`: schedule intents are typically **not** readonly (user may apply the schedule). Decide whether multi-entity schedule is readonly (recommendation only) or actionable; if actionable, do **not** add them to `isReadonly()`.
- In `isActionable()`: if we want "Apply" for multi-entity schedule, keep default behavior (actionable when not readonly and not GeneralQuery).

---

## 2. Config

**config/tasklyst.php** (under `context`)

- Reuse or add schedule-specific limits for multi-entity, e.g.:
  - `multi_entity_schedule_task_limit`, `multi_entity_schedule_event_limit`, `multi_entity_schedule_project_limit` for two-entity schedule.
  - `multi_entity_schedule_all_task_limit`, `multi_entity_schedule_all_event_limit`, `multi_entity_schedule_all_project_limit` for ScheduleAll (smaller per type to stay within token budget).
- Ensure availability/calendar context is built for the relevant entity types when building schedule context (tasks + events + projects as needed).

---

## 3. Intent classification

**app/Services/LlmIntentClassificationService.php**

- Under `if ($hasSchedule)` (or equivalent), add detection order:
  1. **ScheduleAll first:** phrases like "schedule all", "schedule everything", "schedule all my items", "when should I do everything", "schedule my tasks events and projects". Return `ScheduleAll`, `Multiple`.
  2. **Two-way combos:** tasks+events (task + event keywords + "both"/"and") → `ScheduleTasksAndEvents`; tasks+projects → `ScheduleTasksAndProjects`; events+projects → `ScheduleEventsAndProjects`.
- In `classify()`: for these four intents, set `entityType = LlmEntityType::Multiple`.
- **computeConfidence:** add branches for the four intents (e.g. 0.85 when both entity keyword sets present for two-entity, or "all" phrase for ScheduleAll).
- **getIntentKeywords:** add the four new intents to the schedule match arm.

**app/Actions/Llm/ClassifyLlmIntentAction.php**

- In the classification system prompt, add rules and examples:
  - When the user asks to schedule both tasks and events (e.g. "schedule my tasks and events", "when should I do my tasks and events"), use `schedule_tasks_and_events`, entity_type `multiple`.
  - Same for tasks+projects, events+projects, and "schedule all" / "schedule everything" → `schedule_all`, entity_type `multiple`.
- Add one example per new intent.

---

## 4. Context builder

**app/Services/Llm/ContextBuilder.php**

- For `LlmEntityType::Multiple` and schedule intents, add a **match on intent** (same pattern as prioritization):
  - `ScheduleTasksAndEvents` → `buildScheduleTasksAndEventsContext(...)` (new)
  - `ScheduleTasksAndProjects` → `buildScheduleTasksAndProjectsContext(...)` (new)
  - `ScheduleEventsAndProjects` → `buildScheduleEventsAndProjectsContext(...)` (new)
  - `ScheduleAll` → `buildScheduleAllContext(...)` (new)
  - (existing prioritization cases remain unchanged)
- **New private methods:** Each builds task/event/project context with the appropriate limits and **availability** (busy windows) so the LLM can propose conflict-free times. Reuse existing `buildTaskContext`, `buildEventContext`, `buildProjectContext` with limit overrides where applicable; ensure availability/calendar data is included for the entities in scope.
- Config keys: use the new `multi_entity_schedule_*` and `multi_entity_schedule_all_*` limits.

---

## 5. Prompts and schema

**New prompt classes** (same pattern as prioritization prompts):

- **ScheduleTasksAndEventsPrompt:** System prompt instructs the model to suggest times for both tasks and events; output `recommended_action`, `reasoning`, `scheduled_tasks` (array of items with title, start_datetime, end_datetime, optionally sessions), `scheduled_events` (title, start_datetime, end_datetime). Only use context "tasks" and "events"; empty array when none in context.
- **ScheduleTasksAndProjectsPrompt:** Output `scheduled_tasks`, `scheduled_projects` (name, start_datetime, end_datetime).
- **ScheduleEventsAndProjectsPrompt:** Output `scheduled_events`, `scheduled_projects`.
- **ScheduleAllPrompt:** Output `scheduled_tasks`, `scheduled_events`, `scheduled_projects`; use only context "tasks", "events", "projects"; at least one list non-empty when user has data.

All extend `AbstractLlmPromptTemplate` and use `outputAndGuardrails` appropriately (likely `true` for schedule to enforce JSON/datetime).

**app/Services/LlmPromptService.php**

- Register the four new intents with their prompt classes.

**app/Services/Llm/LlmSchemaFactory.php**

- In `schemaForIntent()`, add cases for the four intents mapping to new schema methods.
- **scheduleTasksAndEventsSchema():** ObjectSchema with `entity_type`, `recommended_action`, `reasoning`, `confidence`, `scheduled_tasks` (array of object with title, start_datetime, end_datetime, optional sessions), `scheduled_events` (array of object with title, start_datetime, end_datetime). Required: `entity_type`, `recommended_action`, `reasoning`.
- **scheduleTasksAndProjectsSchema():** Same shape with `scheduled_tasks` and `scheduled_projects` (project items use `name`).
- **scheduleEventsAndProjectsSchema():** `scheduled_events`, `scheduled_projects`.
- **scheduleAllSchema():** `scheduled_tasks`, `scheduled_events`, `scheduled_projects` plus standard fields.

---

## 6. Inference: validation, normalization, fallback

**app/Services/LlmInferenceService.php**

- **normalizeStructuredForIntent:** Add cases for the four schedule intents (normalize `entity_type` to e.g. `task,event`, `task,project`, `event,project`, `all`).
- **isValidStructured:** For each new intent, require `recommended_action`, `reasoning`; require the corresponding scheduled arrays to be present (as arrays; can be empty). At least one of the intent’s scheduled arrays must be present. Validate datetime format if present (optional but recommended).
- **passesIntentSpecificValidation:** Add cases using new DTOs: `ScheduleTasksAndEventsDto::fromStructured`, etc., returning non-null or core text + arrays present.
- **buildFallbackStructured:** For each multi-entity schedule intent, return a fallback with empty scheduled arrays and a message that scheduling is unavailable (or a minimal rule-based suggestion for one entity type if desired). No LLM call in fallback.

**Optional DTOs** (recommended for consistency):

- **ScheduleTasksAndEventsDto**, **ScheduleTasksAndProjectsDto**, **ScheduleEventsAndProjectsDto**, **ScheduleAllDto** — each with `scheduledTasks`/`scheduledEvents`/`scheduledProjects` (as appropriate), `reasoning`, and `fromStructured(array): ?self` validating structure and required fields.

---

## 7. Sanitizer

**app/Services/Llm/StructuredOutputSanitizer.php**

- In `sanitize()`, add cases for the four schedule intents:
  - `ScheduleTasksAndEvents` → `sanitizeScheduledTasksAndEvents($structured, $context)`
  - `ScheduleTasksAndProjects` → `sanitizeScheduledTasksAndProjects($structured, $context)`
  - `ScheduleEventsAndProjects` → `sanitizeScheduledEventsAndProjects($structured, $context)`
  - `ScheduleAll` → `sanitizeScheduledAll($structured, $context)`
- **Implementation:** Filter each `scheduled_*` array so only items whose title/name exists in the corresponding context array remain (reuse `titlesFromContextItems`, `namesFromContextProjects`). Optionally validate/rewrite datetimes to be within allowed range. Return merged structured with updated scheduled arrays.

---

## 8. Display builder

**app/Services/Llm/RecommendationDisplayBuilder.php**

- **computeValidationConfidence:** Extend multi-entity block (or add a schedule-specific block) for the four schedule intents when `entityType === Multiple`: require `recommended_action`, `reasoning`, at least one relevant scheduled array present; optionally reward valid datetimes and non-empty lists.
- **formatRankedListForMessage** (or add **formatScheduledListForMessage**): For schedule intents, format `scheduled_tasks`, `scheduled_events`, `scheduled_projects` into readable lines (e.g. "Task: X — Fri 2–4pm", "Event: Y — Sat 10am"). Support Tasks + Events, Tasks + Projects, Events + Projects, and All sections (mirror prioritization section layout).
- **defaultFollowupSuggestionsForIntent:** Add cases for the four intents with 1–2 follow-up strings each (e.g. "Adjust the time for my top task", "Schedule another event").
- **sanitizeStructuredForDisplay:** Allow `scheduled_tasks`, `scheduled_events`, `scheduled_projects` in the allowed keys so they are passed to the frontend.

---

## 9. Chat flyout / Apply actions

- **Display:** If the flyout currently only shows single-entity schedule results (one task or one event), extend it to render `scheduled_tasks`, `scheduled_events`, `scheduled_projects` when present (similar to how `ranked_tasks`, `ranked_events`, `ranked_projects` are shown).
- **Apply:** If "Apply" is supported for ScheduleTask/ScheduleEvent/ScheduleProject, decide whether multi-entity schedule supports "Apply" for each list (e.g. apply task times, event times, project timelines). If yes, extend **ApplyTaskScheduleRecommendationAction** (and event/project equivalents) to handle the new intents and apply each scheduled item; if no, keep multi-entity schedule as recommendation-only (readonly).

---

## 10. Tests

- **Classification:** Unit tests — e.g. "schedule both my tasks and events" → ScheduleTasksAndEvents, Multiple; "schedule all my items" → ScheduleAll, Multiple.
- **Context:** Feature tests — build context for ScheduleTasksAndEvents, ScheduleTasksAndProjects, ScheduleEventsAndProjects, ScheduleAll with Multiple; assert payload has correct keys (tasks+events, tasks+projects, events+projects, or all three) and availability when applicable.
- **Display:** Unit test — build for ScheduleAll + Multiple with sample scheduled_tasks, scheduled_events, scheduled_projects; assert message contains all three sections and follow-up suggestions.
- **Sanitizer:** Unit tests — sanitize ScheduleTasksAndEvents and ScheduleAll with hallucinated titles; assert only context-backed items remain.
- **Schema:** Existing schema tests that iterate over `LlmIntent::cases()` will cover new intents once enums are added.

---

## 11. Consistency checklist

- Each new intent has a dedicated prompt class, schema, context builder branch, validation and fallback in LlmInferenceService, sanitizer branch, and display handling.
- Entity type remains `Multiple` for all four intents.
- Config limits keep total context within token budget; availability context is included for schedule so the LLM can avoid conflicts.
- Classification order: ScheduleAll first (broad phrases), then two-way combos (tasks+events, tasks+projects, events+projects).
- Reuse helpers: `titlesFromContextItems`, `namesFromContextProjects`, and similar filtering/formatting patterns from prioritization.

---

## 12. File list summary

| Layer          | Files to add or modify |
|----------------|------------------------|
| Enums          | app/Enums/LlmIntent.php (4 new cases, optional isReadonly) |
| Config         | config/tasklyst.php (multi_entity_schedule_*, multi_entity_schedule_all_*) |
| Classification | LlmIntentClassificationService.php, ClassifyLlmIntentAction.php |
| Context        | ContextBuilder.php (schedule branch in Multiple, 4 new build*Context methods) |
| Prompts        | New: ScheduleTasksAndEventsPrompt, ScheduleTasksAndProjectsPrompt, ScheduleEventsAndProjectsPrompt, ScheduleAllPrompt; LlmPromptService.php |
| Schema         | LlmSchemaFactory.php (4 new schema methods + cases) |
| Inference      | LlmInferenceService.php (normalize, isValid, passesIntentSpecific, buildFallback) |
| DTOs           | ScheduleTasksAndEventsDto, ScheduleTasksAndProjectsDto, ScheduleEventsAndProjectsDto, ScheduleAllDto |
| Sanitizer      | StructuredOutputSanitizer.php (4 cases + 4 methods) |
| Display        | RecommendationDisplayBuilder.php (validation, formatScheduledListForMessage or extend format, followups, sanitizeStructuredForDisplay) |
| Chat flyout    | If needed: render scheduled_tasks, scheduled_events, scheduled_projects; optional Apply handling |
| Tests          | Classification, context, display, sanitizer (and Apply if applicable) |

---

## Notes for implementation

- **Availability:** Single-entity schedule prompts already reference "availability" and "busy_windows". Ensure multi-entity context builder includes the same (or aggregated) availability for the relevant entity types so the model can propose non-overlapping times.
- **Readonly vs actionable:** Decide up front whether multi-entity schedule is recommendation-only (no Apply) or if we support applying each scheduled task/event/project. That drives `isReadonly()` and any Apply action changes.
- **Output shape:** Keeping `scheduled_tasks`, `scheduled_events`, `scheduled_projects` parallel to `ranked_*` makes sanitization and UI consistent with prioritization and simplifies the plan.
