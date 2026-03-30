# Tasklyst — Dashboard 

## Quick checklist

Use this as your checklist. Everything below should appear on the dashboard UI. **Section order and content** are what this doc defines; how you arrange, size, or adapt the layout is up to you.

- [ ] **Dashboard** — main screen / layout
- [ ] **Header** — greeting area (see §1)
- [ ] **Needs attention** — alerts / overdue / due soon
- [ ] **Today — tasks** list (see §3)
- [ ] **Today / next up — events** (see §4)
- [ ] **Recurring & daily tasks** (see §5)
- [ ] **Motivation snippets** — small stats card / strip (see §6)
- [ ] **Upcoming / this week** — next-7-days tasks & events (see §7)
- [ ] **Task row** component — for Today + alerts + recurring + upcoming rows
- [ ] **Event row** component
- [ ] **Empty states** — for each list in §1–§7 (including §6–§7 specifics)
- [ ] **Loading** — placeholders for main lists and blocks
- [ ] **Workspace** — clear way to open full app

---

## Terms

|------|-------------------------|
| **Task** | Something to do (assignment, reading, etc.). Can have a due date, priority, course name, tags. |
| **Event** | Something on the calendar (class, exam, meeting). Has start/end time or “all day.” |
| **Project** | A bucket for related tasks (e.g. “Final project — CS 101”). |
| **Tag** | Small labels users add (e.g. `exam`, `lab`). Shown as **chips** next to tasks/events. |
| **Recurring task** | A task that **repeats** on a schedule (daily, weekly, monthly, yearly). The app stores a **pattern** (e.g. every day, every Monday) on the recurring record; the **title** and course info still live on the main **task**. |
| **Motivation snippets** | Tiny **encouraging stats** on the dashboard (e.g. focus time today, simple week progress). **Not** full analytics—keep one small area, supportive tone. |
| **Upcoming / this week** | **Next ~7 days** of deadlines and calendar items so students see what’s coming after today. |

Students also have **subject** and **teacher** on tasks, and some tasks may come from **Brightspace** (show as a small badge or icon if you want).

---

## Page layout — top to bottom

Design the page in **this order** so the most important content comes first.

Section titles use **(required)** and match the checklist. Everything in this layout should appear on the dashboard.

### 1. Header (required)

**Required** — Anchor the page after login; users always see who they are.

- Greeting using the user’s **name**
- Optional: **profile photo**
- Optional: today’s **date** (e.g. “Monday, March 30”)

### 2. Needs attention (required)

**Required** — Surfaces risk first (overdue, due soon, urgent) so nothing critical is buried.

Short, impossible to miss. Examples:

- **Overdue** tasks (past due, not done)
- **Due very soon** (e.g. tomorrow)
- **Urgent / important** tasks even if the due date is missing

Each alert should show at least: **title**, **when it’s due** (or “No date”), and **course/subject** or **project name** if available. **Priority** can be a badge (urgent / high / medium / low).

### 3. Today — tasks (required)

**Required** — Main “what to do today” list; core of the dashboard.

Primary list of tasks the student should deal with **today**.

Each **task row** should support:

- **Title** (main text)
- **Due** (date/time) and optionally **start** time if you show time blocks
- **Status:** To do · Doing · Done (you can hide “done” on the dashboard or tuck it away)
- **Priority** badge
- Optional: **how long it might take** (minutes) and **complexity** (simple / moderate / complex)
- **Course** and **teacher** lines (great for students)
- **Project** or **linked event** as secondary text
- **Tags** as chips
- Optional: tiny **Brightspace** (or “from LMS”) indicator + optional **link out** icon

If the task **repeats**, show a clear **repeats** icon or label on the row (users should recognize it’s not a one-off).

### 4. Today / next up — events (required)

**Required** — Today’s schedule (classes, exams, blocks) so time-bound work is visible next to tasks.

List or timeline of **today’s** calendar items.

Each **event row**:

- **Title**
- **Time range** or **All day**
- Skip or gray out **cancelled** / **finished** events if it looks cleaner

Optional: merge tasks and events into **one timeline** by time (advanced layout).

### 5. Recurring & daily tasks (required)

**Required** — Repeating habits and routines must be visible, not only one-off deadlines.

Tasklyst supports **repeating tasks** (daily, weekly, monthly, yearly). Students use these for habits and fixed routines (e.g. readings, labs, review sessions). This block is **not optional** on the dashboard: it makes repeating work visible alongside one-off deadlines.

**Purpose:** Quick scan of “what comes back every day / week” without digging into the full workspace.

**What to show (each row or card):**

- **Title** (and same secondary lines as a normal task when helpful: **course**, **teacher**, **project**, **tags**)
- **Repeat rule** in plain language, e.g. **Daily**, **Every week**, **Every 2 weeks**, **Monthly**, **Yearly**
- For **weekly** patterns: which **days** (e.g. Mon, Wed, Fri) when the product stores them
- Optional: **next occurrence** or “due today” if developers surface it — nice for motivation
- Same **priority** / **status** treatment as other task rows if you want consistency
- Clear **empty state** if the user has no recurring tasks (short explanation + link to workspace to create one)

Place this section **after** “Today — tasks” and **today’s events**, so repeating routines sit near daily work.

### 6. Motivation snippets (required)

**Required** — A **small**, **positive** snapshot so students feel progress without turning the dashboard into a reports page.

**Purpose:** Reward effort (focus time) and closure (tasks finished)—not shame for empty data.

Place **after** §5 (recurring) so **alerts → today → events → routines** stay the priority; motivation sits as a light “you’ve got this” band before **§7 upcoming**.

#### Checklist — include on the dashboard

- [ ] **Focus today** — One line or number (e.g. “**45 min** focused today” or “**0 min** today — start when you’re ready”). Uses completed **work** focus sessions summed for **today** (developers aggregate `FocusSession`).
- [ ] **Week progress** — One simple score (e.g. “**4 / 10** tasks done this week” or a small **ring / bar**). Keep **one** visual metaphor; no multi-chart layouts.
- [ ] **Supportive empty/zero** — If focus is 0 and progress is 0, use **kind** copy (not “You failed”). Offer a gentle nudge (e.g. open **Workspace**), not guilt.
- [ ] **Compact footprint** — **One card** or **one horizontal strip**; must not overpower §2–§5.

#### Guidelines (do / don’t)

- **Do** keep copy short, scannable, and aligned with **student** stress (calm colors okay; avoid alarmist red for stats).
- **Do** use the same **typography scale** as the rest of the dashboard so it feels native.
- **Don’t** add **full analytics** here (trends, filters, deep history)—that belongs on a **separate Insights/Stats page** later if you build it.
- **Don’t** use more than **two** stat blocks in this required area (focus today + week progress is enough).

### 7. Upcoming / this week (required)

**Required** — Horizon after **today**: deadlines and calendar items in the **next 7 days** (or “this week,” same window—align copy with whatever developers query).

**Purpose:** Students see what’s landing soon without opening the full workspace.

#### Checklist — include on the dashboard

- [ ] Mix of **tasks** (due in window, not done) and **events** (start in window), or **two sub-lists** (Upcoming tasks / Upcoming events)—pick one pattern and stay consistent.
- [ ] Each row **minimal**: **title**, **day + time** (or “All day” for events), **course** (`subject_name`) or **project** name when relevant.
- [ ] **Empty state** **(required)** — e.g. “Nothing due this week” / “No upcoming events” with calm copy (and optional CTA to **Workspace**).
- [ ] **Loading** **(required)** — skeleton rows or placeholder for this block.

**Guidelines:** Keep it **scannable** (no essay text). **Don’t** duplicate the full **Today** lists—this block is only **after today** through the end of the window.

---

## Badges and labels (for consistent UI)

Use the same labels everywhere on **required** task/event rows (§2–§5).

**Required** (plan for these in components):

**Priority:** Urgent · High · Medium · Low  

**Status:** To do · Doing · Done  

**Repeating tasks — rule type:** Daily · Weekly · Monthly · Yearly (often with **interval**, e.g. “Every 2 weeks”).

**Secondary** (use when the row shows these fields):

**Complexity:** Simple · Moderate · Complex  

**Events — status:** Scheduled · Cancelled · Completed · Tentative · Ongoing  

---

## Empty & loading

**Required** — Design empty and loading states for every list in §1–§7 (Today’s tasks, Today’s events, Recurring & daily, alerts if the strip is empty or “all clear”, **§6 motivation**, **§7 upcoming**). **§6** and **§7** also list their own checklists for zero-data and loading—cover those too.

- **Nothing due today** **(required)** — friendly message + button like **Go to workspace** or **Add task**
- **No events today** **(required)** — short friendly line (optional CTA)
- **No recurring tasks** **(required)** — short line that repeating tasks will show here once added + CTA to **Workspace**
- **Alerts / all clear** **(required)** — either show nothing, or a calm “You’re on track” state when there are no overdue or urgent items (pick one approach and stay consistent)
- **Loading** **(required)** — skeleton rows or simple spinners for **required** lists

**Motivation snippets (§6) — required**

- [ ] **Zero focus today** **(required)** — kind line + optional CTA to start focus in **Workspace**
- [ ] **Week progress at 0 / 0** **(required)** — neutral or encouraging; avoid shaming
- [ ] **Loading** **(required)** — short placeholder for the stat numbers (skeleton line or shimmer)

---

## Layout tips

1. **Primary focus** — **Today’s tasks** is usually the main anchor; keep **events** visually tied to that flow (how you arrange them is your call).
2. **Alerts first** — Overdue and “due soon” should be easy to see without scrolling.
3. **Don’t rely only on red** — Clear wording matters for stress-free UX.
4. **Hierarchy** — Keep **alerts**, **today’s tasks**, and **recurring** high in the page order; don’t bury **Recurring & daily** where students won’t notice it.
5. **Motivation then horizon** — **§6** stays compact; **§7 upcoming** gives the **next 7 days** without replacing **Today**.
6. **Match the rest of Tasklyst** — Priority, status, and tags should **look like** the Workspace so it feels like one product.
7. **Recurring vs one-off** — The **Recurring & daily** section should read as “these always come back”; use consistent **repeats** visuals on both this block and any repeating rows in **Today**.

---

## Data reference — models & properties

Only what this page needs. **Eloquent model** names are in `backticks`; **columns** are database names (code sometimes uses camelCase, e.g. `end_datetime` → `endDatetime`).

All subsections below back **§1–§7**. **§6** uses **aggregates** (sums/counts). **§7** uses **tasks** and **events** filtered by date window (same columns as §3–§4 rows).

### `User` (required)

| Property     | Notes              |
|-------------|--------------------|
| `name`      | Greeting           |
| `avatar`    | Optional photo     |

### `Task` (required)

| Property          | Notes                                      |
|-------------------|--------------------------------------------|
| `title`           | Main label                                 |
| `status`          | `to_do`, `doing`, `done`                   |
| `priority`        | `low`, `medium`, `high`, `urgent`          |
| `complexity`      | `simple`, `moderate`, `complex`            |
| `duration`        | Minutes (estimate)                         |
| `start_datetime`  | Optional start / time block                |
| `end_datetime`    | Due date/time; filter range for **§7** upcoming |
| `completed_at`    | `null` = not done; used for **§6** week progress and **§7** “not done” |
| `subject_name`    | Course / subject line                      |
| `teacher_name`    | Instructor                                 |
| `source_type`     | e.g. `manual`, `brightspace`               |
| `source_url`      | Optional LMS link                          |

**Related (for labels on rows):** `project` → `name`; `event` → `title`; `tags` → each tag’s `name`.

**Recurring:** `recurringTask` → see **`RecurringTask`** below (pattern for dashboard “Recurring & daily” section and **repeats** on rows).

### `RecurringTask` (required)

Belongs to one **Task** (`task_id`). Use the **task** for title, course, tags, etc.; use **recurring_task** for how often it repeats.

| Property           | Notes |
|--------------------|--------|
| `task_id`          | Parent task |
| `recurrence_type`  | `daily`, `weekly`, `monthly`, `yearly` |
| `interval`         | e.g. `2` = every 2 weeks (with `weekly`) |
| `start_datetime`   | Optional start of recurrence window |
| `end_datetime`     | Optional end of recurrence window |
| `days_of_week`     | For **weekly**: stored as JSON list of weekday indices (`0` = Sunday … `6` = Saturday) — show as Mon, Tue, … in UI |

### `Event` (required)

| Property          | Notes                                      |
|-------------------|--------------------------------------------|
| `title`           | Main label                                 |
| `start_datetime`  | Start                                      |
| `end_datetime`    | End                                        |
| `all_day`         | true = show “All day”                      |
| `status`          | e.g. `scheduled`, `cancelled`, `completed` |

**Related:** `tags` → `name` (chips). Same columns power **§4** and **§7** event rows.

### `Tag` (required)

Used on task/event rows (chips), including **§7** when tags are shown.

| Property | Notes     |
|----------|-----------|
| `name`   | Chip text |

Tasks and events link to tags via the app’s tagging relation (many-to-many).

### `FocusSession` (required — §6)

| Property           | Notes                                      |
|--------------------|--------------------------------------------|
| `type`             | `work`, `short_break`, `long_break` — **§6** sums **work** sessions for “today” |
| `duration_seconds` | Add up for **minutes focused today** (completed work sessions) |
| `completed`        | Session finished                           |
| `started_at`       | Filter “today”                             |
| `ended_at`         | In progress if null (and not completed)    |

**Related:** `focusable` points at the **task** being focused.

### `PomodoroSetting` (§6 sample copy)

| Property                 | Notes              |
|--------------------------|--------------------|
| `work_duration_minutes`  | Optional sample copy (e.g. “25 min” blocks) in §6 |

---

Full system schema (recurrence, comments, etc.): [`docs/task-management-models-and-schema.md`](./task-management-models-and-schema.md).
