# Focus Mode (Pomodoro) – Backend Layers

This document lists the schema, models, services, actions, traits, and related backend pieces required for the full Pomodoro implementation. It implements the behaviours defined in [focus-mode-pomodoro-behaviours.md](focus-mode-pomodoro-behaviours.md): entry & duration, single active focus, timer & progress, controls (pause/resume, stop, exit), session complete, and edge cases (tab hidden, refresh/restore, card removed).

**Terms (match behaviours doc):** *Focus session* = one stored record (work block or break). *Work block* = one countdown from full duration to 0. *Abandon* = user stops or exits without reaching 0 (completed = false). *Complete* = timer reached 0 (completed = true).

---

## 1. Schema (Migrations)

### 1.1 `pomodoro_settings` (user-level settings)

One row per user; create on first access. Supports default duration (behaviours §1.2) and sound/notification (§5.2).

| Column | Type | Default | Purpose |
|--------|------|---------|---------|
| `id` | bigIncrements | — | PK |
| `user_id` | foreignId → users | — | Unique (one settings per user) |
| `work_duration_minutes` | unsignedSmallInteger | 25 | Default work block length when task has no duration |
| `short_break_minutes` | unsignedSmallInteger | 5 | Short break |
| `long_break_minutes` | unsignedSmallInteger | 15 | Long break |
| `long_break_after_pomodoros` | unsignedSmallInteger | 4 | Long break every N pomodoros |
| `auto_start_break` | boolean | false | Auto-start break when work ends |
| `auto_start_pomodoro` | boolean | false | Auto-start next pomodoro after break |
| `sound_enabled` | boolean | true | Play sound on complete (§5.2) |
| `sound_volume` | unsignedTinyInteger | 80 | 0–100 |
| `notification_on_complete` | boolean | false | Browser/desktop notification when timer ends |
| `created_at` / `updated_at` | timestamps | — | — |

- Index: unique on `user_id`.

### 1.2 `focus_sessions` (stored sessions)

One row per focus session (work block or break). Supports behaviours §3 (timer), §4 (pause/stop/exit), §5 (session complete), §6.2 (restore after refresh).

| Column | Type | Nullable | Purpose |
|--------|------|----------|---------|
| `id` | bigIncrements | — | PK |
| `user_id` | foreignId → users | — | Owner |
| `focusable_type` | string | yes | e.g. `App\Models\Task`; null for break-only sessions |
| `focusable_id` | unsignedBigInteger | yes | Task id; null for break-only sessions |
| `type` | string | — | `work` \| `short_break` \| `long_break` |
| `sequence_number` | unsignedSmallInteger | — | 1-based index in chain (for “long break after N”) |
| `duration_seconds` | unsignedInteger | — | Planned length (work block or break) |
| `completed` | boolean | — | Reached 0 (true) vs abandoned (false) — §4.3, §5.1 |
| `started_at` | timestamp | — | When timer started |
| `ended_at` | timestamp | yes | When session ended (complete or abandon) |
| `paused_seconds` | unsignedInteger | — | Total time spent paused — §4.1–4.2, §6.1 |
| `payload` | json | yes | e.g. `used_task_duration`, `used_default_duration` — §1.2 |
| `created_at` / `updated_at` | timestamps | — | — |

- **Nullable:** For break-only sessions (no task), `focusable_type` and `focusable_id` must be nullable. Work sessions always have a task (behaviours §1.1).
- Indexes: `(user_id, started_at)`; `(focusable_type, focusable_id, started_at)`; active session = one row per user with `ended_at` IS NULL.

---

## 2. Models

### 2.1 `App\Models\PomodoroSetting`

- Table: `pomodoro_settings`.
- Relations: `belongsTo(User::class)`.
- Fillable: all columns except `id`, `user_id`, timestamps (or guarded as appropriate).
- Casts: booleans for `auto_start_break`, `auto_start_pomodoro`, `sound_enabled`, `notification_on_complete`.
- Optional: `getWorkDurationSecondsAttribute()` etc.
- Static: `PomodoroSetting::defaults()` returning default array for new user.

**User:** `hasOne(PomodoroSetting::class)`.

### 2.2 `App\Models\FocusSession`

- Table: `focus_sessions`.
- Relations: `belongsTo(User::class)`, `morphTo('focusable')` (Task now; Event/Project later; null for breaks).
- Casts: `started_at`, `ended_at` datetime; `payload` array; `completed` boolean.
- Scopes: `forUser($userId)`, `forTask(Task $task)`, `work()`, `completed()`, `inProgress()`, `today()`, optional `thisWeek()`.
- Enum: use string enum for `type` (work, short_break, long_break).

**Task:** `morphMany(FocusSession::class, 'focusable')`.

---

## 3. Enums

### 3.1 `App\Enums\FocusSessionType`

- `Work = 'work'`
- `ShortBreak = 'short_break'`
- `LongBreak = 'long_break'`

### 3.2 `App\Enums\ActivityLogAction`

- Add: `FocusSessionCompleted = 'focus_session_completed'` (and label).

---

## 4. Validation / DTOs

### 4.1 Settings (update)

- Rules: work_duration_minutes (1–120), short_break_minutes (1–60), long_break_minutes (1–60), long_break_after_pomodoros (2–10), booleans, sound_volume (0–100).
- Where: FormRequest `UpdatePomodoroSettingsRequest` or inline in Livewire.
- Optional DTO: `UpdatePomodoroSettingsDto`.

### 4.2 Start session

- Payload: `task_id`, `type` (work | short_break | long_break), `duration_seconds`, `started_at`, optional `sequence_number`, optional `payload` (e.g. used_task_duration).
- Optional DTO: `StartFocusSessionDto`.

### 4.3 Complete session

- Payload: `focus_session_id` (or task_id + started_at), `ended_at`, `completed`, `paused_seconds`, optional `duration_seconds`.
- Optional DTO: `CompleteFocusSessionDto`.

---

## 5. Actions

### 5.1 Settings

- **`App\Actions\Pomodoro\GetOrCreatePomodoroSettingsAction`**  
  Input: `User $user`. Returns: `PomodoroSetting` (create with defaults if missing).

- **`App\Actions\Pomodoro\UpdatePomodoroSettingsAction`**  
  Input: `User $user`, validated array (or DTO). GetOrCreate then update; return model.

### 5.2 Sessions

- **`App\Actions\FocusSession\StartFocusSessionAction`**  
  Input: `User $user`, `Task $task` (or null for break), `FocusSessionType $type`, `duration_seconds`, `started_at`, optional `sequence_number`, optional payload. Ensure at most one in-progress session per user (behaviours §1.3); create `FocusSession` (completed = false, ended_at = null). Return `FocusSession`.

- **`App\Actions\FocusSession\CompleteFocusSessionAction`**  
  Input: `FocusSession $session`, `ended_at`, `completed`, `paused_seconds`. Update session; optionally record `ActivityLogAction::FocusSessionCompleted`; optionally update task status when completed work (behaviours §5.3). Return `FocusSession`.

- **`App\Actions\FocusSession\AbandonFocusSessionAction`**  
  Input: `FocusSession $session` (or id + user). Set ended_at, completed = false. Use when user clicks Stop or Exit (behaviours §4.3, §4.4).

- **`App\Actions\FocusSession\GetActiveFocusSessionAction`**  
  Input: `User $user`. Return in-progress `FocusSession` or null (single active focus §1.3, resume on load §6.2).

---

## 6. Services

### 6.1 `App\Services\PomodoroSettingsService` (optional)

- `getOrCreateForUser(User $user): PomodoroSetting`
- `updateForUser(User $user, array $data): PomodoroSetting`

### 6.2 `App\Services\FocusSessionService`

- `startWorkSession(User $user, Task $task, \DateTimeInterface $startedAt, int $durationSeconds, bool $usedTaskDuration = false): FocusSession`
- `startBreakSession(User $user, FocusSessionType $breakType, \DateTimeInterface $startedAt, int $durationSeconds, int $sequenceNumber): FocusSession`
- `completeSession(FocusSession $session, \DateTimeInterface $endedAt, bool $completed, int $pausedSeconds = 0): FocusSession`
- `abandonSession(FocusSession $session): FocusSession`
- `getActiveSessionForUser(User $user): ?FocusSession`
- `getSessionsForTask(Task $task, ?\DateTimeInterface $date = null): Collection`
- `getSessionsForUserToday(User $user): Collection`

---

## 7. Livewire Traits

### 7.1 `App\Livewire\Concerns\HandlesFocusSessions`

Used by workspace index (parent of list and list-item-card). Methods:

- **`startFocusSession(int $taskId, array $payload): array`**  
  Payload: type, duration_seconds, started_at, optional sequence_number, optional used_task_duration. Return e.g. `['id' => $session->id, 'started_at' => ...]`.

- **`completeFocusSession(int $sessionId, array $payload): bool`**  
  Payload: ended_at, completed, paused_seconds. Load session, authorize, call CompleteFocusSessionAction.

- **`abandonFocusSession(int $sessionId): bool`**  
  Load session, AbandonFocusSessionAction. Used for Stop and Exit (behaviours §4.3, §4.4).

- **`getActiveFocusSession(): ?array`**  
  Return current user’s in-progress session as array for frontend (single focus, resume after refresh).

- Optional: **`startBreakSession(array $payload): array`**  
  For starting short/long break after work; uses settings (behaviours §5.3 optional).

### 7.2 Settings (for UI)

- **`getPomodoroSettings(): array`** — Load/create settings, return for form.
- **`updatePomodoroSettings(array $data): bool`** — Validate, UpdatePomodoroSettingsAction.

Can live in a trait (e.g. `HandlesPomodoroSettings`) or on the component that renders the settings form (settings page or modal).

---

## 8. Policies

- **`App\Policies\FocusSessionPolicy`**  
  `view`, `update`, `delete`: user can only act on own sessions (`$session->user_id === $user->id`).

- **`App\Policies\PomodoroSettingPolicy`**  
  `view`, `update`: only owner (`$setting->user_id === $user->id`).

Task policy: keep using `update` for “can start/complete focus on this task”.

---

## 9. Config

**`config/pomodoro.php`** (or `config/focus.php`):

- `defaults.work_duration_minutes` => 25
- `defaults.short_break_minutes` => 5
- `defaults.long_break_minutes` => 15
- `defaults.long_break_after_pomodoros` => 4
- `defaults.auto_start_break` => false
- `defaults.auto_start_pomodoro` => false
- `defaults.sound_enabled` => true
- `defaults.sound_volume` => 80
- `defaults.notification_on_complete` => false
- `max_work_duration_minutes` => 120
- `min_duration_minutes` => 1

Use when creating default `PomodoroSetting` and in validation.

---

## 10. Relationship to Behaviours

| Behaviours section | Backend piece |
|--------------------|---------------|
| §1.2 Duration (task vs default) | `pomodoro_settings.work_duration_minutes`; `focus_sessions.payload` (used_task_duration, used_default_duration) |
| §1.3 Single active focus | One in-progress session per user; `GetActiveFocusSessionAction`, `getActiveFocusSession()` |
| §4.3 Stop / §4.4 Exit | `AbandonFocusSessionAction`, `abandonFocusSession()` |
| §5.1 Session complete (timer 0) | `CompleteFocusSessionAction` (completed = true) |
| §5.2 Sound toggleable | `pomodoro_settings.sound_enabled`, `sound_volume` |
| §5.3 Optional Mark task Done, Start break | CompleteFocusSessionAction optional task status update; `FocusSessionType` short_break/long_break, `startBreakSession()` |
| §4.1–4.2 Pause / §6.1 Tab hidden | `focus_sessions.paused_seconds` when completing or abandoning |
| §6.2 Restore after refresh | In-progress session stored (ended_at null); `getActiveFocusSession()` |

See [focus-mode-pomodoro-behaviours.md](focus-mode-pomodoro-behaviours.md) for the full behaviour spec.

---

## 11. Summary Checklist

| Layer | What to create |
|-------|----------------|
| **Migrations** | `pomodoro_settings` (user_id unique); `focus_sessions` (focusable_type/focusable_id nullable for breaks; type, sequence_number, completed, started_at, ended_at, paused_seconds, payload) |
| **Models** | `PomodoroSetting`, `FocusSession`; User `pomodoroSetting()`, Task `focusSessions()` |
| **Enums** | `FocusSessionType` (work, short_break, long_break); `ActivityLogAction::FocusSessionCompleted` |
| **Validation** | UpdatePomodoroSettings (FormRequest or rules); start/complete session payloads |
| **DTOs (optional)** | UpdatePomodoroSettingsDto, StartFocusSessionDto, CompleteFocusSessionDto |
| **Actions** | GetOrCreatePomodoroSettings, UpdatePomodoroSettings; StartFocusSession, CompleteFocusSession, AbandonFocusSession, GetActiveFocusSession |
| **Services** | PomodoroSettingsService (optional), FocusSessionService |
| **Traits** | HandlesFocusSessions (start, complete, abandon, getActive, optional startBreak); HandlesPomodoroSettings (get, update) for settings UI |
| **Policies** | FocusSessionPolicy, PomodoroSettingPolicy |
| **Config** | `config/pomodoro.php` with defaults and limits |

---

*Backend layers for the full Pomodoro implementation. Implements [focus-mode-pomodoro-behaviours.md](focus-mode-pomodoro-behaviours.md).*
