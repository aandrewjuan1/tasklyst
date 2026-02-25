# LLM Task, Event, and Project Management Integration Workflow
## Implementation Plan — TaskLyst (Ollama + PrismPHP + Hermes 3 3B)

This document is an **implementation plan**. The LLM assistant is not yet built; the following describes the intended design, conventions, and steps to implement it using **Ollama**, **PrismPHP** (`prism-php/prism`), and **Hermes 3 3B** for task prioritization and predictive scheduling.

---

## Tech Stack (Planned)

| Layer | Choice | Purpose |
|-------|--------|--------|
| **LLM runtime** | [Ollama](https://ollama.ai) | Local inference, no API keys, data stays on-device |
| **Model** | Hermes 3 3B (e.g. `hermes3:3b` in Ollama) | Small, fast, instruction-following; good for structured JSON and task/ scheduling reasoning |
| **PHP integration** | [PrismPHP](https://prismphp.com) (`prism-php/prism` ^0.99) | Fluent Laravel-style API, structured output via schema, provider-agnostic |
| **Backend** | Laravel 12 | Validation, auth, persistence, queues |
| **UI** | Livewire + Flux | Chat surface and accept/modify/reject flows |

**Why this stack:** Hermes 3 3B fits local/low-latency use; Prism’s `ObjectSchema` + `asStructured()` gives predictable JSON for recommendations; Laravel handles validation and persistence so the LLM is used only for suggestions, not authority.

### Model limitations and what to expect (Hermes 3 3B)

- **Good for:** Narrow, structured tasks (prioritization, scheduling suggestions, "what to do next"), short schema-bound output (e.g. ranked list + brief reasoning), and low-context prompts. The rest of this doc is designed around these strengths.
- **Do not expect:** Deep multi-step reasoning, perfect consistency across runs, or reliable handling of subtle or rare edge cases. Output may occasionally be odd or inconsistent; the system should not depend on the model being right every time.
- **Therefore:** User-in-the-loop (accept/modify/reject), validation and business rules in the backend, rule-based fallbacks when the LLM fails or is unsure, and logging of accept/modify/reject are all essential—and are already specified in the phases below.

### Scope and known limitations
- **Ollama concurrency:** Ollama serves **one request at a time** by default. If multiple users (or multiple tabs) use the LLM assistant simultaneously, requests queue and the last user may wait a long time (e.g. 3–5s per request × N users). For the thesis, **acknowledge this as a known limitation** in scope. In implementation: set a **reasonable timeout**; show a "Please wait…" or optional "Queue position" message; consider **Laravel queues** with a dedicated worker for LLM jobs so the Livewire component does not block the request. Document in the thesis that single-user or low-concurrency use is assumed unless you scale Ollama or switch to a multi-worker setup.

---

## Core Workflow Overview

The user communicates with the system through a chatbot interface (conversational UI), so all requests and responses flow through that chat surface.

```
User Natural Language Input (via Chatbot UI)
    ↓
Intent Classification + Entity Detection (Fast - No LLM)
    ↓
Route to Appropriate Handler (Task/Event/Project)
    ↓
Context Retrieval + Preparation (Minimal, Surgical, Structured)
    ↓
LLM Inference (Hermes 3 3B)
    ↓
Structured Output (JSON with Reasoning)
    ↓
Chatbot Response to User (Explainable Recommendations + Actions)
    ↓
User Action (Accept/Modify/Reject)
    ↓
Backend Execution (Validation → Database Update)
    ↓
Feedback Loop & Logging
```

---

## Backend vs Frontend: How This Document Is Organized

This document is split into **backend** and **frontend** so you can see at a glance what runs on the server vs what the user sees.

| | Scope | In this document |
|---|--------|-------------------|
| **Backend** | Intent classification, context preparation, system prompting, LLM inference, backend execution (validation, DB writes), logging, fallbacks, architecture | Data Model Alignment, Backend Architecture Alignment, **Phase 1** (Intent), **Phase 2** (Context), **Phase 3** (Prompts), **Phase 4** (Inference), **Phase 7** (Backend Execution), **Phase 8** (Logging), plus Fallback Strategy, Design Principles, Implementation Checklist (backend items), Common Pitfalls |
| **Frontend** | Chat UI, displaying recommendations, Accept/Modify/Reject, modify form, streaming, chat session state | **Phase 5** (Structured Output Display) and **Phase 6** (User Validation & Action). All frontend plan content is collected in **[Frontend (Implementation Plan)](#frontend-implementation-plan)** at the end of this document. |

**Backend-only reading path (for AI agents / implementers):** Read in order: [Backend quick reference](#backend-quick-reference-for-ai--implementers) → [Data Model Alignment](#data-model-alignment-tasks-events-projects) → [Backend Architecture Alignment](#backend-architecture-alignment) → [Phase 1](#phase-1-intent-classification) → [Phase 2](#phase-2-context-preparation) (or [Context and prompts summary](#context-and-prompts-summary-agent-reference) for a short version) → [Phase 3](#phase-3-system-prompting) → [Phase 4](#phase-4-llm-inference) → [Phase 7](#phase-7-backend-execution) → [Phase 8](#phase-8-feedback-loop--logging) → [Fallback Strategy](#fallback-strategy) → [Implementation Checklist](#implementation-checklist) (Backend section) → [Common Pitfalls](#common-pitfalls-to-avoid). Skip Phase 5–6 and [Frontend (Implementation Plan)](#frontend-implementation-plan) when working on backend only.

In the body of the doc, Phase 5 and Phase 6 are summarized with a pointer to the full frontend section below.

---

## Backend quick reference (for AI / implementers)

Use this as the single source of backend constraints and decisions. Details live in the linked sections.

- **Entity ID:** Do **not** include `entity_id` in the LLM output schema. Hermes 3 3B can hallucinate IDs. The entity is known from Phase 2 context; resolve it server-side when applying the recommendation. See [Response Structure](#response-structure).
- **Confidence:** Model-reported confidence is uncalibrated. Prefer **validation-based** confidence (required fields present? dates parse?) or document in UI as self-assessment. See [Confidence scores from 3B models (caveat)](#confidence-scores-from-3b-models-caveat).
- **Token budget:** System prompt ~300–400 tokens; available context for user/context payload ~800–1200 tokens (not 1000–1500 raw). Cap Phase 2 context accordingly; verify with `$response->usage->promptTokens`. See [Token budget: system prompt included](#token-budget-system-prompt-included).
- **PrismException / fallback:** On invalid JSON, timeout, or unreachable Ollama, run rule-based prioritization/scheduling; never expose raw errors. Centralize with e.g. `if (!$this->isValidRecommendation($dto)) return $this->fallbackPrioritization(...)`. See [Deterministic Fallback Layer (In-Depth)](#deterministic-fallback-layer-in-depth).
- **Rule-based fallback rules:** Earlier due date → higher rank; overdue → highest; higher complexity → schedule earlier; schedule fallback = next available slot. Keep in e.g. `RuleBasedPrioritizationService` (testable). See [Deterministic Fallback Layer (In-Depth)](#deterministic-fallback-layer-in-depth).
- **Intent:** Regex/keywords as fast path; when confidence &lt; threshold or no match, optional second-pass LLM classification (small Prism call). See [Intent classification: regex fast path + optional LLM fallback](#intent-classification-regex-fast-path--optional-llm-fallback).
- **Readonly vs actionable:** `prioritize_events` and `prioritize_projects` have no DB write on Accept; `prioritize_tasks` and all schedule_* / adjust_* intents are actionable (can write priority or timing on Accept). See [Readonly vs actionable intents](#readonly-vs-actionable-intents).
- **Config:** LLM model, timeout, max_tokens in `config/tasklyst.php`; use `config()`, not `env()`. See [Conventions to follow](#conventions-to-follow).
- **DTOs:** Map `$response->structured` to recommendation DTOs (e.g. `TaskScheduleRecommendationDto::fromStructured()`); validate before use. See [Backend Architecture Alignment](#backend-architecture-alignment) and [Laravel conventions (implementation)](#laravel-conventions-implementation).
- **Apply only after user action:** No DB updates from LLM output alone; apply only when user Accepts or confirms Modify. See [Separate AI From Authority (In-Depth)](#9-separate-ai-from-authority-in-depth).
- **Audit:** When applying recommendations, record intent, entity_type, **entity_id from context** (not from LLM), user action (accept/modify/reject), and optionally reasoning in `activity_logs`. See [Laravel conventions (implementation)](#laravel-conventions-implementation).
- **Recurring (Phase 1 minimum):** Include `is_recurring: bool` in context; system prompt must say do not recommend times that conflict with recurring instances. See [Context Compression and ContextBuilder (In-Depth)](#context-compression-and-contextbuilder-in-depth).
- **Ollama:** One request at a time by default; document as known limitation; use timeout, "Please wait", and consider Laravel queues + dedicated worker. See [Scope and known limitations](#scope-and-known-limitations).

---

## Data Model Alignment (Tasks, Events, Projects)

This LLM workflow is grounded in TaskLyst's current schema and Eloquent models. The tables and fields below match the existing database (`tasks`, `events`, `projects`, `recurring_tasks`, `recurring_events`, `activity_logs`). Backend placement (Services, Actions, DTOs) aligns with existing patterns—e.g. `TaskService`, `EventService`, `ProjectService`, `UpdateTaskPropertyAction`, `UpdateEventPropertyAction`, `UpdateProjectPropertyAction`, `ActivityLogRecorder`, and `App\DataTransferObjects\*`—so the LLM flow plugs in without bypassing them.

- **Tasks (`tasks` table)**:
  - `id`, `user_id`, `title`, `description`
  - `status` (`App\Enums\TaskStatus`), `priority` (`App\Enums\TaskPriority`), `complexity`
  - `duration` (integer minutes), `start_datetime`, `end_datetime`
  - `project_id`, `event_id`, `calendar_feed_id`
  - `source_type`, `source_id`, `source_url`, `completed_at`, `deleted_at`

- **Events (`events` table)**:
  - `id`, `user_id`, `title`, `description`
  - `start_datetime`, `end_datetime`, `all_day` (boolean), `status` (`App\Enums\EventStatus`)
  - Soft deletes via `deleted_at`

- **Projects (`projects` table)**:
  - `id`, `user_id`, `name`, `description`
  - `start_datetime`, `end_datetime`, `deleted_at`

- **Recurrence & Instances**:
  - `recurring_tasks`, `task_instances`, `task_exceptions`
  - `recurring_events`, `event_instances`, `event_exceptions`

- **Logging & Analytics**:
  - `activity_logs` captures `action` and structured `payload` JSON for LLM‑assisted decisions, including reasoning, confidence, and timing, giving you a durable audit trail.

All JSON examples and field names below are written so they can map cleanly onto these tables and model properties.

---

## Backend Architecture Alignment

TaskLyst’s backend follows **Services**, **Actions**, **DTOs**, **Support/Validation**, and **Livewire Concerns**. The LLM flow should plug into this stack rather than bypass it.

### Existing patterns (summary)

| Layer | Location | Role |
|-------|----------|------|
| **Services** | `App\Services\*Service` | Domain logic, `DB::transaction`, orchestration (e.g. `TaskService::createTask(User, array)`). Injected into Actions. |
| **Actions** | `App\Actions\{Domain}\*Action` | Single-purpose `execute(...)`; inject Services (and optionally `ActivityLogRecorder`, validation). Return models or result DTOs. |
| **DTOs** | `App\DataTransferObjects\{Domain}\*Dto` | Readonly input/output objects. Input DTOs: `fromValidated(array)` and `toServiceAttributes()` for Services. Result DTOs: e.g. `UpdateTaskPropertyResult::success()`. |
| **Support/Validation** | `App\Support\Validation\*PayloadValidation` | `rules()` and `defaults()` for Livewire/form validation; no domain logic. |
| **Livewire Concerns** | `App\Livewire\Concerns\Handles*` | Traits on Livewire components: inject Actions, validate with `*PayloadValidation`, build DTO from validated input, call `$action->execute(...)`. |

### Where each LLM phase should live

| Phase | Suggested placement | Notes |
|-------|---------------------|--------|
| **1. Intent classification** | `App\Services\LlmIntentClassificationService` or `App\Actions\Llm\ClassifyLlmIntentAction` | Stateless: input string → `{ intent, entity_type, confidence }`. No DB; regex/keywords. |
| **2. Context preparation** | `App\Services\LlmContextService` (e.g. `buildContextForIntent(User, intent, entity_type, ?entityId)`) | Uses existing `TaskService`/`EventService`/`ProjectService` (or Eloquent) to load minimal data; returns structured array for the prompt. |
| **3. System prompting** | Same service or `App\Services\LlmPromptService` | Returns system prompt string (or message array) per intent/entity. Can be methods like `getSystemPromptForTaskScheduling()`. |
| **4. LLM inference** | `App\Services\LlmInferenceService` (or `PrismOllamaService`) | Wraps Prism: `using(Provider::Ollama, model)`, `withSchema()`, `withSystemPrompt()`, `withPrompt()`, `asStructured()`. Returns raw `$response->structured` array; catches `PrismException`. |
| **5–6. Response + user validation** | **DTOs** for recommendations: e.g. `App\DataTransferObjects\Llm\TaskScheduleRecommendationDto` | `fromStructured(array $response->structured)`: validate keys/types/enums, throw or return nullable. Livewire shows recommendation and Accept/Modify/Reject. |
| **7. Backend execution** | **Existing or new Actions** | “Apply recommendation” = call existing `UpdateTaskPropertyAction` / `UpdateEventPropertyAction` / `UpdateProjectPropertyAction` with validated values from the recommendation DTO (or from user-modified form). Optionally an `ApplyTaskScheduleRecommendationAction` that takes `TaskScheduleRecommendationDto` and calls `TaskService` / `UpdateTaskPropertyAction` under the hood. |
| **8. Logging / audit** | Existing `ActivityLogRecorder` + `activity_logs` | From within the Action or Service that applies the recommendation: record that the change came from an LLM suggestion (e.g. payload with `reasoning`, `confidence`, `intent`). |

### Conventions to follow

- **Config**: Put LLM model, timeout, max_tokens in `config/tasklyst.php` and use `config()` in Services/Actions.
- **Validation**: After Prism returns, validate `$response->structured` into a DTO (e.g. `TaskScheduleRecommendationDto::fromStructured($data)`). Use Laravel validation or a small `App\Support\Validation\LlmStructuredResponseValidation` (or rules inside the DTO) so invalid shapes are handled in one place.
- **Authorization**: In the Action or Livewire concern that triggers “apply recommendation”, authorize the user against the task/event/project (same policies as today).
- **Traits**: Add a Livewire concern (e.g. `HandlesLlmAssistant`) that injects the LLM Services/Actions, holds chat state, calls intent → context → inference, maps response to recommendation DTOs, and calls the apply Action when the user accepts (or passes modified values to the same Action). Reuse existing Concerns (HandlesTasks, HandlesEvents, HandlesProjects) for any existing update flows if the user chooses “Modify”.
- **Queued inference**: If you run the LLM call in a job, the job should call the same `LlmInferenceService`; on completion it can broadcast or persist the result so the Livewire component can show it without blocking the request.

This keeps the LLM behind the same Service/Action/DTO/Validation boundaries as the rest of the app and makes testing and rollout easier.

---

## Phase 1: Intent Classification

### Purpose
Determine what the user is trying to do **without calling the LLM**. This is the gatekeeper that prevents unnecessary AI inference.

### What Happens
- Input: User's natural language request
- Processing: Lightweight regex pattern matching + keyword detection for both intent and entity type
- Output: Intent type + entity type + confidence score

### Entity Detection
Before intent classification, detect which entity type the user is referring to:

- **Tasks**: "task", "todo", "work item", "action item"
- **Events**: "event", "meeting", "appointment", "calendar", "schedule"
- **Projects**: "project", "initiative", "milestone", "deliverable"

### Intent Types

#### Scheduling Intents
1. **schedule_task** - User wants to time a specific task
   - Keywords: "finish", "by", "deadline", "schedule", "when" + task keywords
   - Example: "Schedule my dashboard task by Friday"

2. **schedule_event** - User wants to schedule a calendar event
   - Keywords: "schedule", "book", "set up", "plan" + event keywords
   - Example: "Schedule a team meeting for next Tuesday"

3. **schedule_project** - User wants to plan a project timeline
   - Keywords: "plan", "timeline", "schedule", "start" + project keywords
   - Example: "Schedule the website redesign project"

#### Prioritization Intents
4. **prioritize_tasks** - User wants to rank/order tasks
   - Keywords: "priority", "important", "urgent", "rank", "order" + task keywords
   - Example: "What tasks should I focus on today?"

5. **prioritize_events** - User wants to prioritize calendar events
   - Keywords: "priority", "important", "urgent", "rank" + event keywords
   - Example: "Which events are most important this week?"

6. **prioritize_projects** - User wants to prioritize projects
   - Keywords: "priority", "important", "urgent", "rank" + project keywords
   - Example: "What projects should I prioritize?"

#### Dependency Resolution
7. **resolve_dependency** - User wants to manage blocking across entities
   - Keywords: "blocked", "waiting", "depends", "after"
   - Works across tasks, events, and projects
   - Example: "I'm blocked on the API integration task"

#### Adjustment Intents
8. **adjust_task_deadline** - User wants to change task timing
   - Keywords: "extend", "move", "delay", "push", "earlier" + task keywords
   - Example: "Can we push the dashboard task deadline to next week?"

9. **adjust_event_time** - User wants to reschedule an event
   - Keywords: "move", "reschedule", "change time", "shift" + event keywords
   - Example: "Can we move the team meeting to Thursday?"

10. **adjust_project_timeline** - User wants to adjust project dates
    - Keywords: "extend", "move", "delay", "push", "timeline" + project keywords
    - Example: "Can we extend the website project timeline?"

#### General Query
11. **general_query** - Doesn't match other categories
    - Fallback for unclear requests

### Readonly vs actionable intents
Events (and projects) do not have a "priority" field in the schema; "prioritize_events" and "prioritize_projects" produce a **display-only** ranked list in chat, with **no database write** on "Accept". The plan and UI must distinguish:

| Intent type | Readonly (display only) | Actionable (has DB write on Accept) |
|-------------|-------------------------|-------------------------------------|
| **prioritize_events** | Yes — show ranked list only | No |
| **prioritize_projects** | Yes — show ranked list only | No |
| **prioritize_tasks** | No | Yes — write task priority on Accept (use TaskPriority enum) |
| **schedule_task**, **adjust_task_deadline**, **schedule_event**, **adjust_event_time**, **schedule_project**, **adjust_project_timeline** | No | Yes |

- **Code and UI:** For readonly intents, do **not** show an "Accept" button that implies applying a change; show the recommendation (e.g. "Here's your suggested order") and optionally "Done" or "Ask something else". For actionable intents, show Accept / Modify / Reject and call the apply Action on Accept.

### Why This Step Matters
- ✅ **Speed**: Instant decision (<10ms) vs. waiting for LLM
- ✅ **Cost**: No token usage for simple operations
- ✅ **Reliability**: Deterministic, no hallucination risk
- ✅ **User Experience**: Fast initial feedback

### Output Structure
```json
{
  "intent": "schedule_task",
  "entity_type": "task",
  "confidence": 0.9
}
```

### Intent classification: regex fast path + optional LLM fallback
- **Fast path:** The keyword/regex classifier above is the primary path—no LLM, &lt;10ms, deterministic. Filipino students (and others) will code-switch (e.g. Tagalog/English), abbreviate, or phrase in ways regex may miss (e.g. "Ano gagawin ko bukas?" for prioritization).
- **Fallback:** When **confidence &lt; threshold** or **no match found**, add an optional **second-pass LLM classification**: one small Prism call with (user message, short system prompt, small output schema). Output: `{ intent, entity_type }`. Token cost is minimal; you already have the infrastructure. Keep regex as the default; use LLM only when the fast path is uncertain.

### Success Criteria
- Classification accuracy &gt;90% on test cases (regex + optional LLM fallback)
- Entity type detection accuracy &gt;85%
- Confidence scores meaningful for routing (low confidence → LLM fallback or general_query)
- Fallback to `general_query` or LLM second-pass when uncertain

---

## Phase 2: Context Preparation

### Purpose
Gather **only the necessary data** to help Hermes 3 make an informed decision. More context = slower + more tokens + higher hallucination risk.

### What Happens
1. **Retrieve**: query the database for relevant information tailored to the detected intent.
2. **Structure**: transform that raw data into a small, predictable, machine-readable payload (typically JSON) using an intent + entity-specific schema.
3. **Inject**: include that structured context payload in the LLM request (alongside the system prompt and the user’s chat message).

This ensures the LLM sees consistent fields (instead of ad-hoc text dumps), which improves parsing reliability and reduces hallucinations.

### Context Compression and ContextBuilder (In-Depth)

**Why it matters:** Hermes 3 (3B) has limited reasoning capacity and context window. If you send **60 tasks**, **6 months of history**, or **full analytics**, quality degrades: slower, noisier, and less reliable JSON. Small models perform better with **curated, minimal context**.

**What NOT to send:**

- All tasks in the backlog (e.g. 60+)
- Long history (e.g. 6 months of completed items)
- Full analytics or raw dumps
- Every field on every entity (strip to what the intent needs)

**What TO send:**

- **Upcoming** tasks/events (e.g. due in next 7–14 days)
- **Overdue** items (always relevant for prioritization)
- **High-priority / high-complexity** items when relevant
- A **strict cap** per entity type (e.g. 10–12 tasks, 5 projects with up to 10 tasks each)

**Implementation pattern: ContextBuilder**

Use a dedicated builder (e.g. on `LlmContextService`) that, per intent and entity type, **filters**, **sorts**, and **limits** before building the payload. Never pass a raw query result to the LLM.

Example shape (conceptual; adapt to your `LlmContextService`):

```php
// Conceptual: build a minimal prioritization context for tasks
public function buildPrioritizationContext(User $user): array
{
    $tasks = Task::query()
        ->forUser($user->id)
        ->whereNull('completed_at')           // pending only
        ->orderBy('end_datetime')             // earlier due date first
        ->limit(12)                           // hard cap
        ->get()
        ->map(fn (Task $t) => [
            'id' => $t->id,
            'title' => $t->title,
            'end_datetime' => $t->end_datetime?->toIso8601String(),
            'priority' => $t->priority?->value,
            'complexity' => $t->complexity?->value,
        ]);

    return [
        'current_time' => now()->toIso8601String(),
        'tasks' => $tasks,
    ];
}
```

**Rules to apply in the builder:**

- **Prioritization:** pending only; sort by due date (and optionally priority); take 10–12; include overdue at top.
- **Scheduling:** only tasks/events relevant to the requested window; exclude completed/cancelled; cap counts.
- **No long history:** only upcoming + overdue + minimal “recent” if needed for pattern (e.g. last 5 similar tasks), not months of data.

- **Recurring (Phase 1 minimum):** Include **`is_recurring: bool`** (and optionally `recurring_rule` or instance info) in the context payload for each task/event. Recurring tasks appear in a student's list from day one; if the LLM ignores recurrence, its scheduling recommendations can conflict with existing recurring instances. In the **system prompt**, instruct the model **not to recommend times that conflict with recurring instances**. Full recurrence management can remain Phase 3.

This **context compression** keeps the prompt small, improves reliability of structured output, and aligns with the token budgets already defined in this phase.

### Token budget: system prompt included
The plan often cites ~1000–1500 tokens for context, but the **system prompt** (role, steps, constraints, schema description) consumes **~300–400 tokens**. With Hermes 3 3B `num_ctx` 4096 and ~500 for the model reply, **available context for user/context payload is ~800–1200 tokens**. In each intent's budget table, account for "system prompt: ~300–400" and cap the context payload so total prompt stays within that. Verify during development with `$response->usage->promptTokens`.

### Context and prompts summary (agent reference)

Short reference for implementers and AI agents. Full detail: [Context Structure by Intent and Entity Type](#context-structure-by-intent-and-entity-type), [Context Filtering Rules](#context-filtering-rules), [Phase 3: System Prompting](#phase-3-system-prompting).

| Intent / area | Context cap (payload) | System prompt |
|---------------|------------------------|---------------|
| Tasks (schedule, prioritize, adjust) | 5–12 tasks; ~700–1200 tokens | Intent-specific; ~300–400 tokens total. Keep short for 3B. |
| Events (schedule, prioritize, adjust) | 5–10 events; ~800–1200 tokens | Intent-specific; ~300–400 tokens total. |
| Projects (schedule, prioritize, adjust) | 3–5 projects, 5–10 tasks each; ~800–1100 tokens | Intent-specific; ~300–400 tokens total. |
| resolve_dependency | 3–5 entities; ~800 tokens | Cross-entity. |

**Rules:** Filter pending/upcoming only; sort by due date; include `is_recurring` for tasks/events; no 60+ tasks or 6 months history. Use a ContextBuilder (e.g. on `LlmContextService`) to filter, sort, limit, then map to minimal fields.

### Context Structure by Intent and Entity Type

#### For Task Intents (`schedule_task`, `prioritize_tasks`, `adjust_task_deadline`)

##### `schedule_task` Intent
```
Required Data:
- Target task details (title, description, deadline, duration estimate)
- User work preferences (timezone, work hours, focus times)
- Blocking tasks (what must complete before this task)
- Dependent tasks (what depends on this completing)
- Recent similar tasks (for effort estimation patterns)
- Conflicting events (if task has scheduled time)

Maximum: 5-10 tasks total
Maximum tokens: ~800-1000
```

##### `prioritize_tasks` Intent
```
Required Data:
- All pending/scheduled tasks (limited to 10 most urgent)
- Each task: deadline, estimated duration, priority, dependencies, project_id
- User work patterns (productivity peaks, context switch limits)
- Current date/time context
- Related events that might affect task scheduling

Maximum: 10 tasks total
Maximum tokens: ~1200
```

##### `adjust_task_deadline` Intent
```
Required Data:
- Task being adjusted
- Current deadline vs. requested deadline
- Dependent tasks affected
- User availability in new timeframe
- Conflicting events in new timeframe

Maximum: 5 tasks
Maximum tokens: ~700
```

#### For Event Intents (`schedule_event`, `prioritize_events`, `adjust_event_time`)

##### `schedule_event` Intent
```
Required Data:
- Event details (title, description, location, all_day flag)
- User calendar availability
- Conflicting events (time overlap detection)
- Conflicting tasks (if tasks have scheduled times)
- Recurring event patterns (if applicable)
- Event instances (for recurring events)
- Related tasks (events can have tasks)
- Tags and reminders
- User timezone preferences

Maximum: 5-10 events total
Maximum tokens: ~1000
```

##### `prioritize_events` Intent
```
Required Data:
- All scheduled/upcoming events (limited to 10 most urgent)
- Each event: start_datetime, end_datetime, timezone, status, location
- Related tasks for each event
- User calendar patterns
- Current date/time context

Maximum: 10 events total
Maximum tokens: ~1200
```

##### `adjust_event_time` Intent
```
Required Data:
- Event being adjusted
- Current start/end datetime vs. requested times
- Conflicting events in new timeframe
- Conflicting tasks in new timeframe
- Related tasks that depend on event timing
- Recurring pattern implications (if recurring)

Maximum: 5 events
Maximum tokens: ~800
```

#### For Project Intents (`schedule_project`, `prioritize_projects`, `adjust_project_timeline`)

##### `schedule_project` Intent
```
Required Data:
- Project details (name, description, start_datetime, end_datetime)
- All tasks within project (limited to 10 most relevant)
- Task dependencies within project
- Project tags
- Milestone tracking (derived from tasks or milestones column if added later)
- User work capacity
- Related events that might affect project timeline

Maximum: 3-5 projects with 5-10 tasks each
Maximum tokens: ~1200
```

##### `prioritize_projects` Intent
```
Required Data:
- All active projects (limited to 5 most relevant)
- Each project: start_datetime, end_datetime, task count, completion rate
- Tasks within each project (top 5 per project)
- Project dependencies
- User work capacity
- Current date/time context

Maximum: 5 projects with 5 tasks each
Maximum tokens: ~800-1100
```

##### `adjust_project_timeline` Intent
```
Required Data:
- Project being adjusted
- Current start/end datetimes vs. requested datetimes
- All tasks within project (to recalculate deadlines)
- Dependent projects
- Related events affected by timeline change

Maximum: 1 project with 10 tasks
Maximum tokens: ~1000
```

#### For `resolve_dependency` Intent (Cross-Entity)
```
Required Data:
- Currently blocked entity details (task/event/project)
- Blocking entity status and estimated completion
- Dependent entities waiting on this one
- Critical path analysis across entity types
- Cross-entity relationships (tasks in projects, tasks in events)

Maximum: 3-5 related entities
Maximum tokens: ~800
```

#### Cross-Entity Context Rules
- When scheduling events, include conflicting tasks (if tasks have scheduled times)
- When scheduling projects, include related events that might affect timeline
- When prioritizing, consider tasks, events, and projects together when relevant
- Always respect entity relationships: tasks can belong to projects and events

### Context Filtering Rules

#### For Tasks
✅ **Include:**
- Active task data (status: to_do, doing)
- User preferences and patterns
- Direct dependencies only
- Recent historical patterns (last 30 days)
- Tasks within relevant projects
- Tasks related to relevant events

❌ **Exclude:**
- Completed tasks (status: done, unless showing patterns)
- All 100+ tasks in the backlog
- Irrelevant metadata
- Full task descriptions if not needed
- Tasks older than 90 days
- Archive data

#### For Events
✅ **Include:**
- Active events (status: scheduled, tentative)
- Recurring event patterns (if applicable)
- Event instances for recurring events
- Related tasks
- Tags and reminders

❌ **Exclude:**
- Cancelled or completed events (unless showing patterns)
- Events older than 90 days
- Full event descriptions if not needed
- Archive data

#### For Projects
✅ **Include:**
- Active projects (within date range or no end_datetime)
- Tasks within project (limited to most relevant)
- Project tags
- Milestone information

❌ **Exclude:**
- Completed projects (end_datetime in past, unless showing patterns)
- All tasks in project (limit to 10 most relevant)
- Archive data

### Why This Step Matters
- ✅ **Token Efficiency**: 3B model has limited context window (~8K-32K tokens)
- ✅ **Accuracy**: LLM focuses on relevant data, less noise
- ✅ **Speed**: Smaller payloads = faster inference
- ✅ **Cost**: Fewer tokens = lower resource usage

### Success Criteria
- Context payload <1500 tokens consistently
- Includes all necessary decision factors
- Excludes irrelevant noise
- Preparation completes in <150ms

---

## Phase 3: System Prompting

### Purpose
Set up Hermes 3 to understand its role, constraints, and expected output format.

### Prompt verbosity and length
The system prompts in this section are structured but relatively long. **For a 3B model, shorter, more direct prompts often outperform verbose ones.** Consider A/B testing prompt length during development (e.g. a condensed version of the same role + steps) and keep the system prompt within the ~300–400 token budget noted in [Token budget: system prompt included](#token-budget-system-prompt-included).

### System Prompt Structure

Each intent gets a **specific, tailored system prompt** (not generic).

#### Template Components
1. **Role Definition**
   - "You are an intelligent task scheduling assistant"
   - "Your goal is to prioritize tasks based on X, Y, Z"

2. **Analysis Framework**
   - Step-by-step reasoning process
   - What to consider first, second, third
   - How to evaluate tradeoffs

3. **User Context**
   - Work patterns to respect
   - Preferences to honor
   - Constraints to observe

4. **Output Requirements**
   - Response format (JSON only, no markdown)
   - Required fields
   - No extra text before/after JSON
   - Must conform to the provided JSON schema for the detected intent + entity type (field names, types, required/optional fields)

5. **Tone & Behavior**
   - Be concise
   - Be confident but humble
   - Explain reasoning clearly

### System Prompt Examples

#### For Task Scheduling
```
Role: Task scheduling assistant for a developer

Goal: Suggest optimal time slots that respect:
- Deadlines and hard constraints
- Task dependencies
- User's work patterns (morning focus, context switches)
- Effort estimation
- Conflicts with events

Analysis steps:
1. When is the deadline?
2. What tasks block this one?
3. How long will this realistically take?
4. What's the optimal time considering user patterns?
5. Check for conflicts with events and buffer time

Output: JSON with suggested date/time and reasoning
```

#### For Event Scheduling
```
Role: Event scheduling assistant

Goal: Suggest optimal time slots for calendar events that respect:
- User calendar availability
- Timezone handling
- All-day event considerations
- Recurring pattern requirements
- Location conflicts
- Conflicts with tasks and other events

Analysis steps:
1. What is the event duration and type?
2. Is this a recurring event? What pattern?
3. What times is the user available?
4. Are there conflicting events or tasks?
5. What timezone considerations apply?
6. Suggest optimal start/end datetime

Output: JSON with suggested start_datetime, end_datetime, timezone, all_day, and reasoning
```

#### For Project Planning
```
Role: Project timeline planning assistant

Goal: Suggest optimal project timeline that respects:
- Project duration and scope
- Task dependencies within project
- Milestone dates
- User work capacity
- Related events that affect timeline

Analysis steps:
1. What is the project scope and estimated duration?
2. What tasks are in this project?
3. What are the task dependencies?
4. What milestones need to be hit?
5. What is the user's work capacity?
6. Are there events that affect the timeline?
7. Suggest optimal start_datetime and end_datetime

Output: JSON with suggested start_datetime, end_datetime, milestones, and reasoning
```

#### For Task Prioritization
```
Role: Task prioritization expert

Goal: Rank tasks by true urgency, considering:
- Deadline proximity
- Task dependencies (what blocks others)
- Effort vs. urgency (RICE scoring)
- User's work patterns and capacity
- Project context

Analysis steps:
1. Identify hard deadline constraints
2. Map dependency graph
3. Calculate impact of each task
4. Determine priority scores (0-100)
5. Highlight critical blockers

Output: JSON with prioritized list and reasoning
```

#### For Event Prioritization
```
Role: Event prioritization expert

Goal: Rank events by importance, considering:
- Event timing and urgency
- Related tasks
- User calendar patterns
- Recurring vs. one-time events
- Location and travel time

Analysis steps:
1. Identify time-sensitive events
2. Consider related tasks
3. Evaluate recurring patterns
4. Determine priority scores (0-100)
5. Highlight critical events

Output: JSON with prioritized event list and reasoning
```

#### For Project Prioritization
```
Role: Project prioritization expert

Goal: Rank projects by strategic importance, considering:
- Project deadlines and milestones
- Task completion rates
- Resource allocation
- Dependencies between projects
- User work capacity

Analysis steps:
1. Identify deadline constraints
2. Evaluate project progress
3. Map project dependencies
4. Calculate impact scores
5. Determine priority scores (0-100)

Output: JSON with prioritized project list and reasoning
```

### Prompt Best Practices
- ✅ Be specific about output format
- ✅ Include/attach a JSON schema (or an explicit schema-like field list) and instruct Hermes 3 to output **only** JSON that conforms to it
- ✅ Provide step-by-step reasoning framework
- ✅ Include user context inline
- ✅ Set temperature to 0.3 (low creativity, high consistency)
- ✅ Limit output tokens to 500 (concise responses)
- ❌ Don't use vague language
- ❌ Don't ask for multiple response formats
- ❌ Don't include conflicting instructions

### Success Criteria
- Output is always valid JSON
- Reasoning is transparent and logical
- Recommendations are actionable
- Temperature 0.3 produces consistent results

---

## Phase 4: LLM Inference

### Purpose
Send minimal context + structured prompt to Hermes 3 via Ollama and receive structured recommendations. Use **PrismPHP** for all LLM calls so responses are constrained by schema and errors are handled consistently.

### PrismPHP + Ollama Implementation

#### Configuration (Laravel)
- In `config/prism.php` (or wherever Prism is configured), ensure Ollama is registered. Example env:
  - `OLLAMA_URL` — default `http://localhost:11434/v1`
- Prefer **config** for model name and timeouts (e.g. `config('tasklyst.llm.model', 'hermes3:3b')`, `config('tasklyst.llm.timeout', 45)`) so you can change them per environment without code changes.

#### Structured output (required)
- **Always** use Prism’s structured API. Ollama does not have native JSON mode; Prism appends instructions so the model outputs JSON matching your schema. If the response is not valid JSON, Prism throws `Prism\Prism\Exceptions\PrismException`.
- Root schema **must** be an `ObjectSchema` (per Prism/Ollama). Define one schema per intent/entity (e.g. task scheduling, task prioritization, event scheduling) so the model sees a clear, narrow shape.
- After `asStructured()`, **validate** `$response->structured` in application code (e.g. Form Request or DTO) before persisting or showing to the user. Prism does not validate payloads against the schema yet.

#### Example: task scheduling response schema (Prism)
```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ArraySchema;

// IMPORTANT: Do NOT include entity_id in the LLM output schema. Hermes 3 3B will
// hallucinate IDs (wrong or belonging to other users). The entity being operated on
// is known before the LLM call (you retrieved it in Phase 2). Pass the ID in context
// and resolve it server-side. The LLM outputs only recommendations (dates, priority, reasoning).
$taskScheduleSchema = new ObjectSchema(
    name: 'task_schedule_recommendation',
    description: 'Structured scheduling recommendation for a single task',
    properties: [
        new StringSchema('entity_type', 'Always "task"'),
        new StringSchema('recommended_action', 'One-line action summary'),
        new StringSchema('reasoning', 'Step-by-step reasoning'),
        new NumberSchema('confidence', 'Self-reported 0-1; see doc on confidence calibration'),
        new StringSchema('start_datetime', 'ISO 8601 datetime'),
        new StringSchema('end_datetime', 'ISO 8601 datetime'),
        new NumberSchema('duration', 'Duration in minutes'),
        new StringSchema('priority', 'low|medium|high|urgent'),
        new ArraySchema(
            name: 'blockers',
            description: 'List of blocker descriptions',
            items: new StringSchema('item', 'Blocker description')
        ),
        // Skip alternative_options for Phase 1; add later once core flow is reliable.
    ],
    requiredFields: ['entity_type', 'recommended_action', 'reasoning']
);

$response = Prism::structured()
    ->using(Provider::Ollama, config('tasklyst.llm.model', 'hermes3:3b'))
    ->withSchema($taskScheduleSchema)
    ->withSystemPrompt($intentSpecificSystemPrompt)
    ->withPrompt($userMessage . "\n\nContext:\n" . $contextJson)
    ->withClientOptions(['timeout' => (int) config('tasklyst.llm.timeout', 45)])
    ->withProviderOptions([
        'temperature' => 0.3,
        'num_ctx' => 4096,
    ])
    ->withMaxTokens(500)
    ->asStructured();
```

- Catch `PrismException`: log, then fall back to rule-based recommendation or a clear “Try again” message. Do not expose raw exception to the user.
- Use `$response->structured` (array), `$response->usage->promptTokens` / `completionTokens` for logging and analytics.

#### Ollama + Hermes 3 3B considerations
- **Timeouts**: Local inference can be slow; set `withClientOptions(['timeout' => 45])` or higher so requests do not cut off mid-generation.
- **No native JSON mode**: Rely on Prism’s prompt injection and schema; keep prompts and context small so the model is more likely to output valid JSON.
- **Context size**: Hermes 3 3B has limited context (e.g. `num_ctx` 4096). The **system prompt** (role, analysis steps, constraints, output schema description) typically uses **~300–400 tokens**. With ~500 tokens reserved for the model's reply, **real available context for user data is ~800–1200 tokens**, not the full 1000–1500. Add "system prompt tokens: ~300–400" to your per-intent budget and **cap Phase 2 context** accordingly. During development, check actual usage with `$response->usage->promptTokens` and adjust.

### What Happens (Summary)
1. **Format request**: System prompt (role + analysis framework) + user message + minimal context (Phase 2).
2. **Call**: `Prism::structured()->using(Provider::Ollama, model)->withSchema(...)->withSystemPrompt(...)->withPrompt(...)->asStructured()` with timeout and provider options.
3. **Parse**: Use `$response->structured`; if Prism threw, use fallback (rule-based or retry).
4. **Validate**: In Laravel, validate the structured array (required fields, types, enums) before use.
5. **Log**: User ID, intent, entity type, tokens, duration, and optionally store reasoning in `activity_logs.payload` for audit.

### Inference Parameters

| Parameter | Value | Why |
|-----------|-------|-----|
| Model | `hermes3:3b` (Ollama tag) | Hermes 3 3B, local, instruction-following |
| Temperature | 0.3 | Consistent, low-randomness output |
| num_ctx | 4096 | Enough for prompt + context; avoid overload |
| Max tokens | 500 | Short, focused recommendations |
| Timeout | 45s (configurable) | Ollama can be slow; avoid premature disconnect |

### Laravel conventions (implementation)
- **Config**: Store `tasklyst.llm.model`, `tasklyst.llm.timeout`, and optionally `tasklyst.llm.max_tokens` in a dedicated config file (e.g. `config/tasklyst.php`) and use `config()`, not `env()`, in application code.
- **Services / Actions / DTOs**: Put the Prism call in a **Service** (e.g. `LlmInferenceService`); map `$response->structured` into a **DTO** (e.g. `TaskScheduleRecommendationDto::fromStructured()`); use **Actions** to apply recommendations (see [Backend Architecture Alignment](#backend-architecture-alignment)).
- **Validation**: Use a **Form Request** (or Livewire rules) for chat input. After Prism returns, validate `$response->structured` into the recommendation DTO (required keys, types, enums) before calling apply Actions.
- **Authorization**: Apply Laravel policies so only the authenticated user (or collaborators) can trigger LLM actions on their tasks/events/projects.
- **Optional queue**: For a better UX, consider dispatching a **job** (`ShouldQueue`) that runs the Prism call and then broadcasts or stores the result; the UI can show “Thinking…” and then update when the job completes. Keep the job idempotent and use a timeout consistent with `withClientOptions`.
- **Audit**: Use the existing `ActivityLogRecorder` and `activity_logs` when applying recommendations (intent, entity_type, entity_id from context—not from LLM—user action accept/modify/reject) and, where useful, store the model’s reasoning in `activity_logs` (e.g. `payload.reasoning`) for transparency and debugging.

### Response Structure
Hermes 3 should always return structured JSON with entity type support (shapes below match the Prism `ObjectSchema` you define per intent):

**Entity ID is never in LLM output.** The task/event/project being operated on is determined in Phase 2 (context) and passed into the request. After the LLM returns, the server uses that known entity ID when applying the recommendation. This prevents the model from hallucinating or returning another user's ID.

#### For Tasks (entity_id resolved server-side from context)
```json
{
  "entity_type": "task",
  "recommended_action": "string",
  "reasoning": "Step 1: ... Step 2: ... Step 3: ...",
  "confidence": 0.85,
  "start_datetime": "2025-12-10T09:00:00",
  "end_datetime": "2025-12-10T11:00:00",
  "duration": 120,
  "priority": "high",
  "blockers": ["issue 1", "issue 2"]
}
```
Phase 1: omit `alternative_options`; add in a later phase once core flow is reliable.

#### For Events (entity_id resolved server-side from context)
```json
{
  "entity_type": "event",
  "recommended_action": "string",
  "reasoning": "Step 1: ... Step 2: ... Step 3: ...",
  "confidence": 0.85,
  "start_datetime": "2025-12-10T09:00:00Z",
  "end_datetime": "2025-12-10T10:00:00Z",
  "timezone": "America/New_York",
  "all_day": false,
  "location": "Conference Room A",
  "recurring_pattern": null,
  "conflicts": ["event 789"]
}
```

#### For Projects (entity_id resolved server-side from context)
```json
{
  "entity_type": "project",
  "recommended_action": "string",
  "reasoning": "Step 1: ... Step 2: ... Step 3: ...",
  "confidence": 0.85,
  "start_datetime": "2025-12-01T00:00:00",
  "end_datetime": "2026-01-15T23:59:59",
  "milestones": [
    { "name": "Phase 1 Complete", "date": "2025-12-15" }
  ],
  "blockers": ["Phase 1 dependencies not completed"]
}
```

### Error Handling
- **PrismException** (invalid JSON or provider error): Log, then use rule-based fallback or ask the user to rephrase; never expose stack traces.
- **Timeout**: Increase `withClientOptions(['timeout' => ...])` if needed; show “Taking longer than usual…” and consider a queued job for long-running inference.
- **Ollama unreachable**: Catch connection errors, activate rule-based prioritization/scheduling, and optionally notify that the assistant is unavailable.
- **Low confidence** (e.g. &lt;0.6): Still show the recommendation but surface a “Low confidence” indicator and encourage the user to review or modify.

### Success Criteria
- Response always valid JSON
- Processing time <3-5 seconds
- Reasoning includes concrete facts (not vague)
- All required fields present
- Confidence: either from schema (document as uncalibrated) or derived from validation (preferred)

### Confidence scores from 3B models (caveat)
- Hermes 3 3B (and most small LLMs) do **not** reliably calibrate their own confidence. Using model-reported confidence to drive UI can mislead. Prefer **validation-based confidence** for thesis prototypes: e.g. did all required fields come back? Did dates parse? Use that for logging and optional UI; treat accept/modify/reject rate as a **finding**, not a success threshold.

---

## Phase 5: Structured Output Display (Frontend)

Display and chat UI: see **[Frontend (Implementation Plan)](#frontend-implementation-plan)** below.

<!-- Full Phase 5 content moved to Frontend (Implementation Plan) at end of document. -->

## Phase 6: User Validation & Action (Frontend)

User validation, Accept/Modify/Reject, and modify-flow: see **[Frontend (Implementation Plan)](#frontend-implementation-plan)** below.

---

*Frontend detail below is also collected in **[Frontend (Implementation Plan)](#frontend-implementation-plan)** at the end of this document.*

Livewire 4’s **[wire:stream](https://livewire.laravel.com/docs/4.x/wire-stream#streaming-chat-bot-responses)** directive lets you stream content to the page before the request finishes. It’s a good fit for the chatbot interface:

- **Streaming assistant text:** If the LLM response is streamed (e.g. Ollama streaming via Prism’s streaming API), use `$this->stream(to: 'answer', content: $partial)` in a callback and target a `<p wire:stream="answer">` (or similar) in the chat. The assistant message then appears progressively. By default content is **appended**; use `replace: true` to replace (e.g. for a single “thinking” line).
- **Structured output flow:** When using **structured** output (Prism `asStructured()`), you typically receive one full JSON payload. You can still use `wire:stream` to stream status lines (e.g. “Analyzing your tasks…”, “Checking deadlines…”) while the request runs, then replace that area with the final recommendation card when the response is parsed.
- **Implementation:** In the Livewire component that handles the chat (e.g. using `HandlesLlmAssistant`), call the intent/context/inference pipeline; if the inference step supports streaming, pass a callback that calls `$this->stream(to: 'target', content: $partial)`. Render the stream target in the chat message area with `wire:stream="target"`. See the [Livewire docs – Streaming chat-bot responses](https://livewire.laravel.com/docs/4.x/wire-stream#streaming-chat-bot-responses) for the full ChatBot example.
- **Note:** `wire:stream` is [not compatible with Laravel Octane](https://livewire.laravel.com/docs/4.x/wire-stream#streaming-chat-bot-responses). If you run the app under Octane, either avoid streaming or use an alternative (e.g. poll for result after a queued job).

### Chat session and multi-turn state
Hermes 3 has **no memory**—each Prism call is stateless. If the user says "can you push that back a day?", the model has no idea what "that" refers to without prior chat context.

- **Store conversation history:** Use a `chat_sessions` table (or `activity_logs` with a `session_id`) so each request can load the current session's messages.
- **Pass last N turns into the LLM:** On each call, pass the **last N message pairs** (user + assistant) into Prism, e.g. via `withMessages(...)` (or equivalent in your prompt builder), so the model sees the recent dialogue. **Cap history at 3–5 turns** to keep token usage predictable and within the ~800–1200 context budget.
- **Implementation:** In `HandlesLlmAssistant` (or the Livewire component), load the session's last N messages, format them into the prompt or Prism message list, then append the current user message. After the LLM responds, persist both user and assistant messages to the session before returning.

### Success Criteria
- User understands recommendation at a glance
- Reasoning is transparent and not magical
- User has clear action options
- Design builds trust, not confusion

---

## Phase 7: Backend Execution

### Purpose
**Now the system makes actual database changes** (not before!). Backend is the source of truth.

### Validation Layer
Before any database change:

1. **Syntax Validation**
   - **Tasks**: Dates valid format, times within work hours, durations reasonable (>0, <12 hours typically) — all computed and verified in backend code, not by the LLM
   - **Events**: DateTime valid format, timezone valid, end_datetime > start_datetime — overlaps and gaps are calculated in backend code only
   - **Projects**: DateTime fields valid format, end_datetime >= start_datetime — any roll‑up timeline math is done in backend code

2. **Business Logic Validation**
   - **Tasks**: No scheduling conflicts with events or other tasks, dependencies met, user capacity not exceeded, deadline respected — conflicts and capacity checks are fully deterministic rules, not delegated to the LLM
   - **Events**: No overlapping events (configurable), no conflicts with tasks, timezone handling correct — all overlap detection and timezone math is done in backend code
   - **Projects**: Milestone dates within project range, task dependencies respected, timeline realistic — project‑level constraints are enforced by backend rules, with LLM used only for ranking and suggestion

3. **Cross-Entity Validation**
   - Event/task conflicts (if task has scheduled time)
   - Project timeline vs event conflicts
   - Task dependencies across projects

4. **Data Integrity Checks**
   - Entity exists and belongs to user
   - No race conditions
   - Concurrent modification safe

### Execution Steps

#### For Tasks:
1. **Validate** recommendation against rules
2. **Update** task in database:
   - start_datetime, end_datetime
   - duration (minutes)
   - priority (enum: low, medium, high, urgent)
   - status (to_do → doing, or to_do → done)
   - project_id (if applicable)
   - event_id (if applicable)
   - llm_reasoning (store for audit trail)

3. **Cascade Updates**
   - If dependent tasks affected, re-prioritize them
   - Update blockers relationships
   - If task belongs to project, update project progress
   - If task belongs to event, notify event context
   - Notify dependent task owners if shared workspace

#### For Events:
1. **Validate** recommendation against rules
2. **Update** event in database:
   - start_datetime, end_datetime
   - timezone
   - all_day (boolean)
   - location
   - status (scheduled, cancelled, completed, tentative)
   - llm_reasoning (store for audit trail)

3. **Handle Recurring Events** (if applicable):
   - Update recurring_events table
   - Update or create event_instances
   - Handle event_exceptions if needed

4. **Cascade Updates**
   - If event timing changes, notify related tasks
   - Update conflicting events (if configurable)
   - Notify reminders system

#### For Projects:
1. **Validate** recommendation against rules
2. **Update** project in database:
   - start_datetime, end_datetime
   - llm_reasoning (store for audit trail)

3. **Cascade Updates**
   - Recalculate deadlines for all tasks within project
   - Update project-related events if timeline changes
   - Re-prioritize project tasks based on new timeline

#### Common Steps (All Entities):
4. **Log Changes**
   - Store in audit log
   - Record: entity_type, entity_id, what changed, why (LLM reasoning), when, by whom
   - For analytics: track LLM recommendation accuracy per entity type

5. **Notify User**
   - Show success message
   - Display updated entity view
   - Optionally: show next recommended action

### Database Transactions
- ✅ Use transactions for multi-step updates
- ✅ Rollback on any validation failure
- ✅ Prevent partial updates

### API/Response Structure

#### For Tasks:
```json
{
  "success": true,
  "message": "Task scheduled for Wednesday, Dec 10 at 9:00 AM",
  "entity_type": "task",
  "entity": {
    "id": 123,
    "title": "...",
    "start_datetime": "2025-12-10T09:00:00",
    "end_datetime": "2025-12-10T11:00:00",
    "duration": 120,
    "priority": "high",
    "status": "doing"
  },
  "next_action": "Your next recommended task is..."
}
```

#### For Events:
```json
{
  "success": true,
  "message": "Event scheduled for Wednesday, Dec 10 at 9:00 AM",
  "entity_type": "event",
  "entity": {
    "id": 456,
    "title": "...",
    "start_datetime": "2025-12-10T09:00:00Z",
    "end_datetime": "2025-12-10T10:00:00Z",
    "timezone": "America/New_York",
    "all_day": false,
    "location": "Conference Room A",
    "status": "scheduled"
  },
  "next_action": "Your next recommended event is..."
}
```

#### For Projects:
```json
{
  "success": true,
  "message": "Project timeline set from Dec 1, 2025 to Jan 15, 2026",
  "entity_type": "project",
  "entity": {
    "id": 789,
    "name": "...",
    "start_datetime": "2025-12-01T00:00:00",
    "end_datetime": "2026-01-15T23:59:59"
  },
  "cascade_updates": {
    "tasks_updated": 15,
    "milestones_set": 3
  },
  "next_action": "Your next recommended project is..."
}
```

### Error Responses
```json
{
  "success": false,
  "error": "Scheduling conflict detected",
  "entity_type": "task",
  "details": "Task overlaps with 'Team Meeting' event already scheduled for 9am-10am",
  "suggestion": "Try scheduling for Thursday instead"
}
```

### Why This Step Matters
- ✅ **Data Integrity**: All changes validated before commit
- ✅ **Audit Trail**: Track what LLM recommended vs. what actually happened
- ✅ **Rollback Capability**: Undo changes if needed
- ✅ **Monitoring**: Detect if LLM makes consistently bad recommendations

### Success Criteria
- All database changes are validated
- Transactions ensure consistency
- Audit trail is complete
- User gets clear feedback on success/failure

---

## Phase 8: Feedback Loop & Logging

### Purpose
Track LLM performance, user acceptance, and gather data to improve future recommendations.

### What Gets Logged

#### Per Interaction
- **User ID** - who made the request
- **Intent Type** - what they were trying to do
- **Entity Type** - task, event, or project
- **Input Context** - what data was sent to LLM
- **LLM Response** - what the model returned
- **Tokens Used** - for cost tracking
- **Processing Time** - for performance monitoring
- **User Action** - did they accept/modify/reject?

#### Metrics Tracked
- **LLM Accuracy** - did user accept the recommendation? (per entity type)
- **Token Efficiency** - how many tokens per decision type and entity type?
- **Latency** - how fast was the full workflow? (per entity type)
- **Error Rate** - how often did parsing/inference fail? (per entity type)
- **User Satisfaction** - do users modify recommendations often? (per entity type)
- **Cross-Entity Conflicts** - how often do events conflict with tasks?

### Analytics Dashboard (Future)
Questions to answer with logs:
- Which intent types have highest acceptance rate?
- Which entity types (tasks/events/projects) have highest acceptance rate?
- What time of day are recommendations most accurate?
- How does recommendation quality correlate with context size?
- Are certain user types accepting recommendations more?
- What's the most common modification pattern?
- Do users modify events more than tasks?
- How often do cross-entity conflicts occur?
- Which entity types require the most user modifications?

### Continuous Improvement
Use logged data to:
1. **Refine prompts** - If acceptance <70%, tweak system prompt
2. **Adjust confidence** - Calibrate confidence scores against actual acceptance
3. **Tune parameters** - Find optimal temperature, max_tokens, context size
4. **Detect edge cases** - Find patterns in rejections, address them

### Success Criteria
- All interactions logged consistently
- Logs contain both input and output
- Processing times tracked
- User acceptance data recorded
- Data structure supports future analysis

---

## Complete Workflow Decision Tree

```
User Input
├─ IntentClassifier + EntityDetector
│  ├─ schedule_task (90% conf, entity: task) → Task schedule handler
│  ├─ schedule_event (85% conf, entity: event) → Event schedule handler
│  ├─ schedule_project (80% conf, entity: project) → Project schedule handler
│  ├─ prioritize_tasks (85% conf, entity: task) → Task prioritize handler
│  ├─ prioritize_events (80% conf, entity: event) → Event prioritize handler
│  ├─ prioritize_projects (75% conf, entity: project) → Project prioritize handler
│  ├─ resolve_dependency (70% conf, entity: any) → Cross-entity dependency handler
│  ├─ adjust_task_deadline (80% conf, entity: task) → Task deadline handler
│  ├─ adjust_event_time (75% conf, entity: event) → Event time handler
│  ├─ adjust_project_timeline (70% conf, entity: project) → Project timeline handler
│  └─ general_query (?) → Help message
│
├─ ContextPreparer
│  ├─ Fetch minimal relevant data from DB
│  ├─ Include cross-entity context (events for tasks, tasks for projects, etc.)
│  └─ Filter by entity type and intent
│
├─ OllamaService
│  ├─ System Prompt (intent + entity-specific)
│  ├─ Context (capped at ~800-1200 tokens)
│  └─ Hermes 3 Inference (temp 0.3, max 500 tokens)
│
├─ Response Parser
│  ├─ Extract JSON from response
│  ├─ Validate structure (entity_type required)
│  └─ Fallback if parsing fails
│
├─ Display Layer
│  ├─ Render recommendation (entity-specific UI)
│  ├─ Show reasoning
│  ├─ Highlight blockers (cross-entity aware)
│  └─ Provide action buttons
│
├─ User Action
│  ├─ Accept → Backend execution
│  ├─ Modify → Updated backend execution (entity-specific fields)
│  └─ Reject → Reset form
│
└─ Backend + Logging
   ├─ Validate all changes (entity-specific rules)
   ├─ Update database (tasks/events/projects tables)
   ├─ Cascade updates (cross-entity aware)
   ├─ Log interaction (with entity_type)
   └─ Show success/failure
```

---

## Performance Targets

| Stage | Duration | Target |
|-------|----------|--------|
| Intent Classification | <10ms | Instant, no waiting |
| Context Preparation | 50-150ms | Fast DB queries |
| LLM Inference | 1-3 seconds | Acceptable wait |
| Display Rendering | <100ms | Instant |
| **Total Round Trip** | **2-4 seconds** | **Snappy UX** |

---

## Fallback Strategy

If LLM fails at any point:

1. **LLM Timeout** → Use cached previous recommendation
2. **JSON Parse Error** → Show generic suggestion + "Try rephrasing"
3. **Ollama Offline** → Activate rule-based prioritization
4. **Confidence <60%** → Flag for manual review
5. **Unknown Intent** → Show help examples

Rule-based fallback logic:
- Prioritize by deadline only
- Schedule by availability only
- Do NOT make assumptions

---

### Deterministic Fallback Layer (In-Depth)

**Why it matters:** The AI can fail (invalid JSON, Ollama crash, model stall, timeout). Without a fallback, the feature breaks. A **deterministic fallback layer** keeps the system reliable, defensible for evaluation (e.g. ISO reliability), and thesis-defensible.

**When to trigger fallback:**

| Condition | Action |
|-----------|--------|
| `PrismException` (invalid JSON or provider error) | Run rule-based path; do not expose error to user |
| Ollama unreachable / connection failure | Run rule-based path; optionally show "Assistant unavailable" |
| Request timeout (no response within configured limit) | Run rule-based path or show "Try again" |
| `$response->structured` fails validation (missing keys, wrong types) | Run rule-based path |
| Confidence below threshold (e.g. &lt;0.6) | Still show recommendation but surface "Low confidence" and encourage review; optionally offer rule-based alternative |

**Implementation pattern:** Centralize the decision so the UI and pipeline always get either a valid recommendation or a fallback result—never a raw exception.

```php
// Pseudocode: in LlmInferenceService or the action that orchestrates the flow
public function getRecommendation(User $user, string $intent, array $context): RecommendationResult
{
    try {
        $response = Prism::structured()->using(...)->withSchema(...)->withPrompt(...)->asStructured();
        $dto = TaskScheduleRecommendationDto::fromStructured($response->structured);
        if (!$this->isValidRecommendation($dto)) {
            return $this->fallbackPrioritization($user, $intent, $context);
        }
        return RecommendationResult::fromDto($dto);
    } catch (PrismException $e) {
        Log::warning('LLM inference failed, using fallback', ['error' => $e->getMessage()]);
        return $this->fallbackPrioritization($user, $intent, $context);
    }
}
```

**Rule-based fallback rules (prioritization):**

- **Earlier due date** → higher priority score (closer deadline = higher rank).
- **Higher complexity** (or “urgent” priority) → schedule earlier when suggesting slots.
- **Overdue tasks** → highest priority; always appear first in fallback ordering.
- **Schedule fallback:** suggest next available slot by work hours / existing events; no AI, just gap-finding.

Keep these rules in a dedicated class (e.g. `RuleBasedPrioritizationService` or methods on `LlmContextService`) so they are testable and reusable. This makes the system **reliable**, **ISO-aligned (reliability dimension)**, and **technically defensible** in a thesis or review.

**Checklist (see also Implementation Checklist → Phase 4):**

- Implement the deterministic fallback layer in the orchestrator (e.g. `if (!$this->isValidRecommendation($dto)) return $this->fallbackPrioritization(...)`).
- Implement rule-based fallback (e.g. `RuleBasedPrioritizationService`): earlier due date = higher rank, overdue = highest, higher complexity = earlier in schedule; keep testable and reusable.

---

## Key Design Principles

### 1. Progressive Disclosure
- Show what matters first (recommendation)
- Details available on demand (reasoning)
- Don't overwhelm user with data

### 2. Trust Through Transparency
- Always show why LLM made a decision
- Include concrete facts in reasoning
- Admit uncertainty via confidence scores

### 3. User Control
- LLM suggests, user approves
- Easy modification options
- User can always override

### 4. Fail Gracefully
- Errors are not crashes
- Fallback to simple rules
- Clear error messages

### 5. Efficient Context
- Less is more (minimal context window)
- Every token counts (cost + quality)
- Curate ruthlessly

### 6. Intent First
- Don't call LLM for simple decisions
- Classify before computing
- Route intelligently

### 7. Structured Over Free-Form
- JSON output, never free text
- Parseable, predictable format
- Prevents hallucination

### 8. Audit Everything
- Log all decisions
- Store reasoning
- Enable continuous improvement

### 9. Separate AI From Authority (In-Depth)

**Rule to enforce everywhere (backend and UI):** The AI **never** has authority to change data on its own. It can only **suggest**. The user must explicitly **Accept**, **Modify**, or **Reject** before any task/event/project is updated.

**What the AI must NOT do:**

- Automatically reschedule tasks
- Change deadlines without user confirmation
- Modify priorities silently
- Create, update, or delete any entity without a user action on a recommendation

**What the user must do:**

- **Accept** — apply the recommendation as-is (one explicit action, e.g. button click)
- **Modify** — adjust the suggestion (e.g. change date/time) then confirm; the applied values are the user’s, not the model’s
- **Reject** — discard the recommendation and optionally try again or cancel

**Enforcement:**

- **Backend:** No code path may update tasks/events/projects based solely on LLM output. Updates may only run after an explicit “apply” step (e.g. the action triggered by Accept or Modify), with the same validation and policies as non-LLM flows.
- **UI:** Do not auto-apply recommendations after a timeout or “suggested” state. Always show Accept/Modify/Reject; only apply when the user chooses Accept or confirms Modify.

**Why this matters for your thesis:**

- **Over-reliance risk** — users stay in control; the system does not act on their behalf without consent.
- **Ethical safeguards** — recommendations are clearly presented as suggestions, not decisions.
- **Bias mitigation** — any model bias is limited to suggestions; final decisions are human.
- **Defensibility** — you can state clearly that “AI suggests, user decides,” which strengthens the thesis and evaluation (e.g. ISO usability/dependability).

---

## Implementation Checklist

Use the [Backend quick reference](#backend-quick-reference-for-ai--implementers) for a compact list of constraints. Below: **Backend** (Phases 1–4, 7–8, Architecture, Testing) then **Frontend** (Phases 5–6).

### Backend

#### Architecture (Services, Actions, DTOs, Traits)
Align with the [Backend Architecture Alignment](#backend-architecture-alignment) section so the LLM flow uses the same patterns as the rest of the app:
- [ ] **Intent**: `App\Services\LlmIntentClassificationService` or `App\Actions\Llm\ClassifyLlmIntentAction` (no DB; returns intent + entity_type + confidence)
- [ ] **Context**: `App\Services\LlmContextService` with methods that use existing Task/Event/Project services or Eloquent to build minimal context payloads
- [ ] **Prompts**: Same service or `App\Services\LlmPromptService` for system prompts per intent/entity
- [ ] **Inference**: `App\Services\LlmInferenceService` wrapping Prism (Ollama, schema, asStructured, PrismException handling)
- [ ] **DTOs**: `App\DataTransferObjects\Llm\*RecommendationDto` (e.g. `TaskScheduleRecommendationDto::fromStructured(array)`) to validate and type the LLM response before use
- [ ] **Apply**: Reuse `UpdateTaskPropertyAction` / `UpdateEventPropertyAction` / `UpdateProjectPropertyAction` with values from the recommendation DTO, or add `ApplyTaskScheduleRecommendationAction` etc. that call existing services
- [ ] **Livewire**: `App\Livewire\Concerns\HandlesLlmAssistant` trait that injects the above services/actions, manages chat state, and calls apply actions when the user accepts (or modify flow via existing HandlesTasks/HandlesEvents/HandlesProjects)
- [ ] **Validation**: `App\Support\Validation\LlmStructuredResponseValidation` or validation inside recommendation DTOs for `$response->structured`
- [ ] **Audit**: Use existing `ActivityLogRecorder` and `activity_logs` when applying LLM recommendations (payload: reasoning, confidence, intent). See [Backend quick reference](#backend-quick-reference-for-ai--implementers) (entity_id from context).

#### Phase 1: Intent Classification
- [ ] Define regex patterns for each intent and entity type
- [ ] Implement entity detection (task/event/project)
- [ ] Test classification accuracy >90%
- [ ] Test entity detection accuracy >85%
- [ ] Implement confidence scoring
- [ ] Add fallback to `general_query`. See [Intent classification: regex + LLM fallback](#intent-classification-regex-fast-path--optional-llm-fallback).

#### Phase 2: Context Preparation
- [ ] Design context schema per intent type and entity type
- [ ] Implement **context compression** via a ContextBuilder (e.g. on `LlmContextService`): filter (e.g. pending only), sort (e.g. by due date), limit (e.g. 10–12 tasks); never send 60+ tasks or 6 months of history (see [Context Compression and ContextBuilder](#context-compression-and-contextbuilder-in-depth))
- [ ] Implement data filtering:
  - Tasks: max 10–12 tasks
  - Events: max 10 events
  - Projects: max 5 projects with 5-10 tasks each
- [ ] Set token budget (~1000-1500 max)
- [ ] Add caching for user preferences
- [ ] Implement cross-entity context (events for tasks, tasks for projects). See [Context and prompts summary](#context-and-prompts-summary-agent-reference) and [Token budget](#token-budget-system-prompt-included).

#### Phase 3: System Prompting
- [ ] Write intent-specific and entity-specific system prompts
  - [ ] Task scheduling prompts
  - [ ] Event scheduling prompts
  - [ ] Project planning prompts
  - [ ] Prioritization prompts (all entity types)
- [ ] Test with Hermes 3 locally
- [ ] Tune temperature (0.3 recommended)
- [ ] Validate JSON output format (with entity_type). Keep prompts short for 3B; see [Prompt verbosity](#prompt-verbosity-and-length).

#### Phase 4: LLM Inference (PrismPHP + Ollama)
- [ ] Configure Ollama in Prism (`OLLAMA_URL`) and optional `config/tasklyst.php` (model, timeout, max_tokens)
- [ ] Define one `ObjectSchema` per intent/entity (task schedule, task prioritize, event schedule, etc.) using Prism schema types; **do not include `entity_id` in the output schema**—resolve entity server-side from context (see [Response Structure](#response-structure)).
- [ ] Use `Prism::structured()->using(Provider::Ollama, model)->withSchema(...)->withSystemPrompt(...)->withPrompt(...)->withClientOptions(['timeout' => ...])->asStructured()`
- [ ] Catch `PrismException` and fall back to rule-based recommendation or “Try again”; never expose raw errors
- [ ] Validate `$response->structured` in application code (required keys, types, enums) before use
- [ ] Log requests/responses (user_id, intent, entity_type, tokens, duration) and optionally store reasoning in `activity_logs.payload`

### Testing “Golden Paths” (End‑to‑End)
- [ ] Define at least 2–3 **canonical scenarios** per core intent (e.g., `schedule_task`, `prioritize_tasks`, `schedule_event`)
- [ ] For each scenario, fix:
  - [ ] Input utterance (user message)
  - [ ] Expected context payload (Phase 2)
  - [ ] Expected structured LLM output shape (fields + types, not exact wording)
  - [ ] Expected backend validation behavior and final database changes
- [ ] Automate these as end‑to‑end tests so future changes to prompts, schemas, or context preparation cannot silently break the pipeline

### Frontend

#### Phase 5: Display Layer
Full plan: [Frontend (Implementation Plan)](#frontend-implementation-plan).
- [ ] Design recommendation card UI (entity-specific)
- [ ] Implement reasoning display
- [ ] Add blocker alerts (cross-entity aware)
- [ ] Create action buttons
- [ ] Consider Livewire 4 `wire:stream` for chat: stream assistant text or status updates (see [Phase 5 – Chat UI and streaming](#chat-ui-and-streaming-livewire-4-wirestream)); skip if using Octane.
- [ ] **Chat session / multi-turn:** Store conversation in `chat_sessions` (or `activity_logs` with session_id); pass last 3–5 message pairs to Prism so the model can resolve "that", "it", etc. (see [Chat session and multi-turn state](#chat-session-and-multi-turn-state)).
- [ ] **Readonly intents:** For `prioritize_events` and `prioritize_projects` (and any display-only intent), do not show an "Accept" button; show the ranked recommendation only (see [Readonly vs actionable intents](#readonly-vs-actionable-intents)).
- [ ] Entity-specific metrics display:
  - [ ] Tasks: date, time, priority, duration
  - [ ] Events: datetime, timezone, location, all-day, recurring
  - [ ] Projects: timeline, milestones, task count, progress

#### Phase 6: User Validation
- [ ] Build accept/modify/reject buttons
- [ ] Implement modify form (entity-specific fields):
  - [ ] Tasks: date, time, duration, priority
  - [ ] Events: start_datetime, end_datetime, timezone, all_day, location, recurring_pattern
  - [ ] Projects: start_date, end_date, milestone dates
- [ ] Add cross-entity conflict detection
- [ ] Show real-time updates

#### Phase 7: Backend Execution
See [Phase 7: Backend Execution](#phase-7-backend-execution) in the body for full validation and execution steps.
- [ ] Validation layer (entity-specific rules):
  - [ ] Tasks: dates, times, durations, conflicts
  - [ ] Events: DateTime, timezone, overlaps, conflicts
  - [ ] Projects: date ranges, milestones, task dependencies
- [ ] Cross-entity validation (event/task conflicts, project timeline vs events)
- [ ] Database update transactions (tasks/events/projects tables)
- [ ] Cascade updates:
  - [ ] Tasks: update dependent tasks, project progress
  - [ ] Events: update recurring patterns, related tasks
  - [ ] Projects: update task deadlines, related events
- [ ] Audit logging (with entity_type). See [Backend quick reference](#backend-quick-reference-for-ai--implementers) (apply only after user action).

#### Phase 8: Analytics
- [ ] Log all interactions (with entity_type)
- [ ] Track acceptance rates per entity type
- [ ] Monitor token usage per entity type
- [ ] Track cross-entity conflicts
- [ ] Setup performance dashboard

---

## Common Pitfalls to Avoid

See [Backend quick reference](#backend-quick-reference-for-ai--implementers) for the canonical list of backend constraints. Summary:

❌ **Don't:** Put `entity_id` in the LLM output schema. ✅ **Do:** Resolve entity from context server-side. (See [Response Structure](#response-structure).)

❌ **Don't:** Rely on model-reported confidence for critical UI decisions. ✅ **Do:** Use validation-based confidence or document as uncalibrated. (See [Confidence scores from 3B models (caveat)](#confidence-scores-from-3b-models-caveat).)

❌ **Don't:** Send all 100 tasks/events/projects to LLM. ✅ **Do:** Send 5–10 most relevant per type (see [Context and prompts summary](#context-and-prompts-summary-agent-reference)).

❌ **Don't:** Use free-form LLM responses
✅ **Do:** Force structured JSON output with entity_type

❌ **Don't:** Execute recommendations without user approval
✅ **Do:** Show user first, get approval, then execute

❌ **Don't:** Hide the LLM reasoning
✅ **Do:** Show step-by-step logic transparently

❌ **Don't:** Call LLM for every input
✅ **Do:** Classify first (intent + entity), route intelligently

❌ **Don't:** Ignore LLM failures. ✅ **Do:** Implement fallback rules. (See [Deterministic Fallback Layer](#deterministic-fallback-layer-in-depth).)

❌ **Don't:** Use high temperature (>0.7)
✅ **Do:** Use temperature 0.3 for consistency

❌ **Don't:** Skip logging/monitoring
✅ **Do:** Log everything for continuous improvement

❌ **Don't:** Ignore cross-entity conflicts
✅ **Do:** Check event/task conflicts, project timeline vs events

❌ **Don't:** Treat all entity types the same
✅ **Do:** Use entity-specific prompts, validation, and display logic

❌ **Don't:** Forget recurring patterns
✅ **Do:** Handle recurring_tasks and recurring_events properly

❌ **Don't:** Call Ollama without Prism structured output
✅ **Do:** Use `Prism::structured()->withSchema(ObjectSchema)->asStructured()` so responses are parseable and PrismException is thrown on invalid JSON

❌ **Don't:** Ignore PrismException or expose it to the user
✅ **Do:** Catch it, log it, and fall back to rule-based recommendation or a friendly “Try again” message

---

## Success Metrics

**Framing for thesis and evaluation:** The metrics below should be treated as **aspirational targets or research questions**, not hard success criteria. For a 3B local model in a first prototype, thresholds like "accept rate &gt;80%" or "conflict detection &gt;95%" are unrealistic—if the panel evaluates against them and finds e.g. 65% accept rate, it can look like failure even when the system is genuinely useful. For **ISO/IEC 25010** and thesis defense, tie evaluation to what you can actually measure and report as findings.

### Measurable (thesis and ISO)
- **Response time:** End-to-end workflow time (intent → display); target &lt;5s, log actual.
- **JSON validity rate:** % of LLM responses that pass schema validation; log and report.
- **Task/event creation success after AI suggestion:** Did the user accept or modify and then succeed? Log accept/modify/reject; report as **findings**, not pass/fail thresholds.
- **User-reported satisfaction:** e.g. Likert scale after using the assistant; suitable for thesis and quality-in-use.

### Aspirational (research questions, not gates)
- User satisfaction and accept rate (log and analyze; do not set a single "pass" threshold).
- Intent classification accuracy, entity detection accuracy (improve over time; report as findings).
- Task completion rate, scheduling efficiency, time saved (aspirational; report what you observe).

### System Performance (targets)
- Intent classification: &lt;10ms
- Context prep: &lt;150ms
- LLM inference: &lt;3–5s (acceptable for local 3B)
- Total: &lt;5 seconds

---

## Next Steps (Roadmap)

### Phase 1 (Current)
- [ ] Implement basic scheduling:
  - [ ] schedule_task
  - [ ] schedule_event
  - [ ] schedule_project
- [ ] Implement basic prioritization:
  - [ ] prioritize_tasks
  - [ ] prioritize_events
  - [ ] prioritize_projects

### Phase 2
- [ ] Add dependency resolution (resolve_dependency - cross-entity)
- [ ] Add adjustment intents:
  - [ ] adjust_task_deadline
  - [ ] adjust_event_time
  - [ ] adjust_project_timeline
- [ ] Cross-entity conflict detection

### Phase 3
- [ ] Multi-user collaboration (team scheduling)
- [ ] Recurring patterns:
  - [ ] Recurring tasks (recurring_tasks, task_instances)
  - [ ] Recurring events (recurring_events, event_instances)
- [ ] Time zone intelligence (events)
- [ ] Project milestone tracking

### Phase 4
- [ ] ML-based effort estimation (tasks)
- [ ] Predictive scheduling (predict completion for tasks/projects)
- [ ] Calendar integration (events)
- [ ] Cross-entity optimization (unified calendar view)

### Phase 5
- [ ] Cross-workspace dependencies
- [ ] Resource allocation (across projects)
- [ ] Burndown projections (projects)
- [ ] Advanced recurring pattern management

---

## Frontend (Implementation Plan)

All frontend plan content is collected here. The interface is a **chatbot** (Livewire + Flux). Phases 5 and 6 in the pipeline refer to this section.

### Phase 5: Structured Output Display

**Purpose:** Show the user both the recommendation and the reasoning in a transparent format in the chatbot, with clear Accept / Modify / Reject actions.

**Display components:**
- **Primary recommendation:** Clear hierarchy; entity-specific metrics (tasks: date, time, priority, duration; events: start/end, timezone, location, all-day, recurring; projects: dates, milestones, task count); color for urgency/confidence.
- **Reasoning:** Step-by-step logic, concrete facts, tradeoffs; format "Step 1: … Step 2: …".
- **Blockers/dependencies:** List blockers, status, cross-entity blockers; yellow/red as needed.
- **Smart suggestions:** 2–3 actionable bullets from LLM analysis.
- **Confidence indicator:** Either omit (use validation-based confidence) or show model self-assessment with a note that it is not calibrated (see Confidence caveat elsewhere in doc).

**Layout:** Information hierarchy, visual separation, color coding, mobile-friendly, clear CTAs.

**User feedback:** Accept (green), Modify (grey), Reject (subtle); processing state ("Generating…"), success state ("Scheduled!").

**Chat UI and streaming (Livewire 4 `wire:stream`):** Use Livewire 4 [wire:stream](https://livewire.laravel.com/docs/4.x/wire-stream#streaming-chat-bot-responses) for streaming assistant text or status lines; with structured output you can stream status then show the final recommendation card. Not compatible with Laravel Octane—use poll or skip streaming if using Octane.

#### Workspace placement: bottom dock assistant (TaskLyst workspace)

In the main workspace page (`resources/views/pages/workspace/⚡index/index.blade.php`), the chatbot should live in a **bottom dock** that sits across the width of the workspace and can slide up over the main content:

- **Placement**
  - Dock is anchored to the bottom of the workspace, above any global app chrome.
  - Closed state: a thin bar with a button (e.g. "Assistant") visible at the bottom edge.
  - Open state: the dock expands to ~30–40% of viewport height on desktop (resizable if desired) and overlays the list / kanban / time‑grid view without being tied to any specific card.
  - Mobile: the dock becomes a bottom sheet, occupying most of the height when open, with a clear close/drag handle.

- **Why this placement**
  - Keeps the assistant **independent of any single view**: the same dock works for list, future kanban, and future time‑grid modes.
  - Allows the user to **see both** recommendations and their tasks/events/projects at once by adjusting the dock height.
  - Fits naturally into the existing workspace layout without overloading `list-item-card` or other per‑item components.

#### In-dock UI components

Inside the bottom dock, the chat UI is composed of small, focused subcomponents:

- **Header strip**
  - Title: short label such as "Workspace Assistant".
  - Context chips: show what the assistant is using (e.g. `Today`, `Filters: High priority`, `Scope: Tasks`), derived from the same Livewire state as the workspace list.
  - Session controls: "New session" / "Clear chat", plus a collapse/expand control for the dock itself.

- **Conversation timeline**
  - Message bubbles for user and assistant, with timestamps.
  - A subtle label per assistant answer indicating whether the underlying intent is **readonly** (e.g. `prioritize_events`) or **actionable** (e.g. `schedule_task`), so the user knows if Accept will cause changes.
  - Optional "Show reasoning" toggle per assistant message that expands to show the model’s step‑by‑step rationale or the raw structured JSON summary.

- **Recommendation cards**
  - **Task prioritization card** (`prioritize_tasks`):
    - Ranked list of tasks with title, status badge, priority badge, and due date chip.
    - Per‑item actions: **Accept**, **Modify**, **Reject**.
  - **Scheduling / adjustment card** (`schedule_*`, `adjust_*` intents):
    - For each affected item, show a concise card with the proposed new date/time and a one‑line "change preview" (e.g. `End date → Fri, Mar 6 · 17:00`).
    - Aggregate view that lists all pending changes within a single recommendation.
  - **Readonly recommendation card** (`prioritize_events`, `prioritize_projects`):
    - Ranked list of events or projects with no DB‑writing actions; instead show "Done" / "Ask something else" buttons.

- **Inline Modify panel**
  - When the user clicks **Modify** on a recommendation card, show a compact form beneath that card:
    - Tasks: date/time pickers, duration selector, and (optionally) priority selector, prefilled from the LLM output.
    - Events: start/end datetime pickers, all‑day toggle, and (optional) location/timezone controls.
    - Projects: start/end date pickers and key milestone dates if applicable.
  - The form reuses existing TaskLyst validation rules; on submit it calls the same backend apply Action but with the user‑edited values.

- **Context controls bar**
  - Scope chips such as `Today`, `Next 7 days`, `This week`, `Overdue only` that influence which items are included in Phase 2 context.
  - Entity chips such as `Tasks`, `Events`, `Projects` that mirror the workspace filters and make explicit what the assistant is looking at.
  - Optional toggle like "Use current workspace filters" that locks the assistant scope to whatever filters/search are active in the workspace.

- **Input area**
  - Multiline textarea with:
    - Send button and keyboard hint (`Enter` to send, `Shift+Enter` for newline).
    - Disabled state while a request is inflight (with an inline "Thinking…" indicator).
  - Prompt shortcut chips for common flows:
    - "Plan my day from today’s tasks"
    - "Prioritize overdue items"
    - "Suggest a schedule for this project"

- **Status and feedback strip**
  - Small, inline status line in the most recent assistant bubble: "Analyzing tasks…", "Checking deadlines…", etc., wired to `wire:stream` or queued job progress where possible.
  - Mode badges such as `AI suggestion` vs `Deterministic plan` so users know when the fallback rules (non‑LLM) were used instead of Hermes.
  - Processing / success states for actions: "Applying changes…" and "Changes applied" when Accept or Modify triggers Phase 7.

- **Error and timeout banner**
  - When the LLM call fails or times out, show a single inline banner in the timeline:
    - Human‑readable message (e.g. "The assistant is unavailable right now; here is a simple rule‑based plan instead.") plus a close button.
    - If a deterministic fallback was used, clearly label the resulting recommendation card as such, while still allowing Accept/Modify/Reject.

**Chat session and multi-turn:** Hermes has no memory. Store history in `chat_sessions` (or `activity_logs` with session_id); pass last 3–5 message pairs into Prism; in `HandlesLlmAssistant`, load last N messages, append current message, persist user + assistant after response.

**Success criteria:** User understands at a glance; reasoning transparent; clear action options; design builds trust.

### Phase 6: User Validation & Action

**Purpose:** User reviews the recommendation and chooses accept, modify, or reject.

**User actions:**
- **Accept:** Click Accept → proceed to Phase 7 (Backend Execution).
- **Modify:** Adjust date/time/priority etc.; user input overrides LLM; proceed to Phase 7 with modified values.
- **Reject:** Try Again or Cancel; back to input; can start over from Phase 1.

**Modification workflow (by entity):**
- **Tasks:** Inputs for date, time, duration, priority; show updated recommendation; warn on conflicts; then Phase 7.
- **Events:** Inputs for start_datetime, end_datetime, timezone, all_day, location, recurring_pattern; warn on conflicts; if recurring, show implications; then Phase 7.
- **Projects:** Inputs for start/end, milestone dates; warn on impact to tasks/events; show cascade on task deadlines; then Phase 7.

**Why it matters:** User control, safety, trust, feedback, flexibility. Success: user understands recommendation, has clear accept/modify/reject options, smooth modify flow, changes reflected immediately.

---

## Reference Links & Resources

### LLM stack (this plan)
- **Ollama**: https://ollama.ai — run `hermes3:3b` (or your chosen tag) locally.
- **PrismPHP**: https://prismphp.com — Laravel LLM integration; use [Ollama provider](https://prismphp.com/providers/ollama.html), [Schemas](https://prismphp.com/core-concepts/schemas.html), [Structured output](https://prism.echolabs.dev/core-concepts/structured-output.html).
- **Hermes 3**: e.g. NousResearch Hermes 3 3B; pull in Ollama with the model tag you use (e.g. `hermes3:3b`).

### Frameworks
- Laravel 12: https://laravel.com/docs/12.x
- Livewire 4: https://livewire.laravel.com/docs (match your app version)
- PrismPHP: https://prismphp.com — required for this implementation plan.

### Prompt Engineering
- Anthropic Prompt Guide: https://docs.anthropic.com/claude/reference/prompt-engineering
- OpenAI Best Practices: https://platform.openai.com/docs/guides/prompt-engineering
- Few-shot Learning: Common prompt patterns

### Performance
- Measure token usage per request
- Monitor inference latency
- Track user acceptance rate
- Log error rates

---

## Questions to Ask During Implementation

1. **Intent Classification**: Am I covering all user intents? Am I detecting entity types correctly? What edge cases am I missing?
2. **Context**: Is my context minimal enough? Am I including cross-entity context when needed? Could I remove any fields?
3. **Prompting**: Is my system prompt clear for each entity type? Does Hermes 3 understand the differences between tasks, events, and projects?
4. **Output**: Is JSON always valid with entity_type? What happens when it's not?
5. **Display**: Can a first-time user understand the recommendation? Is the entity-specific UI clear?
6. **User Action**: Is the accept/modify/reject flow intuitive? Are entity-specific modification fields clear?
7. **Backend**: Am I validating all inputs before database writes? Am I checking cross-entity conflicts?
8. **Logging**: Am I capturing enough data to improve the system? Am I tracking metrics per entity type?
9. **Recurring Patterns**: Am I handling recurring_tasks and recurring_events correctly?
10. **Relationships**: Am I respecting entity relationships (tasks in projects, tasks in events)?

---

## Conclusion

This workflow balances **intelligence (LLM reasoning) with control (user approval)** and **speed (lightweight, minimal context) with quality (structured output, transparent reasoning)** across **tasks, events, and projects**.

The key insight: **LLM should augment, not replace, your business logic.**

- Use LLM for what it's good at: understanding context, ranking options, detecting patterns across entity types
- Use rules for what's critical: validation, safety, non-negotiable constraints, cross-entity conflict detection
- Use user for what only they can do: making final decisions, handling exceptions, managing complex relationships

**Multi-Entity Considerations:**
- Each entity type (tasks, events, projects) has unique characteristics and requires tailored handling
- Cross-entity relationships must be respected (tasks in projects, tasks in events, project timelines vs events)
- Recurring patterns add complexity but are essential for real-world use cases
- Entity-specific prompts, validation, and display logic ensure accuracy and user understanding

This creates a **trustworthy, performant, scalable system** that users will actually adopt and benefit from across all their task, event, and project management needs.

Good luck with your thesis! 🚀