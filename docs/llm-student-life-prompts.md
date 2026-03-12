## Student Life LLM Test Prompts

These prompts are designed to manually test Hermes 3 (3B) against the **`StudentLifeSampleSeeder`** data for user `andrew.juan.cvt@eac.edu.ph`.

Assumptions:

- Database has been seeded via `StudentLifeSampleSeeder`.
- You are testing in the workspace view that surfaces:
  - Brightspace-style tasks (20 items under courses like ITCS 101, MATH 201, CS 220, ENG 105, ITEL 210).
  - Manual student tasks, daily recurring chores, projects, events, and tags created by the seeder.

Each example below includes:

- **User prompt**: What you type into the assistant.
- **Focus**: Capability under test.
- **Expected behaviour**: How the LLM should reason.
- **Expected result**: Concrete, seed-data-based outcome.

---

## 1. Smart prioritization

### 1.1 Prioritize CS 220 and MATH 201 work ✅

- **User prompt**

  > I’m overwhelmed. Looking only at my CS 220 and MATH 201 work for the next three days, which tasks should I tackle first and why?

- **Focus**: Smart prioritization (course filter + “next three days” window) with strict due-date correctness.

- **Expected behaviour**
  - Filter tasks to:
    - Subject `CS 220 – Data Structures` and `MATH 201 – Discrete Mathematics`.
    - Tasks whose `end_datetime` falls within approximately the next 3 days (relative to “now” in the app) **and are not yet completed**.
  - Rank tasks in a reasonable way using:
    - Deadlines (earlier due first when otherwise comparable).
    - Workload (`duration` and `complexity`) so long/complex tasks due soon are not left until the last minute.
    - Priority and status.
  - Produce a **complete** ranked list for the filtered slice (not “top 2” by accident).
  - Ensure the response is **factually accurate** about due dates/times (no AM/PM drift).

- **Expected result (seed-data-based)**
  - The ranked list should include exactly these 3 tasks (titles must match exactly):
    1. **`CS 220 – Lab 5: Linked Lists`** — due **Fri, Mar 13, 2026 11:59 PM**
    2. **`MATH 201 – Problem Set 4: Relations`** — due **Fri, Mar 13, 2026 11:00 PM**
    3. **`MATH 201 – Quiz 3: Graph Theory`** — due **Sat, Mar 14, 2026 10:00 AM**

  The explanation should reference why the top item is #1 (deadline + complexity/duration + priority), and it must not invent different due times (for example, the problem set is **11:00 PM**, not 11:00 AM).

- **Implementation notes (bug fix summary)**
  - **Context constraints (course + window)**:
    - `LlmContextConstraintService` detects `CS 220`, `MATH 201`, and “next three days” and constrains the context query accordingly.
  - **Context “completeness” guarantee (prevents “only 2 ranked tasks”)**:
    - For single-entity prioritize intents, when the user does **not** request “top N”, `ContextBuilder` now sets `requested_top_n` to the size of the context slice so the model must return **every** item in `ranked_tasks`.
  - **Context signals (reduce guessing)**:
    - Tasks now include `is_assessment` in prioritize context so the model doesn’t have to infer quizzes/exams from title wording.
  - **Prompt consistency rule (prevents narrative/rank mismatch)**:
    - `PrioritizeTasksPrompt` includes a hard rule: `recommended_action` must explicitly recommend the same task as `ranked_tasks[0]` (rank #1), using the exact title string.
  - **Structured output canonicalization (prevents time drift)**:
    - `StructuredOutputSanitizer` canonicalizes `ranked_tasks[*].end_datetime` from the context slice so the LLM can’t accidentally shift times (AM/PM/timezone mistakes).
  - **Narrative date binding (fixes wrong wording while keeping ordering)**:
    - `RecommendationDisplayBuilder` applies narrow narrative corrections when context facts are available, binding relative phrases (like “tomorrow”) and ambiguous time phrases to the canonical due datetime—without changing which tasks were ranked.

---

### 1.2 Top 5 tasks for today (school-only) ✅

- **User prompt**

  > For today only, what are the top 5 school-related tasks I should focus on? Ignore chores and personal stuff.

- **Focus**: Smart prioritization + domain filtering (school vs life) **with hard “top 5” enforcement.**

- **Expected behaviour**
  - Restrict to:
    - Tasks with course `subject_name` (e.g. `ITCS 101 – Intro to Programming`, `MATH 201 – Discrete Mathematics`, `CS 220 – Data Structures`, `ENG 105 – Academic Writing`, `ITEL 210 – Web Development`) and manual student tasks that are clearly school work (e.g. research, project prep, coding practice).
    - Exclude chores / life tasks, especially those tagged `Health` / `Household` (e.g. `Wash dishes after dinner`, `Walk 10k steps`, `Prepare tomorrow’s school bag`).
  - Rank by:
    - Imminent deadlines (today + overdue first, then near-term).
    - Priority (urgent/high first).
    - Only include tasks that are not completed.
  - **Always return 5 ranked tasks** when at least 5 school tasks exist in the constrained context slice.

- **Expected result (seed-data-based for StudentLife user)**

  A ranked list of **exactly 5** school tasks taken from the seed data, for example:

  1. `MATH 201 – Problem Set 4: Relations` — due **Fri, Mar 13, 2026 11:00 PM**  
  2. `CS 220 – Lab 5: Linked Lists` — due **Fri, Mar 13, 2026 11:59 PM**  
  3. `MATH 201 – Quiz 3: Graph Theory` — due **Sat, Mar 14, 2026 10:00 AM**  
  4. `ENG 105 – Reading Response #3` — due **Sat, Mar 14, 2026 10:00 PM**  
  5. `ITCS 101 – Midterm Project Checkpoint` — due **Sat, Mar 14, 2026 11:59 PM**  

  All due dates/times must match the canonical task records (no AM/PM drift or day-of-week mistakes). The explanation should mention **why** each is in the top 5 (assessment vs prep, proximity of deadlines, contribution to bigger projects), without inventing new tasks.

- **Bugs encountered during manual testing**

  - **Bug 1: “Top 5” prompt returning only 2 items**
    - Symptom: For the exact 1.2 prompt, the assistant replied with only:
      - `MATH 201 – Problem Set 4: Relations`
      - `CS 220 – Lab 5: Linked Lists`  
      even though many more school tasks existed and the user explicitly asked for “top 5”.
    - Root cause:
      - `ContextBuilder` correctly:
        - Classified intent as `prioritize_tasks` / `task`.
        - Built a school-only task slice of 12 items for the StudentLife user.
        - Parsed “top 5” and set `requested_top_n = 5`.
      - When enforcing the token cap, `ContextBuilder::enforceTokenAwareness()` shrank the payload to a minimal payload and **dropped `requested_top_n` and `requested_top_n_instruction`**.
      - `StructuredOutputSanitizer::sanitizeRankedTasks()` only enforces “fill up to N” when `requested_top_n` is present, so with that key missing it let the 2-item list through.

  - **Bug 2: Sanitizer didn’t enforce top N even when possible**
    - Symptom: Even when the context slice contained enough tasks (≥ 5) and `requested_top_n` had been set earlier, the final `ranked_tasks` could still have fewer items than requested.
    - Root cause:
      - Original `StructuredOutputSanitizer` for `PrioritizeTasks`:
        - Filtered `ranked_tasks` to titles in context.
        - Deduped by title.
        - Canonicalized `end_datetime`.
        - **Never added missing items**; it trusted the model to obey the prompt’s “you MUST return requested_top_n items”.

- **Fixes implemented for 1.2 (and all single-entity prioritize intents)**

  - **Fix 1: Preserve `requested_top_n` through token shrinking**
    - In `ContextBuilder::enforceTokenAwareness()`:
      - When building the minimal payload under the token cap, we now **keep `requested_top_n` and `requested_top_n_instruction`** alongside core keys like `current_time`, `tasks`, etc.
      - This ensures every downstream layer still knows the user requested “top 5”.

  - **Fix 2: Hard “fill up to N” logic for tasks**
    - In `StructuredOutputSanitizer::sanitizeRankedTasks()`:
      - Read `requested_top_n` from context.
      - Compute `available = min(requested_top_n, count(context.tasks))`.
      - After filter + dedupe, if `count(filtered) < available`:
        - Collect titles already ranked.
        - Iterate the context `tasks` slice in order and **append extra items** (canonical `title` + `end_datetime`) for tasks not yet ranked.
        - Stop once `filtered` reaches `available`.
      - Re-run `rerank()` so ranks are 1..N in sequence.
    - Effect: for 1.2, if there are at least 5 school tasks, `ranked_tasks` in the final snapshot will **always** contain 5 items, even if the raw LLM only produced 2–3.

  - **Fix 3: Extend top‑N enforcement to events and projects for consistency**
    - `sanitizeRankedEvents()` and `sanitizeRankedProjects()` now mirror the same pattern:
      - Honor `requested_top_n` when present.
      - Backfill `ranked_events` / `ranked_projects` from their context slices when the model under‑returns.
    - Multi‑entity prioritize intents (`PrioritizeTasksAndEvents`, etc.) still rely on per-entity limits and do not currently enforce a single cross-entity `requested_top_n`, which is fine for the current 1.x StudentLife prompts.

- **Post‑fix behaviour for 1.2 (verified)**

  - For `andrew.juan.cvt@eac.edu.ph` seeded by `StudentLifeSampleSeeder` on 2026‑03‑11:
    - `BuildLlmContextAction` for this prompt now yields:
      - `requested_top_n = 5`
      - `tasks` slice of 12 school tasks (no chores).
    - After inference + sanitization, the stored `recommendation_snapshot` shows:
      - `intent = prioritize_tasks`, `entity_type = task`.
      - `structured.ranked_tasks` with exactly these 5 items and ISO datetimes:
        - `MATH 201 – Problem Set 4: Relations` — `2026-03-13T23:00:00+08:00`
        - `CS 220 – Lab 5: Linked Lists` — `2026-03-13T23:59:00+08:00`
        - `MATH 201 – Quiz 3: Graph Theory` — `2026-03-14T10:00:00+08:00`
        - `ENG 105 – Reading Response #3` — `2026-03-14T22:00:00+08:00`
        - `ITCS 101 – Midterm Project Checkpoint` — `2026-03-14T23:59:00+08:00`
      - All `end_datetime` values are canonicalized from the context / DB.

  - The UI now correctly shows a **top 5, school-only** list with factually accurate due dates, matching this spec.

---

### 1.3 Prioritize by tag: Exam ✅

- **User prompt**

  > Look at everything tagged as “Exam” and prioritize it from most to least urgent.

- **Focus**: Tag-based prioritization.

- **Bugs encountered (and fixes) (verified )**

  - **Bug: Mis-classification to `prioritize_all` produced non‑Exam narrative + empty rankings**
    - **Symptom**: Response came back as `intent = prioritize_all`, with a narrative recommending non‑Exam school tasks (e.g. `MATH 201 – Problem Set 4: Relations`, `CS 220 – Lab 5: Linked Lists`) while `structured.ranked_tasks/events/projects` were all `[]`.
    - **Root cause**:
      - The model pulled items from conversation history instead of the **tag-filtered** context.
      - Sanitization correctly stripped hallucinated items from `ranked_*`, but intent validation still passed because `recommended_action`/`reasoning` were non‑empty.
    - **Fix**:
      - Hardened prioritize prompts (especially `PrioritizeAllPrompt`) to forbid referencing items not present in the current Context arrays.
      - Added a backend guard for `PrioritizeAll` so if Context has items but all `ranked_*` arrays are empty, we deterministically rank from Context instead of returning an empty list.

  - **Bug: Tag filtering only worked via the special-cased word “exam”**
    - **Symptom**: Only messages containing “exam” reliably triggered `requiredTagNames = ['Exam']`; generic phrasing like `tagged as "Homework"` was not consistently interpreted.
    - **Fix**: Extended `LlmContextConstraintService` to parse quoted tags from “tagged as …”/“with the … tag” phrasing and add them to `requiredTagNames`, so tag filtering works generically for tasks/events.

  - **Bug: Narrative text could mention non-context items even when ranked lists were correct**
    - **Symptom**: `ranked_tasks` was correct (Exam-only), but the narrative still referenced unrelated projects/tasks (e.g. `CS 220 Final Project`, `ENG 105 Comparative Essay`) that were not in the filtered context.
    - **Fix**:
      - Added consistent “do not mention anything outside Context” guardrails across all prioritize prompt templates.
      - Appended an explicit runtime guidance line to prioritize prompts at inference time: only mention items present in Context arrays.

  - **Bug: Hallucinated `id` field inside `ranked_tasks` (e.g. `id => 0`)**
    - **Symptom**: Some LLM responses included a bogus `id` key in ranked items even though prioritize context doesn’t provide IDs.
    - **Fix**: In `StructuredOutputSanitizer` for `PrioritizeAll`, strip any `id` fields from `ranked_tasks` items during sanitization.

- **Expected behaviour**
  - Filter to tasks/events with the `Exam` tag:
    - Tasks like `ITCS 101 – Quiz 2: Conditions`, `MATH 201 – Quiz 3: Graph Theory`, `MATH 201 – Take-home Exam 1 Submission`.
    - Event `Math exam review session`.
  - Order by proximity of due/start time; treat the review session as supporting work around the exam window.
  - Clearly distinguish between **completed** exam items (e.g. the take-home exam and possibly the ITCS quiz) and upcoming ones; when prioritizing what to do next, focus on the upcoming, incomplete work.

- **Expected result**
  - A ranked list where the take-home exam and imminent quiz come first, followed by the review session.
  - The assistant explicitly notes which are tasks vs events, and references the tag.

---

## 2. Smart scheduling

### 2.1 Plan tonight’s evening block

- **User prompt**

  > From 7pm to 11pm tonight, create a realistic plan using my existing tasks. Include at least one break and don’t schedule more than 3 hours of focused work.

- **Focus**: Time-block scheduling + respecting duration + load limits.

- **Expected behaviour**
  - Work within a 4-hour window and respect the user’s focused-work cap.
  - The suggestion should be **optimal and realistic**, not “maximal”.
    - If the user caps focused work at ~3 hours, the plan should usually include only **~3–4 tasks** (plus breaks/buffers), depending on the tasks’ durations.
    - If the user gives fewer/more hours, the number of tasks and block lengths should flex accordingly.
  - Insert at least one explicit break (e.g. 15–30 minutes).
  - Avoid stuffing in the entire `Impossible 5h study block`.

- **Expected result (example schedule)**

---

### 2.2 Spread project work across 5 days

- **User prompt**

  > Spread out my CS 220 Final Project work and ENG 105 drafts across the next 5 days, avoiding times when I already have quizzes or the math review session.

- **Focus**: Scheduling multi-day + project awareness + conflict avoidance.

- **Expected behaviour**
  - Use tasks linked to:
    - Project `CS 220 Final Project` (CS 220 tasks).
    - Project `ENG 105 Comparative Essay` (ENG 105 Draft 1/2, Reading Response).
  - Respect events:
    - Avoid clashing with `MATH 201 – Quiz 3: Graph Theory` window and `Math exam review session`.
  - Distribute effort:
    - Shorter sessions (1–2h) per day for project/document work rather than one giant block.

- **Expected result**
  - A 5-day table or bullet list where each day has:
    - A CS 220 project-related task chunk (e.g. dynamic arrays milestone, lab work).
    - An ENG 105-related task chunk (e.g. revise Draft 2).
    - Explicit notes like “avoid 16:00–18:00 due to Math exam review session”.

---

### 2.3 Schedule exam prep from exam-tagged items

- **User prompt**

  > Using only exam-related tasks and events, create a study schedule for the next 3 days that gets me ready without cramming all on the last day.

- **Focus**: Scheduling within a filtered subset (tag = Exam).

- **Expected behaviour**
  - Pick exam-tagged tasks/events:
    - `MATH 201 – Quiz 3: Graph Theory`
    - `Math exam review session`
    - Optionally mention that `ITCS 101 – Quiz 2: Conditions` and `MATH 201 – Take-home Exam 1 Submission` exist in the data but are already completed and should **not** be scheduled again.
  - Spread preparation time before each exam:
    - Allocate problem-solving practice and reading on earlier days.
    - Use the review session as part of the plan, not the only prep.

- **Expected result**
  - A 3-day schedule that explicitly names these tasks/events and explains sequencing (e.g. “Day 1: review graph theory notes; Day 2: attend the math exam review session; Day 3: light quiz-style warm-up problems”), without trying to reschedule already-completed exams.

---

## 3. Filtering and searching

### 3.1 Exam-related items this week

- **User prompt**

  > Show only my exam-related tasks and events for this week.

- **Focus**: Filtering by tag + time window.

- **Expected behaviour**
  - Equivalent to:
    - `itemType = tasks + events`
    - Tag filter `Exam`
    - Date filter ≈ “this week” from now.

- **Expected result**
  - A list that includes:
    - `ITCS 101 – Quiz 2: Conditions`
    - `MATH 201 – Quiz 3: Graph Theory`
    - `MATH 201 – Take-home Exam 1 Submission`
    - `Math exam review session`
  - It is acceptable (and expected, given the seed data) that some of these may already be completed; the assistant should surface that status rather than treating them as new work.
  - And excludes:
    - Non-exam tasks (labs, readings, chores, CV updates, etc.).

---

### 3.2 Health and household tasks

- **User prompt**

  > List all tasks related to health or household chores.

- **Focus**: Tag-based filtering.

- **Expected behaviour**
  - Equivalent to:
    - Tag filter in {`Health`, `Household`}.

- **Expected result**
  - `Walk 10k steps` (Health).  
  - `Wash dishes after dinner` (Household).  
  - `Prepare tomorrow’s school bag` (Household).  
  - Possibly other chores if you later tag them; must *not* include academic tasks or events.

---

### 3.3 Events-only upcoming view

- **User prompt**

  > Filter to events only and show what’s coming up in the next 7 days.

- **Focus**: Item type filter (events) + date window.

- **Expected behaviour**
  - Equivalent to:
    - `itemType = events`
    - Date filter ≈ next 7 days.
  - Include both one-time events and any expanded recurring club events within that window.

- **Expected result**
  - At least:
    - `Math exam review session`
    - `CS group project meetup`
    - `Campus club orientation night` (plus any weekly recurrences that land in the 7-day window).
  - Exclude all tasks and projects.

---

## 4. Multi-turn workflows

### 4.1 School-only → schedule

- **Turn 1 – User**

  > List my top 5 tasks for today that are school-related, not chores.

- **Expected behaviour**
  - Similar to prompt 1.2:
    - Filter out chores (`Health`/`Household` tags, recurring chores).
    - Prioritize school tasks due today/overdue.

- **Expected result**
  - A ranked list of 5 tasks like:
    - `MATH 201 – Take-home Exam 1 Submission`
    - `ITCS 101 – Programming Exercise: Functions`
    - `CS 220 – Lab 5: Linked Lists`
    - `ENG 105 – Draft 2: Comparative Essay`
    - `Library research for history essay`

- **Turn 2 – User**

  > Okay, schedule those across tonight and tomorrow evening.

- **Expected behaviour**
  - Use only the tasks just listed (no new ones).
  - Split them across “tonight” and “tomorrow evening” time blocks, respecting duration and not overloading any one night.

- **Expected result**
  - A two-evening schedule that references exactly those 5 titles and briefly explains why certain tasks were put on which day (e.g. exam-related vs flexible).

---

### 4.2 Exam list → study plan

- **Turn 1 – User**

  > List everything that looks like an exam or quiz.

- **Expected behaviour**
  - Return exam/quiz tasks/events:
    - `ITCS 101 – Quiz 2: Conditions`
    - `MATH 201 – Quiz 3: Graph Theory`
    - `MATH 201 – Take-home Exam 1 Submission`
    - `Math exam review session`

- **Turn 2 – User**

  > Using those, create a 3-day study plan that balances time between ITCS 101 and MATH 201.

- **Expected behaviour**
  - Reference only the previously returned items.
  - Allocate study/quiz prep blocks for ITCS 101 and MATH 201 on each of the 3 days, trying to keep load balanced.

- **Expected result**
  - A 3-day outline where each day references specific tasks by title and mentions which course they are for, with approximate time allocations.

---

## 5. Edge-case and stress-test prompts

### 5.1 Try to pack all urgent/high work into tonight

- **User prompt**

  > Can you fit all of my urgent and high-priority tasks into tonight before midnight? Be honest if that’s impossible.

- **Focus**: Feasibility judgement under overload.

- **Expected behaviour**
  - Identify tasks with `priority` in {`urgent`, `high`}:
    - Examples: `MATH 201 – Take-home Exam 1 Submission`, `ITCS 101 – Midterm Project Checkpoint`, `CS 220 – Lab 5: Linked Lists`, `CS 220 – Project Milestone 2: Dynamic Arrays`, `ENG 105 – Draft 2: Comparative Essay`, `Practice coding interview problems`, the `Impossible 5h study block`, etc.
  - Sum durations vs available time tonight (~a few hours).
  - Conclude that not all can fit, especially the 5h block.

- **Expected result**
  - Assistant explicitly calls out:
    - `Impossible 5h study block before quiz` as infeasible within tonight’s window.
  - Provides:
    - A smaller subset of urgent/high tasks that could reasonably be attempted tonight.
    - A suggestion to move some work (e.g. project or CV updates) to tomorrow or later in the week.

---

### 5.2 Realistically doable in next 24 hours

- **User prompt**

  > Look at everything due in the next 24 hours and tell me what is realistically doable, given the estimated durations.

- **Focus**: Due-date filtering + feasibility.

- **Expected behaviour**
  - Filter tasks/events whose `end_datetime` falls within the next ~24 hours.
  - Compare sum of their durations to a plausible available-time budget (e.g. 4–6 hours).
  - Identify:
    - Which subset of tasks can fit.
    - Which must be deferred or at best partially completed.

- **Expected result**
  - A breakdown like:
    - “Realistically doable”: list of 3–4 tasks totaling a reasonable number of hours.
    - “Risky or unlikely to finish”: explicitly highlight the 5h impossible block and any other large deliverables that don’t fit.
  - Short explanation of trade-offs (e.g. “finish exam submission and one project piece, but leave CV update for later”).

---

These examples should give you a high-signal manual test set for Hermes 3 on top of the `StudentLifeSampleSeeder` data, covering prioritization, scheduling, filtering/searching, multi-turn context, and feasibility reasoning. 

