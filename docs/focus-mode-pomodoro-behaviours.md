# Focus Mode (Pomodoro) – Required Behaviours

This document describes the in-depth required behaviours for **focus mode** with **Pomodoro-type** sessions on list-item cards. It defines what the UI and backend must do together. The companion document [focus-mode-pomodoro-backend.md](focus-mode-pomodoro-backend.md) lists the backend layers (schema, models, actions, traits) that implement these behaviours.

**Terms used in both docs:** *Focus session* = one stored record (work block or break). *Work block* = one countdown from full duration to 0 (a Pomodoro). *Default duration* = used when the task has no `duration` (from user settings or app default, e.g. 25 min).

**Frontend approach:** All focus-mode actions use **optimistic UI**: update the UI immediately (focus styling, dimming, overlay, timer state, pause/complete, etc.) before the server responds; then call the server and either sync with the response or roll back and show an error on failure. This applies in every phase (entry, exit, pause/resume, complete, and any later server-backed actions). See the project’s [optimistic UI guide](../.cursor/optimistic-ui-guide.md) for the pattern.

---

## 1. Entry & Availability

### 1.1 Focus Entry Point

- Each card has a **Focus** entry point (e.g. in the existing ellipsis dropdown, or a dedicated “Focus” dropdown).
- Inside that, at least one option: **“Pomodoro”** (or “Start Pomodoro focus”) that starts focus mode for that card.
- Scope: Focus mode is available for **tasks**. For **projects** and **events**, either hide Focus/Pomodoro, or show it and use a **default duration** when the item has no `duration` (see 1.2).

### 1.2 Duration Source

- **Task has `duration` (minutes):** Use that value as the work-interval length for this focus session (e.g. 15, 30, 60, 120, 240, 480). Backend stores this as “used task duration” when persisting the session.
- **Task has no `duration` (null/empty):** Either:
  - **Option A (recommended):** Use a **default duration** (e.g. **25 minutes** from user Pomodoro settings or app config) and show a hint: “Using 25 min (default). Set duration on the task to customize.” Backend stores “used default duration” when persisting.
  - **Option B:** Disable Pomodoro for that card and show: “Set a duration on this task to use focus mode.”
- **Recommendation:** Option A so every task can use focus. Backend distinguishes “used default” vs “used task duration” in the stored session payload.

### 1.3 Single Active Focus

- **Rule:** Only **one** card on the page can be in focus mode at a time.
- If the user starts focus on another card while one is already focused:
  - Either **switch** focus to the new card (stop the previous session and start the new one), or
  - Show something like “End the current focus session first” and do not start the second.
- **Implementation note:** Parent (list/page) or a shared store must know “which card (if any) is focused” so starting focus elsewhere can be handled consistently. Backend supports this via a single in-progress focus session per user (`getActiveFocusSession`).

---

## 2. Visual & Layout State When Focused

### 2.1 Focused Card Styling

- **Border:** Clearly different from normal (e.g. thicker, accent colour, or glow).
- **Background:** Different from normal (e.g. slightly brighter/darker or tinted) so the card is visually “elevated.”
- Optional: subtle shadow or scale so the card feels like the only active content.
- Same focus styling for all item types (task/event/project) when they support focus.

### 2.2 Rest of Page (Outside the Card)

- **Dimmed:** Content outside the focused card is visually dimmed (e.g. lower opacity or overlay).
- **Non-interactive:** Clicks/taps and keyboard focus do **not** activate elements outside the focused card (buttons, links, other cards, nav). Use `pointer-events: none` (or equivalent) on a full-page overlay or wrapper, with the focused card above it and `pointer-events: auto`.
- **Focus trap (optional but recommended):** When focus mode is on, tab/arrow keys keep focus inside the focused card (or at least don’t move to dimmed content).

### 2.3 View-Only Mode

- **While focused:** The user cannot edit any property of the item:
  - No inline edit for title, description, status, priority, complexity, duration, dates, tags, recurrence, etc.
- **Display:** All fields are read-only (labels/text only, no inputs, no dropdowns that change data). Clicks that normally start editing do nothing or are disabled.
- **Clarification:** “View mode” applies only to **this** card’s content; the only interactive elements are the focus-mode controls (timer, pause, resume, stop, exit).

---

## 3. Timer & Progress

### 3.1 Timer Semantics

- **Work interval:** One countdown from “full duration” down to 0 (e.g. 25:00 → 0:00). This is the “pomodoro work block.”
- **Unit:** Countdown in **minutes and seconds** (e.g. 24:59, 24:58 … 0:01, 0:00).
- **Duration:** Comes from item `duration` (minutes) or default (e.g. 25 min); converted to seconds internally for countdown.

### 3.2 Progress Bar

- **Meaning:** Represents “time remaining” or “time elapsed” for the **current work block**.
  - **Option A (recommended):** Bar shows **elapsed** (0% → 100% as time runs down). Full bar = session complete.
  - **Option B:** Bar shows **remaining** (100% → 0%). Empty bar = session complete.
- **Sync:** Progress bar and countdown must always match (same remaining/elapsed time).
- **Updates:** Smooth updates (e.g. every second or more frequently for smooth animation). When paused, bar and countdown freeze.

### 3.3 Countdown Display

- **Visible:** Numeric countdown (e.g. “24:35” or “24 min 35 sec”) is always visible while the timer is running or paused.
- **Format:** Consistent, readable format (e.g. `MM:SS`). For long sessions (e.g. 4 hours), consider “2:00:00” or “120 min” so it stays readable.
- **Placement:** Near the progress bar and control buttons so the user can glance at time left without interpreting the bar alone.

### 3.4 Auto-Play on Focus Start

- **On “Start Pomodoro”:** Timer starts immediately (auto-play). No extra “Play” click required.
- **Initial state:** Countdown = full duration; progress bar = 0% elapsed (or 100% remaining, depending on chosen convention).

---

## 4. Controls (Inside the Focused Card)

### 4.1 Pause

- **Action:** Pause the countdown and progress bar.
- **UI:** Replace or supplement “Pause” with a **Resume** affordance (e.g. “Resume” button or “Paused – Resume”).
- **State:** While paused, countdown and bar do not change. Resuming continues from the **same** remaining time (no reset).

### 4.2 Resume

- **Action:** Resume from current remaining time.
- **Availability:** Shown when the session is paused. After resume, show Pause again (and hide or de-emphasise Resume).

### 4.3 Stop

- **Action:** End the focus session immediately (timer stops, focus mode exits).
- **Semantics:** “Stop” = **abandon** the current work block. Backend records the session as abandoned (completed = false, ended_at set); it is not counted as a completed Pomodoro.
- **After stop:** Card returns to normal (no focus styling), overlay/dimming removed, card editable again. No “session complete” celebration.

### 4.4 Exit Focus (Without “Stop”)

- **Additional way to leave:** User can exit focus without explicitly pressing “Stop”, e.g.:
  - **Escape key:** Hitting Escape exits focus (and stops the timer, same as Stop).
  - **“Exit focus” / “Leave focus” button:** Same effect as Stop.
- **Consistency:** Stop, Escape, and “Exit focus” all: stop timer, exit focus mode, restore page.

### 4.5 Buttons Visibility and Placement

- **When running:** Pause and Stop (and optionally a small “Exit focus”) are visible.
- **When paused:** Resume and Stop (and optionally “Exit focus”) are visible.
- **When completed (timer reached 0):** See section 5; different set of actions (e.g. “End focus”, “Start break” later).

---

## 5. Session Complete (Timer Reached 0)

### 5.1 Reaching Zero

- **Trigger:** When countdown hits 0:00, the work block is considered **complete** (not “stopped”).
- **Timer behaviour:** Timer stops at 0. Progress bar shows 100% elapsed (or 0% remaining). Do not go negative.

### 5.2 Visual/Audio Feedback

- **Visual:** Clear “Session complete” state: e.g. message (“Session complete”, “Good work”, etc.), and/or progress bar full, and/or brief highlight.
- **Sound (optional):** Completion sound when the work block reaches 0; must be toggleable via user settings (e.g. Pomodoro settings: sound on/off, volume). Respect `prefers-reduced-motion` / accessibility where relevant. Backend stores `sound_enabled` and `sound_volume` in user Pomodoro settings.

### 5.3 Actions After Completion

- **Always available:** **“End focus”** (or “Done”) to leave focus mode and return the card to normal. Backend completes the focus session (completed = true) when the user ends focus after reaching 0.
- **Optional:** **“Mark task as Done”** or **“Mark as Doing”** — backend can update task status when completing a work session (see backend doc: CompleteFocusSessionAction).
- **Optional:** **“Start break”** — short or long break timer; after break, “Start next block” or “End focus.” Backend supports break session types and user settings (short/long break duration, long break after N pomodoros).
- **Required minimum:** At least **“End focus”** and a clear “session complete” state.

### 5.4 No Auto-Exit

- **Requirement:** When the timer hits 0, do **not** automatically exit focus mode. User explicitly chooses “End focus” (or “Start break” later). This avoids accidentally losing context and allows a moment to read the message or mark the task.

---

## 6. Edge Cases & Robustness

### 6.1 Tab Visibility / Tab in Background

- **Optional but recommended:** When the browser tab is in the background, either:
  - **Pause the timer** when the tab is hidden and **resume** when the tab is visible again, or
  - **Keep running** but document that the timer may drift if the tab is throttled.
- **Recommendation:** Pause when tab is hidden so “25 min focus” means 25 min of active tab time.

### 6.2 Page Refresh or Navigation

- **Without backend:** If the user refreshes or navigates away, focus mode and timer state are lost. Optional message: “Focus session was not saved.”
- **With full backend:** Backend persists the in-progress focus session (one per user). On load, the app can call `getActiveFocusSession` and restore focus mode + timer (resume from remaining time). Optional message when abandoning: “Focus session was not saved.”

### 6.3 Very Long Duration

- **Display:** For durations ≥ 1 hour, ensure countdown format stays readable (e.g. “1:30:00” or “90 min”).
- **Progress bar:** Still 0% → 100% (or 100% → 0%) over the full duration; no special behaviour.

### 6.4 Card Removed While in Focus

- **If the card is deleted or moved** (e.g. by another tab or user): When the list updates and the card is gone, exit focus mode and remove overlay/dimming. No need to “save” the session if the item no longer exists. Backend may leave an abandoned in-progress session for that task (optional cleanup).

### 6.5 Accessibility

- **Keyboard:** All focus-mode actions (Pause, Resume, Stop, End focus, optional “Mark as Done”) are keyboard accessible (tab + Enter/Space).
- **Escape:** Escape exits focus mode (see 4.4).
- **Screen readers:** Announce focus mode entry (“Focus mode on”), timer updates (e.g. “5 minutes remaining” at 5:00), “Paused”, “Session complete”, and button labels.
- **Reduced motion:** If you animate the progress bar or countdown, respect `prefers-reduced-motion` (e.g. instant updates instead of smooth animation).

---

## 7. Relationship to Backend

| Behaviour area | Backend support |
|----------------|-----------------|
| Duration source (task vs default) | `pomodoro_settings.work_duration_minutes`; `focus_sessions.payload` (`used_task_duration` / `used_default_duration`) |
| Single active focus | One in-progress `FocusSession` per user; `GetActiveFocusSessionAction`, `getActiveFocusSession()` |
| Stop / Exit | `AbandonFocusSessionAction` (completed = false) |
| Session complete (timer 0) | `CompleteFocusSessionAction` (completed = true); optional task status update |
| Sound toggle | `pomodoro_settings.sound_enabled`, `sound_volume` |
| Paused time | `focus_sessions.paused_seconds` when completing or abandoning |
| Resume after refresh | In-progress session stored; `getActiveFocusSession()` returns it |
| Optional: Start break | `FocusSessionType` short_break / long_break; settings for durations and “long break after N” |

See [focus-mode-pomodoro-backend.md](focus-mode-pomodoro-backend.md) for full backend layers.

---

## 8. Summary Checklist

| # | Area | Required behaviour |
|---|------|--------------------|
| 1 | Entry | Focus entry on card (e.g. dropdown) with “Pomodoro” option. |
| 2 | Availability | Only tasks (or tasks + events/projects with default duration). |
| 3 | Duration | Use item `duration` when set; otherwise fixed default (e.g. 25 min) or disable with message. |
| 4 | Single focus | Only one card in focus at a time; starting another either switches or shows “end current first.” |
| 5 | Card styling | Focused card: distinct border and background (and optional shadow/scale). |
| 6 | Rest of page | Dimmed and non-interactive (overlay + pointer-events). Optional focus trap. |
| 7 | View-only | No editing of any card property while focused; only focus controls are interactive. |
| 8 | Timer | Countdown in minutes:seconds from duration to 0; one work block per session. |
| 9 | Progress bar | Synced with timer (elapsed or remaining); updates every second; freezes when paused. |
| 10 | Countdown display | Visible numeric countdown (e.g. MM:SS) next to bar and controls. |
| 11 | Auto-play | Timer starts automatically when entering focus. |
| 12 | Pause | Pauses timer and bar; shows Resume; resume continues from same remaining time. |
| 13 | Stop | Stops timer and exits focus; no “complete” state. |
| 14 | Exit | Escape and/or “Exit focus” button also exits (same as Stop). |
| 15 | At 0:00 | Timer stops; show “Session complete” and at least “End focus” button; do not auto-exit. |
| 16 | Tab hidden | Optional: pause timer when tab hidden, resume when visible. |
| 17 | A11y | All controls keyboard-accessible; Escape exits; screen reader announcements; reduced motion. |
| 18 | Optimistic UI | All server-backed actions (start, stop, complete, optional mark-done, etc.): update UI first, then call server; roll back and toast on error. |

---

## 9. Frontend implementation phases

The frontend should be implemented in **six phases** so each slice is testable and dependencies flow in order. Backend is already in place; each phase builds on the previous.

**Optimistic UI (all phases):** Every user-triggered action that touches the server must follow the optimistic pattern: snapshot if needed → update UI immediately → call server → on success sync with response, on error roll back and show a toast. This keeps the interface feeling instant across all six phases.

- **Phase 1–2 (entry and exit):** **Start:** build an optimistic session (e.g. temporary id), set local focus state and dispatch `focus-session-updated` so Index, List, and other cards update immediately; then call `startFocusSession(taskId, payload)`; on success replace with server result, on error clear and toast. **Stop / exit:** snapshot current session, clear state and dispatch `session: null`; then call `abandonFocusSession(sessionId)`; on error restore and toast.
- **Phase 4 (pause / resume, stop):** Pause/resume: update paused state and UI immediately; sync `paused_seconds` with server when abandoning or completing. Stop: already optimistic in Phase 2.
- **Phase 5 (session complete, “End focus”):** When user clicks “End focus” after timer reaches 0: clear focus state and dispatch `session: null` immediately (or show “complete” state then clear); then call `completeFocusSession(sessionId, payload)`; on error restore and toast.
- **Phase 6 and later:** Any action that persists state (e.g. optional “Mark task as Done”) should update UI first, then call server; roll back on error.

Use the same event name and `detail: { session }` for focus-session updates so Index, List, and cards stay in sync. Optional: use subtle CSS transitions on focus/dim styling so changes don’t flicker. See [.cursor/optimistic-ui-guide.md](../.cursor/optimistic-ui-guide.md).

| Phase | Doc sections | Deliverables | Checklist refs |
|-------|----------------|---------------|----------------|
| **1** | §1 | Focus entry on task card (e.g. “Pomodoro” in ellipsis dropdown). Duration: task `duration` (minutes) or default (e.g. 25 min from settings); optional hint when using default. **Optimistic entry:** on click, set optimistic session and dispatch `focus-session-updated` so UI updates immediately; then call parent `startFocusSession(taskId, payload)`; on success replace with server result, on error rollback and toast. Index: call `getActiveFocusSession()` on load and after start/complete/abandon; store in Livewire property. Pass active session (e.g. `activeFocusSession`) from Index → List → each card. Single active focus: backend already abandons previous when starting new; frontend only reflects the one active session from parent. | 1, 2, 3, 4 |
| **2** | §2 | Focused card styling when this card is the active focus session (distinct border/background; optional shadow/scale). Overlay: when any card is focused, show full-page (or list-covering) overlay that dims the rest of the page, `pointer-events: none` on overlay, focused card above with `pointer-events: auto`. Optional: subtle transitions on focus/dim so changes don’t flicker. **Optimistic exit:** Stop (and Escape/exit) clears focus state and dispatches `session: null` immediately, then calls `abandonFocusSession(sessionId)`; on error rollback and toast. Optional later: focus trap. View-only: when this card is focused, disable all inline edits and data-changing dropdowns; only focus controls are interactive. Minimal “Focus mode” bar with Stop placeholder is enough for this phase. | 5, 6, 7 |
| **3** | §3 | Timer: in focused card (Alpine), compute remaining seconds from `started_at` + `duration_seconds` minus elapsed (and later minus `paused_seconds`); update every second; stop at 0. Progress bar: e.g. elapsed % (0% → 100%), synced with same remaining time; freeze when paused (Phase 4). Countdown display: MM:SS (or “2:00:00” / “90 min” for ≥ 1 h). Auto-play: timer starts as soon as focus starts; no extra “Play” click. (Optimistic: timer/bar are client-driven; server sync is on start/stop/complete.) | 8, 9, 10, 11 |
| **4** | §4 | Pause: button sets paused state, freezes countdown and bar, records pause start; total `paused_seconds` accumulated client-side (optimistic: UI updates immediately; send `paused_seconds` when abandoning/completing). Resume: same — clear paused and update UI first. Stop: optimistic exit per Phase 2. Exit: Escape and/or “Exit focus” button, same as Stop. When abandoning: send `paused_seconds` (extend backend abandon or use complete with `completed: false` if supported). | 12, 13, 14 |
| **5** | §5 | When remaining hits 0: stop ticker, show “Session complete” state (message + full bar). No auto-exit. **Optimistic “End focus”:** clear focus state and dispatch `session: null` immediately, then call `completeFocusSession(sessionId, { ended_at, completed: true, paused_seconds })`; on error restore and toast. Optional: “Mark task as Done” / “Mark as Doing” — send `mark_task_status` in payload (also optimistic: update task UI first, then server). Optional: completion sound (can defer to Phase 6). | 15 |
| **6** | §6, §5.2, §6.5 | Resume after refresh: when `activeFocusSession` is present on load, matching card renders focus UI and restores remaining time from `started_at` + `duration_seconds` (and `paused_seconds` when available). Card removed (§6.4): when list updates and focused task is gone, clear focus state and overlay. Very long duration (§6.3): readable countdown format. Tab hidden (§6.1, optional): pause when `document.visibilityState === 'hidden'`, resume on visible; add to `paused_seconds`. A11y (§6.5): keyboard access to all focus buttons; Escape exits; live region for “Session complete” and timer; `prefers-reduced-motion` for progress animation. Sound on complete (§5.2, optional): play when user clicks “End focus” after 0 if settings allow. Any new server-backed actions in this phase: apply optimistic pattern (update UI first, then server; rollback on error). | 16, 17 + edge cases |

**Optional later (Phase 7 or polish):** “Start break” after session complete (§5.3); focus trap (tab confined to focused card).

---

*Required behaviours for focus mode (Pomodoro). Implement front-end and backend together using [focus-mode-pomodoro-backend.md](focus-mode-pomodoro-backend.md) for schema, models, actions, and traits.*
