## Workspace item visibility ideas

High-level recommendations for how to decide which items to show in the Workspace views, based on patterns from other task apps (Todoist, Things, TickTick, Asana, Microsoft To Do, etc.).

---

## 1. Date-based “smart buckets”

- **Overdue bucket**
  - Show all incomplete items whose effective due/end date is before today in a separate “Overdue” group at the top of Today.
  - This can include tasks and events; visually distinguish them but keep them together so the user sees “what I’m behind on”.

- **Today / Upcoming / Someday views**
  - **Today**: items where the current day is within their active window:
    - Non-recurring: `start <= today <= end` (or open-ended).
    - Recurring: occurrence window overlaps today.
  - **Upcoming**: items grouped by date, for the next N days/weeks.
  - **Someday / No date**:
    - Items with no `start` and `end` at all.
    - Instead of showing them on every day, keep them in a dedicated “No date / Someday” view.

---

## 2. Priority-driven visibility

- **Promote important tasks**
  - In Today, always surface `high` and `urgent` priority tasks at or near the top of their groups (or in a dedicated “Important” group).
  - Optionally allow high/urgent tasks to appear in Today even if they are slightly in the future (e.g. due within the next 1–2 days).

- **Priority filters**
  - Provide quick filters:
    - “Show only High & Urgent”.
    - “Hide Low priority”.
  - Can be per-view toggles or global user preferences.

---

## 3. “My Day” / Focus planning (separate from due dates)

- **Dedicated focus flag or date**
  - Add a `focus_for_date` or `planned_for_today` attribute that is independent from `start_datetime` / `end_datetime`.
  - This allows users to *pull* tasks into a particular day for focus, even if they are not due that day.

- **My Day view**
  - Shows:
    - All incomplete items where `focus_for_date = today`.
    - Optionally also overdue items for visibility.
  - Keeps the distinction:
    - **Due date** = when something must be done.
    - **Focus date** = when the user *plans* to work on it.

---

## 4. Time-of-day and “Now” logic

- **Time-of-day groupings**
  - For items with times (start/end):
    - Group Today’s items into “Morning”, “Afternoon”, “Evening” sections based on their start time.

- **Happening now section**
  - A “Now” section that shows:
    - Events where current time is between `start` and `end`.
    - Time-bound tasks that are currently in progress within their scheduled slot.
  - Useful for a dashboard-like Workspace view.

---

## 5. Recurring / habit-focused behavior

- **Only next occurrence in generic views**
  - For recurring items (especially daily/weekly habits), show only the *next* upcoming occurrence in generic lists (e.g. “All tasks”), to avoid clutter.
  - Show full recurrence patterns only in a calendar or specialty view.

- **Hide after today’s completion**
  - When today’s occurrence of a recurring item is completed:
    - Hide it from Today as soon as it’s marked done.
    - It only reappears on the next occurrence date.

---

## 6. Project-based “Next Actions”

- **One next task per project**
  - For each active project, compute a “next action”:
    - First incomplete, non-blocked task in that project.
  - Show these in a “Next actions” or “Project focus” view.

- **Dependency-aware visibility**
  - If you add task dependencies later:
    - Hide tasks whose prerequisites are not completed from “Next” or “Today” lists by default.
    - Show them only in project detail views or in a “Blocked” group.

---

## 7. Snoozed / “Hide until” logic

- **Snooze / hide until**
  - Add a `hide_until` (or `snoozed_until`) field:
    - While `now < hide_until`, the item is hidden from normal views (Today, Upcoming, etc.).
  - Lets users clear non-urgent items out of their short-term views without changing due dates.

- **Snooze presets**
  - Common options:
    - “Later today”, “Tomorrow”, “Next week”.
    - “Pick date…” for custom.

---

## 8. Collaboration and assignment filters

- **Assignee-based visibility**
  - Smart filters:
    - “Assigned to me”.
    - “Created by me”.
    - “Shared with me” (I’m a collaborator).

- **Per-user inbox**
  - When an item is assigned to a user:
    - It appears in that user’s personal “Inbox” / “Assigned to me” smart list, regardless of date.
  - The user can then move it into Today / Upcoming / Someday views as they plan.

---

## 9. Completion and history

- **Hide completed in normal views**
  - By default, Today / Upcoming only show incomplete items.
  - Add a toggle to “Show completed” for auditing.

- **Recently completed view**
  - Separate “Completed today” or “Activity” view:
    - Items completed in the last N days (e.g. 7 or 30).
  - Helps users feel progress and locate recently finished work.

- **Soft archive for old items**
  - Items whose end date is far in the past (e.g. > 30 days) only show:
    - In search results.
    - In a “History / Archive” view.
  - Keeps main views focused on current and upcoming work.

---

## 10. Tag- and context-based visibility

- **Context filters using tags**
  - Use tags for contexts like `@home`, `@work`, `@errands`, `deep-work`.
  - Provide:
    - “Match any tag” filter (OR).
    - Advanced “match all tags” (AND) for power users.

- **Saved filters / smart lists**
  - Let users save combinations, e.g.:
    - “Today + High priority + @work”.
    - “This week + @deep-work + projects I own”.

