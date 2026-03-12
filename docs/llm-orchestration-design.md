## LLM Orchestration Design

This document describes the current end‑to‑end orchestration for TaskLyst’s LLM features: how a free‑form user message becomes a structured, validated recommendation with UI‑ready narrative and appliable changes. It summarizes the major phases, core components, and how different operation modes (prioritize, schedule, list/filter/search, create/update, general query) plug into the same pipeline.

---

## High‑level flow

At a high level, a single LLM interaction follows these phases:

1. **Intent classification** – interpret the raw user message into an `LlmIntent`, `LlmEntityType`, and `LlmOperationMode`.
2. **Context building** – fetch and shape canonical task/event/project context, plus conversation history, filters, and overlays into a JSON payload.
3. **Prompt construction & inference** – select the correct prompt template, build the system prompt, and call the LLM in strict JSON mode.
4. **Post‑processing & sanity checks** – sanitize structured output, apply deterministic fallbacks and rule‑based corrections, and bind explicit user times.
5. **Display & appliable changes** – build a `RecommendationDisplayDto` with narrative, ranked/scheduled items, and changes the app can apply.

Each phase is intentionally layered so that:

- **Classification is deterministic and cheap** (no LLM round‑trip).
- **Context is the single ground truth** (no hallucinated IDs/titles).
- **LLM is treated as a flexible planner** but not the source of truth for ranking dates/IDs.
- **Post‑processing can deterministically repair or replace** the model’s choices when needed.

---

## Phase 1: Intent classification

**Component**: `LlmIntentClassificationService`  
**Key types**: `LlmIntent`, `LlmEntityType`, `LlmOperationMode`

- **Responsibility**: map a raw user message (e.g. “From 7pm to 11pm tonight, plan my evening using those tasks”) to:
  - **Operation mode**: `Schedule`, `Prioritize`, `ListFilterSearch`, `Create`, `Update`, `ResolveDependency`, or `General`.
  - **Entity scope**: tasks, events, projects, or multiple.
  - **Concrete intent**: via `LlmIntentAliasResolver` (e.g. `ScheduleTasks`, `PrioritizeTasks`, `ListFilterSearch`, etc.).
- **Keyword‑driven detection**:
  - Uses keyword lists for entity hints (task / event / project nouns) and mode hints (schedule/prioritize/list/filter/adjust/create/update).
  - Detects **time‑window scheduling** (e.g. “from 7pm to 11pm”) and **multi‑target schedule** patterns (e.g. “schedule all of them”) to upgrade entity scope to `Multiple`.
- **Outputs**: `LlmIntentClassificationResult` containing:
  - `intent`
  - `entityType` (scope)
  - `operationMode`
  - `entityTargets` (multiple when appropriate)
  - `confidence` (simple heuristic used mainly for logging/analytics, not gating).

Classification is intentionally conservative: ambiguous requests default to **tasks + general mode**, which in turn will use the general query / list‑filter‑search pipeline.

---

## Phase 2: Context building

**Component**: `ContextBuilder`  
**Collaborators**:

- `LlmContextConstraintService`
- `CanonicalEntityContextFetcher`
- `ConversationContextBuilder`
- `ContextOverlayComposer`
- `TokenBudgetReducer`

The **context payload** is the single source of truth the LLM is allowed to reference. It includes:

- **Time & user request**:
  - `current_time`, `current_date`, `timezone`, and a humanized `current_time_human`.
  - `user_current_request` (trimmed raw message).
- **Entities**:
  - `tasks`, `events`, `projects` arrays from `CanonicalEntityContextFetcher`, filtered by constraints and entity scope.
- **Conversation**:
  - `conversation_history` (recent messages, role + truncated content).
  - `previous_list_context` when the user references “those/these/that one…”.
- **Derived overlays**:
  - Scheduling **availability** (busy windows) and optional **time window** or **focused work cap**.
  - Prioritization **requested_top_n`** when the user asks for “top N”.
- **Filtering summary**:
  - `filtering_summary.applied` + `dimensions` + per‑entity counts to support filter‑first UX and narrative.

### 2.1 Parsing constraints

**Component**: `LlmContextConstraintService`  
**Type**: `LlmContextConstraints`

Given `(userMessage, intent, entityType, now)`, the constraint service infers things like:

- **Subjects / domains** – e.g. `subjectNames` for course filters (CS 220, MATH 201).
- **Tags** – `requiredTagNames`, `excludedTagNames`, with support for quoted phrases (“tagged as "Exam"”).
- **Status / priority / complexity** – `taskStatuses`, `taskPriorities`, `taskComplexities`.
- **Domain flags** – `schoolOnly`, `healthOrHouseholdOnly`, `examRelatedOnly`.
- **Time windows** – rolling “next 3 days”, “this week”, “next 7 days”, including an option to include overdue items.

For **scheduling intents**, `ContextBuilder` narrows to a **schedule‑safe subset** of constraints (e.g. subject/tags/status/priorities/flags/time window) to avoid mis‑interpreting scheduling text as hard filters that accidentally drop everything.

### 2.2 Fetching canonical entity context

**Component**: `CanonicalEntityContextFetcher`

This service applies the constraints plus the entity scope to the database and returns:

- `tasks[]` – with canonical ids, titles, date/times, durations, statuses, priority, flags like `is_overdue`, `due_today`, `is_assessment`, and any schedule‑relevant metadata.
- `events[]` – with canonical ids, titles, start/end datetimes, recurrence, etc.
- `projects[]` – with canonical ids, names, end dates, and aggregate status signals.

The fetcher is responsible for:

- Respecting **filters and windows** (course, tag, status, due windows).
- Avoiding N+1s via appropriate relationships/eager loading.
- Ensuring **canonical date/time** fields that later stages use as the source of truth.

### 2.3 Conversation context & previous lists

**Component**: `ConversationContextBuilder`

- `buildConversationHistory(thread)`:
  - Takes the last N messages (configurable) from `AssistantThread` and returns `{ role, content }` pairs trimmed to a char limit.
  - This lets prompts reference immediate conversational context without exploding the token budget.
- `buildPreviousListContext(thread, entityScope, userMessage)`:
  - Triggers when the user **explicitly references a previous list** using phrases like “those”, “these”, “that task/event/project”, “top 1/2/3”, “from previous list”, etc.
  - Extracts the **last assistant recommendation snapshot** (`recommendation_snapshot.structured`) and builds `items_in_order`:
    - From `ranked_tasks`, `ranked_events`, `ranked_projects`, or `listed_items`.
  - Returns a small structure:
    - `entity_type`
    - `items_in_order` (position + title/name)
    - Instruction: “Use the previous list order strictly when user references prior ranking/list.”

`ContextBuilder` uses this in two ways:

- **Preserve ordering** – `applyPreviousListOrdering()` reorders `tasks/events/projects` to match the previous list when the entity scope matches.
- **Restrict to slice** – `restrictToPreviousListItems()` filters the entity arrays down to only items that appeared in the previous list when followup schedule phrasing is detected.

For followups like **“schedule those across tonight and tomorrow evening”**:

- The context slice is restricted to the previous ranked/listed items.
- For multi‑task schedule followups, if `requested_schedule_n` is not set yet, it defaults to `count(payload['tasks'])` so the LLM is expected to schedule all of them.

### 2.4 Overlays for scheduling & prioritization

**Component**: `ContextOverlayComposer`

`apply(userMessage, operationMode, payload)` dispatches to:

- **Schedule overlay**:
  - `availability[]` – a computed map of days → `busy_windows[]` aggregating tasks and events within a sliding window.
  - `user_scheduling_request` – normalized copy of the raw message.
  - `context_authority` – explicit guardrail: use only entities present in `tasks/events/projects`.
  - Optional `requested_window_start` / `requested_window_end` – parsed from natural language like:
    - “from 7pm to 11pm”, “between 2:00 and 5:30pm”.
    - “tomorrow morning/afternoon” patterns.
  - Optional `focused_work_cap_minutes` – from phrases like “don’t schedule more than 3 hours of focused work”.
- **Prioritize overlay**:
  - `requested_top_n` – from “top N” phrasing, capped to a soft upper bound (e.g. 20).

These overlays give the LLM explicit, machine‑readable **guards and constraints** that post‑processing can enforce deterministically.

### 2.5 Filtering summary

**Component**: `ContextBuilder::buildFilteringSummary()`

When any constraint dimensions are active, the payload includes:

- `filtering_summary.applied = true`
- `dimensions` – e.g. `['subject', 'required_tag', 'task_status', 'time_window']`
- `counts` – entity counts after filtering.

This powers:

- Filter‑aware system prompts (via `AbstractLlmPromptTemplate`).
- Narrative tone adjustments in `RecommendationDisplayBuilder` (“I filtered your items based on…”).

### 2.6 Token budget reduction

**Component**: `TokenBudgetReducer`

Given the assembled payload, this component:

- Computes an approximate token count by JSON length.
- **First**, trims `conversation_history` from the oldest messages until under cap.
- **Then**, if still above cap, slices `tasks/events/projects` to at most a small number (e.g. 4 each).
- Keeps `requested_schedule_n` consistent with any trimmed task slice (e.g. min of requested and remaining tasks).

This enforces a **hard upper bound** on prompt size while preserving the most relevant context and instructions.

---

## Phase 3: Prompt construction & LLM inference

**Component**: `App\Llm\PromptTemplates\*Prompt` classes (e.g. `ScheduleTasksPrompt`, `PrioritizeTasksPrompt`, `GeneralQueryPrompt`, etc.)  
**Base class**: `AbstractLlmPromptTemplate`

Given `(intent, entityType, context)`, the pipeline selects the appropriate prompt template, which returns a **system prompt string** with:

- **Hermes JSON framing**:
  - `"You are a helpful assistant that answers in JSON."`
  - `OUTPUT_FORMAT` – respond with one JSON object only, no markdown, no extra text.
- **Context discipline**:
  - `CONTEXT_AND_MISSING` – use only entities in the `Context` arrays; never invent titles/IDs or pull from conversation history.
  - `ENTITY_ID_GUARDRAIL` – never invent IDs; only include `id` when explicitly requested and only if it exists in context.
- **Persona & tone**:
  - `SHORT_PERSONA`, `ADDRESS_USER_DIRECTLY`, `ADAPTIVE_TONE_POLICY`, `TONE`, `LOW_CONFIDENCE`.
- **Filter‑first narrative**:
  - `FILTER_FIRST_NARRATIVE` – when `filtering_summary` is present, explicitly acknowledge filtering and counts.
- **Scheduling‑specific rules** (for schedule/adjust intents):
  - `NO_PAST_TIMES` – never schedule in the past relative to `current_time`.
  - `SCHEDULE_MUST_OUTPUT_TIMES` – require concrete ISO datetimes for any recommended schedule.
  - `TASK_SCHEDULE_OUTPUT_START_AND_OR_DURATION` – for task scheduling, only start/duration; do not alter due dates.
  - `RESPECT_EXPLICIT_USER_TIME` – treat explicit timestamps in the user message as hard constraints.
  - `SCHEDULE_REASONING_AND_COACH` + `SCHEDULE_JSON_FIELDS_REQUIRED` – ensure reasoning references context and JSON times align with narrative.

### Example: multi‑task scheduling

**Component**: `ScheduleTasksPrompt`

The system prompt:

- Frames the assistant as a **time‑block planner** for a short window (e.g. “From 7pm to 11pm tonight”).
- Describes required context fields:
  - `current_time`, `current_date`, `timezone`.
  - `tasks[]` (id, title, duration, end_datetime, priority, is_recurring).
  - `availability[]` with busy windows.
  - `requested_window_start`, `requested_window_end`, `focused_work_cap_minutes` when present.
- Enforces:
  - **Count rules** – schedule exactly `requested_schedule_n` tasks if present, else at least 2 tasks when >= 2 in context.
  - **Window rules** – all `scheduled_tasks[*].start_datetime` must fall within the requested window.
  - **Focused work cap** – total durations must not exceed the cap.
  - **No overlaps** with `busy_windows` or overnight scheduling unless explicitly requested.
- Output shape:
  - `entity_type`, `recommended_action`, `reasoning`, `scheduled_tasks[]`, optional `confidence`.
  - `scheduled_tasks` items must include `id`, `title`, `start_datetime`, `duration`.

Other prompt templates follow a similar pattern, but with intent‑specific schemas:

- **Prioritization** – `ranked_tasks`, `ranked_events`, `ranked_projects`, or combinations.
- **List/filter/search & general query** – `listed_items` arrays with optional date‑driven filters (“no due date”, “no set dates”, “upcoming week”).
- **Create/update/adjust** – `proposed_properties` blocks with shape‑specific fields.

The actual call to the LLM (e.g. via `LlmInferenceService`) uses:

- The selected **system prompt**.
- A **user prompt** that wraps:
  - The natural language message.
  - The JSON `Context:` block containing the context payload.

The LLM’s raw structured output is then handed to post‑processing.

---

## Phase 4: Post‑processing and deterministic guards

**Component**: `LlmPostProcessor`  
**Collaborators**:

- `StructuredOutputSanitizer`
- `ExplicitUserTimeParser`
- `DeterministicScheduleTasksService`
- `LlmInferenceService` (for one‑shot retries)
- `LlmInteractionLogger`

Given `(user, intent, entityType, context, userMessage, userPrompt, promptResult, inferenceResult, traceId)`, post‑processing:

1. **Normalizes structured output**:
   - For most intents: calls `StructuredOutputSanitizer::sanitize($rawStructured, $context, $intent, $entityType, $userMessage)`.
   - For **single‑task scheduling** (`ScheduleTask` / `AdjustTaskDeadline`):
     - Uses a specialized path `taskScheduleStructuredFromRaw()`:
       - Enforces “no tasks” guardrails (friendly message when context has no tasks).
       - Strips any `end_datetime` from task schedules (due dates are read‑only).
       - Applies `ensureSensibleStartTimeForTaskSchedule()` to avoid recommending times too far in the past (bumps to ~30 minutes from now).
2. **Retries fragile intents (multi‑task schedule) once**:
   - For `ScheduleTasks` + `entityType = Multiple`:
     - `retryScheduleTasksOnceWhenInvalid()`:
       - Computes a **target scheduled count**:
         - Uses `requested_schedule_n` when present (capped by `count(Context.tasks)`).
         - Else, if this is a “schedule those” followup with previous list context, target = `count(Context.tasks)`.
         - Else, default is at least 2 scheduled tasks when possible.
       - If the first pass returned **too few `scheduled_tasks`**, builds a **retry guidance** suffix and calls the LLM one more time with stricter instructions.
       - Sanitizes and logs this retry under a `traceId` suffix for observability.
3. **Falls back to deterministic scheduling** when LLM still fails:
   - `fallbackToDeterministicScheduleTasksWhenStillInvalid()`:
     - If context has many tasks and a valid requested window, but `scheduled_tasks` is still under the target count, delegates to `DeterministicScheduleTasksService::buildStructured($context)`.
     - This produces a fully deterministic schedule within the requested window using rule‑based selection and spacing; the LLM’s output is effectively replaced.
4. **Enforces deterministic ranking for prioritization**:
   - For all prioritize intents:
     - `ensurePrioritizeTasksUsesDeterministicRanking()`
     - `ensurePrioritizeEventsUsesDeterministicRanking()`
     - `ensurePrioritizeProjectsUsesDeterministicRanking()`
     - `ensurePrioritizeTasksAndEventsUsesDeterministicRanking()`
     - `ensurePrioritizeTasksAndProjectsUsesDeterministicRanking()`
     - `ensurePrioritizeEventsAndProjectsUsesDeterministicRanking()`
     - `ensurePrioritizeAllUsesDeterministicRanking()`
   - Each method:
     - Builds a deterministic ranking from the **context arrays** only (ignoring the model’s own ranking).
     - Honors `requested_top_n` when present by slicing the deterministic list.
     - Ensures `entity_type` is set appropriately (`task`, `event`, `project`, or combinations).
   - Deterministic ranking rules:
     - For tasks:
       - Drop completed tasks (`status = done`).
       - Sort primarily by `end_datetime` (earlier first), then by priority (`urgent`, `high`, `medium`, `low`), then alphabetically by title.
       - Emit `rank = 1..N`, `title`, and canonical `end_datetime`.
     - For events:
       - Sort by `start_datetime`, then title.
     - For projects:
       - Sort by `end_datetime`, then name.
   - Result: **LLM provides narrative & coaching**, but the **backend owns the actual ordering.**
5. **Binds explicit user times**:
   - For schedule/adjust intents (task/event/project) after sanitization:
     - `overrideStartFromExplicitUserTime()` uses `ExplicitUserTimeParser` to detect phrases like “tomorrow at 3pm” or “at 7:30 tonight”.
     - If a valid candidate time is parsed, it **overrides** `structured.start_datetime` and `proposed_properties.start_datetime` with that exact timestamp.
     - This enforces the `RESPECT_EXPLICIT_USER_TIME` contract even if the model tried to move the time for optimization reasons.

---

## Phase 5: Display, narrative, and appliable changes

**Component**: `RecommendationDisplayBuilder`

Given `(LlmInferenceResult $result, LlmIntent $intent, LlmEntityType $entityType)`, this component builds a `RecommendationDisplayDto` for the UI. It:

1. **Starts from canonical structured output**:
   - Uses `result->structured` (already sanitized and possibly deterministically adjusted) plus `result->contextFacts` (e.g. filtering summary).
2. **Computes validation confidence**:
   - Checks presence and types of required fields, date parseability, and enum validity.
   - Produces a `validationConfidence` score used for UI confidence indicators, not for gating.
3. **Refines narrative for prioritization**:
   - For `PrioritizeTasks`, `PrioritizeEvents`, `PrioritizeProjects`, and multi‑entity prioritize intents:
     - Applies `enforce*Prioritize*NarrativeConsistency()`:
       - Ensures `recommended_action` and `reasoning` are consistent with `ranked_*` lists and context facts.
       - Binds relative phrases like “tomorrow morning” or “due today” to canonical `end_datetime` / `start_datetime` and `current_time`.
       - Avoids quietly changing ordering; it only adjusts the text to align with the already‑deterministic ranking.
4. **Filter‑first tone injection**:
   - `applyFilterFirstNarrativeTone()`:
     - When `contextFacts.filtering_summary.applied = true`, prepends a brief explanation that:
       - Filters were applied according to certain dimensions (e.g. subject, tag, time window).
       - Reports how many tasks/events/projects matched.
     - This presents a **filter → rank → recommend** story to the user rather than a mysterious ranking.
5. **Sanitizes internal key names**:
   - Replaces references to internal JSON keys in the narrative with human‑friendly language (e.g. “tag”, “priority”, “start time”).
6. **Ensures schedule fields exist when narrative mentions times**:
   - For schedule/adjust intents, `ensureScheduleFromNarrativeWhenMissing()` attempts to back‑fill schedule fields when the LLM text mentions a time but JSON fields are missing.
   - This is a guardrail against mismatches where the user sees “8pm tonight” but the app has no structured time to apply.
7. **Formats ranked lists & next steps for message**:
   - `formatRankedListForMessage()` converts `ranked_*` arrays to human‑readable lines (e.g. “1. Task (due Fri 11:59 PM)”).
   - `formatNextStepsForMessage()` summarizes appliable changes when present.
   - `buildMessage()` combines:
     - `recommended_action` paragraph.
     - `reasoning` paragraph.
     - Optional listed/ ranked items sections.
8. **Builds appliable changes**:
   - Uses intent‑specific DTOs:
     - `TaskScheduleRecommendationDto`, `EventScheduleRecommendationDto`, `ProjectScheduleRecommendationDto`.
     - `TaskCreateRecommendationDto`, `EventCreateRecommendationDto`, `ProjectCreateRecommendationDto`.
     - Update DTOs for property changes.
   - These DTOs expose exactly what can be applied to the database (e.g. new schedule slots, updated priorities, created tasks).
9. **Back‑fills target identifiers for single‑entity schedules**:
   - For schedule/adjust intents, ensures `target_*` fields (ID + title/name) are in `structured` so:
     - The UI can show which item is being scheduled/adjusted.
     - Downstream logic can apply the change deterministically.

Finally, `RecommendationDisplayDto` is serialized into the assistant message, and its `structured` snapshot is stored in assistant message metadata as `recommendation_snapshot` so followup interactions can reference it via `ConversationContextBuilder`.

---

## Operation mode variants

Although the pipeline is shared, different intent families emphasize different pieces:

- **Prioritize** (`PrioritizeTasks`, `PrioritizeEvents`, `PrioritizeProjects`, multi‑entity variants, `PrioritizeAll`):
  - Strong reliance on:
    - Deterministic ranking in `LlmPostProcessor`.
    - Context constraints and tag/subject/time windows.
    - Narrative consistency and filter‑first tone.
- **Schedule / Adjust** (`ScheduleTask`, `ScheduleTasks`, `ScheduleEvent`, `ScheduleProject`, and their adjust variants, `PlanTimeBlock`):
  - Heavy use of:
    - Time‑window parsing in `ContextOverlayComposer`.
    - Availability overlays and focused‑work caps.
    - Single vs multi‑task scheduling logic, deterministic fallbacks for `ScheduleTasks`.
    - Explicit user time binding via `ExplicitUserTimeParser`.
- **List / Filter / Search + General Query** (`ListFilterSearch`, `GeneralQuery`):
  - Use `StructuredOutputSanitizer::sanitizeGeneralQuery()` to:
    - Enforce context‑only `listed_items`.
    - Apply date‑based filters for phrases like “no due date”, “no start date”, “upcoming week”.
  - `RecommendationDisplayBuilder` rewrites `recommended_action` to summarize the list (“You have N tasks matching that request…”).
- **Create / Update / Resolve Dependency**:
  - Use prompt templates that:
    - Inspect context for related tasks/events/projects.
    - Output `proposed_properties` shaped DTOs.
  - `StructuredOutputSanitizer` and `RecommendationDisplayBuilder` ensure:
    - Only properties that exist in the domain model are emitted.
    - Narrative and appliable changes remain aligned.

---

## Design goals and invariants

The current orchestration design is built around a few hard invariants:

- **Context is the only ground truth**:
  - All IDs, titles, dates, and tags must appear in `Context.*` arrays.
  - Sanitization aggressively strips any hallucinated or history‑only references.
- **Deterministic where it matters**:
  - Ranking and multi‑task scheduling can be fully backend‑owned when needed.
  - LLM is primarily responsible for **coaching narrative and lightweight planning**, not low‑level ordering.
- **Explicit user constraints are hard constraints**:
  - Time windows, focused‑work caps, “top N” requests, and explicit timestamps are treated as binding, with deterministic fallbacks rather than silently ignoring them.
- **Followups respect prior slices**:
  - Multi‑turn workflows (e.g. “top 5 school tasks → schedule those across tonight and tomorrow”) preserve the previous list slice and order using `previous_list_context`.
- **Token budget is enforced centrally**:
  - `TokenBudgetReducer` applies a consistent strategy for keeping prompts under model limits while preserving the most important information.

This layering lets you evolve prompts, swap models, or tighten backend rules without rewriting business logic at call sites. Any new LLM capability or intent should be wired into this pipeline by:

1. Extending intent classification (if needed).
2. Adding or adapting context fetch/constraint logic.
3. Creating a focused prompt template that adheres to the shared guardrails.
4. Teaching `StructuredOutputSanitizer` and `RecommendationDisplayBuilder` how to sanitize and present the new structured shape.

