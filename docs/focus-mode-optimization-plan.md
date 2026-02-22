# Focus Mode Frontend — Optimization Plan (Two Phases)

This plan implements the audit recommendations to optimize the focus modal flow: remove dead/redundant code, consolidate logic, and reduce per-tick work.

---

## Phase 1 — Dead code removal & derived state

**Goal:** Remove unused code and derive `focusProgressStyle` from existing state. No refactor of shared “remaining seconds” logic. Low risk, easy to verify.

### 1.1 Remove dead / unused code

| Item | Location | Action |
|------|----------|--------|
| `_lastDisplayUpdate` | `resources/js/alpine/focus-session.js` | Remove both assignments: in `startFocusTicker` (initial `= 0`) and inside the `setInterval` callback (`= now`). Never read. |
| Card method `formatFocusCountdown(seconds)` | `resources/js/alpine/list-item-card.js` | Remove the method. No callers; controller uses `formatFocusCountdown` from `focus-time.js`; template uses `focusCountdownText` set by controller. |
| Card method `formatPomodoroDurationMinutes(minutes)` | `resources/js/alpine/list-item-card.js` | Remove the method. Template uses getter `formattedPomodoroWorkDuration` only; no other references. |

### 1.2 Derive progress style in template (remove `focusProgressStyle` from state)

**Current:** `focusProgressStyle` is a string kept in Alpine state and updated every second in the ticker (and in pause/resume/stop).

**Target:** Progress bar style is derived in the template from `focusElapsedPercentValue` only.

- **`resources/views/components/workspace/list-item-card/focus-bar.blade.php`**  
  Replace  
  `:style="focusProgressStyle"`  
  with  
  `:style="'width: ' + (focusElapsedPercentValue ?? 0) + '%; min-width: ' + ((focusElapsedPercentValue ?? 0) > 0 ? '2px' : '0')"`

- **`resources/js/alpine/list-item-card.js`**  
  Remove from initial state:  
  `focusProgressStyle: 'width: 0%; min-width: 0',`

- **`resources/js/alpine/focus-session.js`**  
  - In `startFocusTicker`: remove the line that sets `ctx.focusProgressStyle = ...` (and the one that sets it in the initial block before the interval).  
  - Inside the `setInterval` callback: remove the line that sets `ctx.focusProgressStyle = ...` (inside the `requestAnimationFrame` callback).  
  - In `stopFocusTicker`: remove the line `ctx.focusProgressStyle = 'width: 0%; min-width: 0';`  
  - In `pauseFocus`: remove the two lines that set `ctx.focusProgressStyle = ...`  
  - In `resumeFocus`: remove the two lines that set `ctx.focusProgressStyle = ...`  

**Verification:** Focus bar progress still animates correctly during work/break sessions and resets when stopping or dismissing.

### 1.3 Optional — Simplify ticker (remove `requestAnimationFrame`)

- **`resources/js/alpine/focus-session.js`** — In `startFocusTicker`, inside the `setInterval` callback:  
  Update the three reactive properties (`focusElapsedPercentValue`, `focusCountdownText`, and any remaining `focusProgressStyle` reference) directly instead of wrapping in `requestAnimationFrame(...)`.  
  If you already removed `focusProgressStyle` in 1.2, only the rAF wrapper and the two assignments (`focusElapsedPercentValue`, `focusCountdownText`) need to change.

**Verification:** Timer and progress still update every second with no visible jank.

---

## Phase 2 — Consolidate remaining-seconds logic & simplify getters

**Goal:** Single implementation for “remaining seconds” and “elapsed percent”; remove duplicate logic and the card getters that only exist to serve the controller.

### 2.1 Single implementation for remaining seconds

**Option A (recommended):** Add a shared helper in `resources/js/lib/focus-time.js`, e.g.:

- `getFocusRemainingSeconds(session, nowMs, options)`  
  Where `options` includes `pausedSecondsAccumulated`, `pauseStartedAt`, `isPaused`.  
  Returns integer remaining seconds.  
  Move the logic from either the card getter or `getRemainingSecondsAt` into this function (handling `started_at`, `duration_seconds`, `paused_at`, `paused_seconds`, and local pause state).

Then:

- **`resources/js/alpine/focus-session.js`**  
  - Implement `getRemainingSecondsAt(ctx, nowMs)` by calling the new helper with `ctx.activeFocusSession`, `nowMs`, and ctx’s pause fields.  
  - Keep using `getRemainingSecondsAt` in the ticker and in `checkFocusCompletionOnVisible` (and anywhere else that currently uses it).

- **`resources/js/alpine/list-item-card.js`**  
  - Change `focusRemainingSeconds` getter to call the same helper (or a small wrapper that passes `this.activeFocusSession`, `this.focusTickerNow ?? Date.now()`, and this card’s pause state).  
  So the card getter becomes a thin wrapper over the single implementation.

**Option B:** Keep the implementation only in the controller: add a method on the controller like `getRemainingSeconds(ctx, nowMs)` that returns remaining seconds. Then the card’s `focusRemainingSeconds` getter becomes `return this._focus.getRemainingSeconds(this, this.focusTickerNow ?? Date.now())`. Remove the duplicate math from the card and ensure `getRemainingSecondsAt` uses the same controller method (or the same shared helper as in Option A).

**Verification:** Ticker, completion-on-tab-visible, pause/resume, and any UI that reads `focusRemainingSeconds` behave unchanged.

### 2.2 Remove `focusElapsedPercent` getter; compute percent in controller

- **`resources/js/alpine/list-item-card.js`**  
  Remove the getter `focusElapsedPercent` (the one that computes from `duration_seconds` and `focusRemainingSeconds`).

- **`resources/js/alpine/focus-session.js`**  
  In `pauseFocus` and `resumeFocus`, stop reading `ctx.focusElapsedPercent`. Instead:  
  - Compute `remaining` using the single remaining-seconds implementation (e.g. `getRemainingSecondsAt(ctx, Date.now())` or the new helper).  
  - Compute `pct = duration > 0 ? Math.min(100, Math.max(0, ((duration - remaining) / duration) * 100)) : 0`.  
  - Set `ctx.focusElapsedPercentValue = pct`.  
  - Remove any remaining references to `focusProgressStyle` if not already removed in Phase 1.

**Verification:** Pause and resume still show the correct countdown and progress; progress bar still reflects elapsed time.

### 2.3 Follow-up cleanup

- Ensure `focusTickerNow` is only set where necessary (ticker, visibility check, pause/resume). No new uses of the removed getters.
- Run tests and manual pass: focus ready → start (Sprint and Pomodoro) → pause/resume → stop/dismiss → completion flows.

---

## Execution order

1. **Phase 1** (next iteration): 1.1 → 1.2 → 1.3 (optional). Then run focus-mode-related tests and a quick manual check.
2. **Phase 2** (later): 2.1 → 2.2 → 2.3. Then full regression on focus and pomodoro flows.

---

## Files touched (summary)

| Phase | File | Changes |
|-------|------|--------|
| 1 | `resources/js/alpine/focus-session.js` | Remove `_lastDisplayUpdate`; remove all `focusProgressStyle` updates; optionally remove rAF in ticker. |
| 1 | `resources/js/alpine/list-item-card.js` | Remove `formatFocusCountdown`, `formatPomodoroDurationMinutes`; remove `focusProgressStyle` from initial state. |
| 1 | `resources/views/components/workspace/list-item-card/focus-bar.blade.php` | Derive progress bar style from `focusElapsedPercentValue`. |
| 2 | `resources/js/lib/focus-time.js` | Add shared `getFocusRemainingSeconds` (or equivalent) helper. |
| 2 | `resources/js/alpine/focus-session.js` | Use shared helper in `getRemainingSecondsAt`; in pause/resume compute percent and set only `focusElapsedPercentValue`. |
| 2 | `resources/js/alpine/list-item-card.js` | `focusRemainingSeconds` getter delegates to shared helper or controller; remove `focusElapsedPercent` getter. |
