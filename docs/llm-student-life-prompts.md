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

### 1.1 Prioritize CS 220 and MATH 201 work

- **User prompt**

  > I’m overwhelmed. Looking only at my CS 220 and MATH 201 work for the next three days, which tasks should I tackle first and why?

- **Focus**: Smart prioritization (by course + near-term window).

- **Expected behaviour**
  - Filter tasks to:
    - Subject `CS 220 – Data Structures` and `MATH 201 – Discrete Mathematics`.
    - Tasks whose due/end datetimes fall within approximately the next 3 days (relative to “now” in the seeder) **and are not yet completed**.
  - Rank by:
    - Hard deadlines (e.g. quiz date/time, take-home exam submission).
    - Workload (`duration` and `complexity`).
    - Status/priority (e.g. `urgent`/`high` over `medium`/`low`).

- **Expected result (ordered list example)**
  1. **`CS 220 – Project Milestone 2: Dynamic Arrays`**  
     - Complex, high priority, multi-hour deliverable due within the 3–7 day range.
  2. **`MATH 201 – Quiz 3: Graph Theory`**  
     - Time-bound quiz; short duration but high impact; should be prepared for before the quiz window.
  3. **`CS 220 – Lab 5: Linked Lists`**  
     - Complex lab that often precedes quizzes/projects; substantial work within the same window.
  4. **`MATH 201 – Problem Set 4: Relations`**  
     - Significant homework with a near-term deadline but slightly more flexible than the quiz and project milestone.

The explanation should explicitly reference course names, due windows, and why upcoming exams/major milestones are above readings or lighter, more flexible work. Completed exam items like **`MATH 201 – Take-home Exam 1 Submission`** may still appear in the data but should not be scheduled again.

---

### 1.2 Top 5 tasks for today (school-only)

- **User prompt**

  > For today only, what are the top 5 school-related tasks I should focus on? Ignore chores and personal stuff.

- **Focus**: Smart prioritization + domain filtering (school vs life).

- **Expected behaviour**
  - Restrict to:
    - Tasks with course `subject_name` (e.g. `ITCS 101 – Intro to Programming`, `MATH 201 – Discrete Mathematics`, `CS 220 – Data Structures`, `ENG 105 – Academic Writing`, `ITEL 210 – Web Development`) and manual student tasks like:
      - `Library research for history essay`
      - `Group project planning slides`
      - `Practice coding interview problems`
    - Exclude chores (`Wash dishes after dinner`, `Walk 10k steps`, etc.) and household/health-only items.
  - Rank by:
    - Imminent deadlines (today/overdue).
    - Priority (urgent/high first).
    - Only include tasks that are not completed.

- **Expected result (example set)**
  - A list containing 5 items drawn from:
    - `ITCS 101 – Programming Exercise: Functions`
    - `CS 220 – Lab 5: Linked Lists`
    - `ENG 105 – Draft 2: Comparative Essay`
    - `Library research for history essay`
    - `Practice coding interview problems` or `ITEL 210 – Lab 2: Flexbox Layout`
  - Each line should mention why it’s above others (e.g. “due tonight”, “exam-related”, “feeds into project milestone”).

---

### 1.3 Prioritize by tag: Exam

- **User prompt**

  > Look at everything tagged as “Exam” and prioritize it from most to least urgent.

- **Focus**: Tag-based prioritization.

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
  - Work within a 4-hour window; choose tasks with durations that can fit ~3 hours total:
    - For example: a 60–90 minute block on `MATH 201 – Problem Set 4: Relations`, a 60-minute block on `Practice coding interview problems`, and a 30–45 minute review (`Review today’s lecture notes`).
  - Insert at least one explicit break (e.g. 15–30 minutes).
  - Avoid stuffing in the entire `Impossible 5h study block`.

- **Expected result (example schedule)**
  - 7:00–8:15pm – Work on `MATH 201 – Problem Set 4: Relations`.  
  - 8:15–8:30pm – Break.  
  - 8:30–9:30pm – `Practice coding interview problems`.  
  - 9:30–10:00pm – `Review today’s lecture notes`.  
  - 10:00–11:00pm – Free / light reading / buffer.  
  - The assistant mentions that the 5h study block can’t reasonably fit and is omitted or split to another day.

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

