# Focus Mode (Pomodoro) – Required Behaviours

This document describes the in-depth required behaviours for **focus mode** with **Pomodoro-type** sessions on list-item cards. It serves as the spec before implementing backend support.

---

## 1. Entry & Availability

### 1.1 Focus Entry Point

- Each card has a **Focus** entry point (e.g. in the existing ellipsis dropdown, or a dedicated “Focus” dropdown).
- Inside that, at least one option: **“Pomodoro”** (or “Start Pomodoro focus”) that starts focus mode for that card.
- Scope: Focus mode is available for **tasks**. For **projects** and **events**, either hide Focus/Pomodoro, or show it and use a **default duration** when the item has no `duration` (see 1.2).

### 1.2 Duration Source

- **Task has `duration` (minutes):** Use that value as the work-interval length for this focus session (e.g. 15, 30, 60, 120, 240, 480).
- **Task has no `duration` (null/empty):** Either:
  - **Option A:** Use a fixed default (e.g. **25 minutes**) and show a small hint: “Using 25 min (default). Set duration on the task to customize.”
  - **Option B:** Disable Pomodoro for that card and show: “Set a duration on this task to use focus mode.”
- **Recommendation:** Option A so every task can use focus; backend can later distinguish “used default” vs “used task duration.”

### 1.3 Single Active Focus

- **Rule:** Only **one** card on the page can be in focus mode at a time.
- If the user starts focus on another card while one is already focused:
  - Either **switch** focus to the new card (stop the previous session and start the new one), or
  - Show something like “End the current focus session first” and do not start the second.
- **Implementation note:** Parent (list/page) or a shared store must know “which card (if any) is focused” so starting focus elsewhere can be handled consistently.

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
- **Semantics:** “Stop” = abandon current block (no “completed block” recorded if you add backend later).
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
- **Sound (optional):** Optional completion sound; if implemented, should be toggleable (e.g. user preference or mute button) and respect `prefers-reduced-motion` / accessibility where relevant.

### 5.3 Actions After Completion

- **Always available:** **“End focus”** (or “Done”) to leave focus mode and return the card to normal. No further timer.
- **Optional (this iteration):** A **“Mark task as Done”** or **“Mark as Doing”** button that (when backend exists) updates task status. For now you can design the button and wire it later.
- **Optional (later):** “Start break” (short break timer); after break, “Start next block” or “End focus.” For the in-depth list, the **required** part is: at least **“End focus”** and a clear “session complete” state.

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

- **Acceptable for this iteration:** If the user refreshes or navigates away, focus mode and timer state are lost (no persistence). Optional message: “Focus session was not saved.”
- **Later:** Backend could persist “session in progress” and restore on return.

### 6.3 Very Long Duration

- **Display:** For durations ≥ 1 hour, ensure countdown format stays readable (e.g. “1:30:00” or “90 min”).
- **Progress bar:** Still 0% → 100% (or 100% → 0%) over the full duration; no special behaviour.

### 6.4 Card Removed While in Focus

- **If the card is deleted or moved** (e.g. by another tab or user): When the list updates and the card is gone, exit focus mode and remove overlay/dimming. No need to “save” the session if the item no longer exists.

### 6.5 Accessibility

- **Keyboard:** All focus-mode actions (Pause, Resume, Stop, End focus, optional “Mark as Done”) are keyboard accessible (tab + Enter/Space).
- **Escape:** Escape exits focus mode (see 4.4).
- **Screen readers:** Announce focus mode entry (“Focus mode on”), timer updates (e.g. “5 minutes remaining” at 5:00), “Paused”, “Session complete”, and button labels.
- **Reduced motion:** If you animate the progress bar or countdown, respect `prefers-reduced-motion` (e.g. instant updates instead of smooth animation).

---

## 7. Summary Checklist

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

---

*This spec can be used for front-end implementation and later for backend (persistence, break timers, task status updates).*
