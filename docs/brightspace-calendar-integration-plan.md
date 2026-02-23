# Brightspace Calendar Integration — Implementation Plan

This document outlines the implementation plan for connecting Brightspace (D2L) calendar feeds to Tasklyst: backend (schema, models, services, actions, validation, policies, scheduling) and frontend (workspace UI for connecting and managing feeds).

---

## Table of Contents

1. [Overview](#overview)
2. [Phase 1: Schema & Core Models](#phase-1-schema--core-models)
3. [Phase 2: ICS Parsing & Sync Services](#phase-2-ics-parsing--sync-services)
4. [Phase 3: Actions & DTOs](#phase-3-actions--dtos)
5. [Phase 4: Validation & Authorization](#phase-4-validation--authorization)
6. [Phase 5: Scheduling & Console](#phase-5-scheduling--console)
7. [Phase 6: Edge Cases & Cleanup](#phase-6-edge-cases--cleanup)
8. [Backend File Checklist](#backend-file-checklist)
9. [Backend–Frontend sync (audit)](#backendfrontend-sync-audit)
10. [Frontend Implementation Plan](#frontend-implementation-plan)
11. [Frontend File Checklist](#frontend-file-checklist)

---

## Overview

### Flow

- User provides a Brightspace calendar subscribe URL (ICS feed). The URL contains a secret token.
- System stores the feed in a new `calendar_feeds` table (per user).
- A scheduled command (or manual trigger) fetches the ICS, parses VEVENTs, and upserts into `tasks` using `source_type` + `source_id` to avoid duplicates and allow updates.
- Tasks created from a feed are first-class `Task` records but are identifiable as read-only/synced (no activity log, optional UI guard via TaskPolicy).

### Conventions Used

- **Tables:** Plural snake_case (`calendar_feeds`).
- **Actions:** `App\Actions\CalendarFeed\{Verb}CalendarFeedAction`, inject services, `execute(User, Dto)` or `execute(CalendarFeed)`.
- **Services:** `CalendarFeedService` (feed CRUD + orchestrate sync), `IcsParserService` (parse ICS string → array of event-like data). Task persistence from sync does **not** call `ActivityLogRecorder`.
- **DTOs:** `CreateCalendarFeedDto` (and optionally `SyncCalendarFeedDto` if needed) with `fromValidated()` and `toServiceAttributes()`.
- **Validation:** `App\Support\Validation\CalendarFeedPayloadValidation` with `rules()` and `defaults()` (and optional `rulesForProperty()` / `allowedUpdateProperties()` if we add update-feed later).
- **Traits (Livewire Concerns):** `App\Livewire\Concerns\HandlesCalendarFeeds` — used by the **workspace index** (`resources/views/pages/workspace/⚡index/index.php`). Alpine/frontend calls index trait methods via `$wire` (e.g. `$wire.connectCalendarFeed(payload)`, `$wire.loadCalendarFeeds()`). Same pattern as the index’s HandlesEvents, HandlesTasks, HandlesTrash on the index.
- **Enums:** `TaskSourceType` (e.g. `Manual`, `Brightspace`) for `tasks.source_type`; optional `CalendarFeedSource` for `calendar_feeds.source` if we support multiple feed types later.

### Backend layers checklist (consistency audit)

| Layer | In plan? | Notes |
|-------|----------|--------|
| **Migrations** | Yes | `create_calendar_feeds_table`, `add_source_columns_to_tasks_table`. |
| **Models** | Yes | `CalendarFeed`, extend `Task`. |
| **Enums** | Yes | `TaskSourceType`; optional `CalendarFeedSource`. |
| **Services** | Yes | `IcsParserService`, `CalendarFeedSyncService`, `CalendarFeedService`; optional `TaskService` helper for feed sync. |
| **Actions** | Yes | `ConnectCalendarFeedAction`, `SyncCalendarFeedAction`, `DisconnectCalendarFeedAction`. |
| **DTOs** | Yes | `CreateCalendarFeedDto` (`fromValidated`, `toServiceAttributes`). |
| **Validation** | Yes | `CalendarFeedPayloadValidation` (`defaults()`, `rules()`). Key rules to match Livewire properties (e.g. `feedUrl`, `feedName` or `calendarFeedPayload.feedUrl`). |
| **Traits (Concerns)** | Yes | `HandlesCalendarFeeds` — used by the **workspace index** (index.php); frontend calls index via `$wire`. |
| **Policies** | Yes | `CalendarFeedPolicy`; optional guard in `TaskPolicy`. |
| **Console / Schedule** | Yes | `SyncCalendarFeedsCommand`, `routes/console.php`. |
| **Form Requests** | N/A | This app uses `Support\Validation\*PayloadValidation` classes, not Form Request classes. Plan uses that. |

---

## Phase 1: Schema & Core Models

**Goal:** Add storage for calendar feeds and extend tasks so they can be linked to an external source.

### 1.1 Migration: `create_calendar_feeds_table`

- **File:** `database/migrations/YYYY_MM_DD_HHMMSS_create_calendar_feeds_table.php`
- **Table:** `calendar_feeds`
- **Columns:**
  - `id` (bigint unsigned, primary key, auto-increment)
  - `user_id` (foreign key to `users.id`, cascade on delete)
  - `name` (string, nullable) — e.g. "Brightspace – All Courses"
  - `feed_url` (text, not nullable) — treat as secret; store as-is or encrypted if desired
  - `source` (string, not nullable, default `'brightspace'`) — allows future `ical` or other providers
  - `sync_enabled` (boolean, default true)
  - `last_synced_at` (timestamp, nullable)
  - `created_at`, `updated_at`
- **Indexes:** `user_id` (FK index); optionally `(user_id, source)` if we expect multiple feeds per user per source.
- **Notes:** No soft deletes unless you want to retain feed history; for simplicity, hard delete is fine.

### 1.2 Migration: `add_source_columns_to_tasks_table`

- **File:** `database/migrations/YYYY_MM_DD_HHMMSS_add_source_columns_to_tasks_table.php`
- **Changes on `tasks`:**
  - `source_type` (string, nullable) — e.g. `'manual'`, `'brightspace'`. Use enum-backed values.
  - `source_id` (string, nullable) — external UID from ICS (e.g. `6606-927@eac.brightspace.com`)
  - `calendar_feed_id` (foreign key to `calendar_feeds.id`, nullable, null on delete) — so we know which feed a task came from (helps on disconnect).
- **Unique index:** `(user_id, source_type, source_id)` where `source_type` and `source_id` are not null — ensures one task per external UID per user for upsert.
- **Note:** Optional: add nullable `location` on tasks if you want to store ICS LOCATION (Task model currently has no location column).

### 1.3 Enum: `TaskSourceType`

- **File:** `app/Enums/TaskSourceType.php`
- **Cases:** `Manual = 'manual'`, `Brightspace = 'brightspace'`. Optionally `Ical = 'ical'` for generic ICS later.
- **Methods (optional):** `label()` for UI; `isExternal(): bool` (true for non-Manual) to drive read-only behavior in policy or attributes.

### 1.4 Model: `CalendarFeed`

- **File:** `app/Models/CalendarFeed.php`
- **Fillable:** `user_id`, `name`, `feed_url`, `source`, `sync_enabled`, `last_synced_at`
- **Casts:** `sync_enabled` => boolean, `last_synced_at` => datetime
- **Relations:** `belongsTo(User::class)`; `hasMany(Task::class, 'calendar_feed_id')`.
- **Scopes (optional):** `scopeSyncEnabled($query)` for scheduled sync.
- **Security:** Consider `$hidden = ['feed_url']` on serialization so the token is never leaked in JSON; or leave visible only to backend and never expose in API responses.

### 1.5 Model: `Task` — extend

- **File:** `app/Models/Task.php`
- **Changes:** Add `source_type`, `source_id`, and `calendar_feed_id` to `$fillable`. Add cast for `source_type` => `TaskSourceType::class` (nullable). Add relation `belongsTo(CalendarFeed::class, 'calendar_feed_id')`.
- **Scopes:** `scopeFromFeed($query)` (where not null `source_type`), `scopeNative($query)` (where `source_type` is null or `TaskSourceType::Manual`). Optional: `scopeFromCalendarFeed($query, CalendarFeed $feed)`.
- **Attribute/accessor (optional):** `isFromExternalFeed(): bool` for use in policy or UI.

---

## Phase 2: ICS Parsing & Sync Services

**Goal:** Fetch an ICS URL, parse VEVENTs, and upsert tasks without duplicating or logging as user actions.

### 2.1 Service: `IcsParserService`

- **File:** `app/Services/IcsParserService.php`
- **Responsibility:** Parse a raw ICS string and return a list of event-like arrays (or DTOs) for consumption by the sync service.
- **Public method:** `parse(string $icsContent): array` — returns array of shapes with at least: `uid`, `summary`, `dtstart`, `dtend`, `location` (nullable), `description` (nullable), `all_day` (bool). Task has no `all_day` column; if needed for display, either ignore or document a separate attribute/column.
- **Implementation options:**
  - Use a package (e.g. `eluceo/ical` or `spatie/icalendar-generator` for reading; or a dedicated ICS parser). Ensure the package can parse DTSTART/DTEND with timezone and VALUE=DATE for all-day.
  - Or implement a minimal parser (regex or line-by-line) for the subset of properties you need (UID, SUMMARY, DTSTART, DTEND, LOCATION, DESCRIPTION). Handle line folding (lines starting with space/tab continue previous line). Normalize dates to Carbon or DateTime for the sync layer.
- **Error handling:** Return empty array or throw a dedicated exception on malformed ICS; document behavior so callers can decide.

### 2.2 Service: `CalendarFeedSyncService`

- **File:** `app/Services/CalendarFeedSyncService.php`
- **Dependencies:** Inject `IcsParserService` and optionally `Http` client (or use `Illuminate\Support\Facades\Http` in the method).
- **Public method:** `sync(CalendarFeed $feed): void` (or return a result DTO with counts created/updated/failed).
  - Fetch: `Http::timeout(15)->get($feed->feed_url)`. Validate response successful; get body as string.
  - Parse: `$this->icsParserService->parse($body)`.
  - For each parsed item:
    - Resolve `user_id` from `$feed->user_id`.
    - Upsert: `Task::query()->updateOrCreate(
          ['user_id' => $user->id, 'source_type' => TaskSourceType::Brightspace->value, 'source_id' => $vevent['uid']],
          ['title' => $vevent['summary'], 'description' => $vevent['description'], 'start_datetime' => $vevent['dtstart'], 'end_datetime' => $vevent['dtend'], 'calendar_feed_id' => $feed->id, 'status' => TaskStatus::ToDo, 'priority' => TaskPriority::Medium, ...]
      )`. Omit or set null: `project_id`, `event_id`, recurrence. Do **not** call `ActivityLogRecorder`. Use `Task::updateOrCreate` directly (or a dedicated method on `TaskService` that skips activity log).
  - After successful sync: `$feed->update(['last_synced_at' => now()])`.
- **Stale tasks:** Leave as-is. Tasks that were previously synced but no longer appear in the feed (e.g. removed in Brightspace) are not deleted or changed; they remain in the user's list until the user removes them.

### 2.3 TaskService: optional persistence helper for sync

- **Optional:** Add a method (e.g. on `TaskService` if one exists) that creates/updates a task from feed data without recording activity log. Use it from `CalendarFeedSyncService` for consistency (e.g. ensuring no recurrence/tags for feed tasks). Otherwise, calling `Task::updateOrCreate` in `CalendarFeedSyncService` is fine.

---

## Phase 3: Actions & DTOs

**Goal:** Application-layer actions for connecting, syncing, and disconnecting feeds; DTOs for validated input.

### 3.1 DTO: `CreateCalendarFeedDto`

- **File:** `app/DataTransferObjects/CalendarFeed/CreateCalendarFeedDto.php`
- **Properties:** `string $feedUrl`, `?string $name`, `string $source` (default `'brightspace'`).
- **Static:** `fromValidated(array $validated): self` — map from validation payload (e.g. `feedUrl`, `name`).
- **Method:** `toServiceAttributes(): array` — return `['feed_url' => ..., 'name' => ..., 'source' => ...]` for `CalendarFeedService::createFeed`.

### 3.2 Service: `CalendarFeedService`

- **File:** `app/Services/CalendarFeedService.php`
- **Responsibility:** CRUD for calendar feeds (no parsing/sync).
- **Methods:**
  - `createFeed(User $user, array $attributes): CalendarFeed` — create `CalendarFeed` with `user_id`, `sync_enabled => true`. No activity log unless you add a dedicated “feed connected” log type.
  - `updateFeed(CalendarFeed $feed, array $attributes): CalendarFeed` — update name, sync_enabled, etc. Do not allow updating `feed_url` without validation (e.g. same host or re-validation).
  - `deleteFeed(CalendarFeed $feed): bool` — delete feed. Optionally: leave tasks that have `calendar_feed_id = $feed->id` as-is, or soft-delete them; document the chosen behavior.

### 3.3 Action: `ConnectCalendarFeedAction`

- **File:** `app/Actions/CalendarFeed/ConnectCalendarFeedAction.php`
- **Constructor:** Inject `CalendarFeedService` and optionally `CalendarFeedSyncService`.
- **Method:** `execute(User $user, CreateCalendarFeedDto $dto): CalendarFeed`
  - Call `$this->calendarFeedService->createFeed($user, $dto->toServiceAttributes())`.
  - Optionally trigger first sync: `$this->calendarFeedSyncService->sync($feed)` (or dispatch a job/command). Document whether first sync is sync or async.

### 3.4 Action: `SyncCalendarFeedAction`

- **File:** `app/Actions/CalendarFeed/SyncCalendarFeedAction.php`
- **Constructor:** Inject `CalendarFeedSyncService`.
- **Method:** `execute(CalendarFeed $feed): void` — call `$this->calendarFeedSyncService->sync($feed)`. Return void or a result object (e.g. counts) if needed for UI.

### 3.5 Action: `DisconnectCalendarFeedAction` (or `DeleteCalendarFeedAction`)

- **File:** `app/Actions/CalendarFeed/DisconnectCalendarFeedAction.php`
- **Constructor:** Inject `CalendarFeedService`.
- **Method:** `execute(CalendarFeed $feed, ?User $actor = null): bool` — call `$this->calendarFeedService->deleteFeed($feed)`. Implement inside service: delete feed and optionally clean up tasks that belong to this feed (leave as-is or soft-delete per product decision).

### 3.6 Trait: `HandlesCalendarFeeds` (Livewire Concern)

- **File:** `app/Livewire/Concerns/HandlesCalendarFeeds.php`
- **Used by:** The **workspace index** component (`resources/views/pages/workspace/⚡index/index.php`). Add `use HandlesCalendarFeeds;` and inject the three actions in the index's constructor, same as `HandlesEvents`, `HandlesTrash`, etc. The frontend (Alpine.js in the calendar area and in the calendar-feeds modal) calls these methods on the **index** via `$wire` — e.g. `$wire.connectCalendarFeed(payload)`, `$wire.syncCalendarFeed(feedId)`, `$wire.disconnectCalendarFeed(feedId)`, `$wire.loadCalendarFeeds()`.
- **Dependencies (index provides):** `ConnectCalendarFeedAction`, `SyncCalendarFeedAction`, `DisconnectCalendarFeedAction`; optional `CalendarFeedService` or direct `CalendarFeed::query()->where('user_id', $user->id)` for listing.
- **Methods (all called from frontend via $wire on the index):**
  - `connectCalendarFeed(array $payload): void` — Merge payload with `CalendarFeedPayloadValidation::defaults()`, validate with `CalendarFeedPayloadValidation::rules()` (keys match index properties, e.g. `calendarFeedPayload.feedUrl` or top-level `feedUrl`, `feedName`). Build `CreateCalendarFeedDto::fromValidated()`, call `ConnectCalendarFeedAction::execute($user, $dto)`, dispatch toast, clear form, refresh feed list. Use `$this->authorize('create', CalendarFeed::class)` and `requireAuth()` like other concerns.
  - `syncCalendarFeed(int $feedId): void` — Resolve feed for current user, authorize `update`, call `SyncCalendarFeedAction::execute($feed)`, toast, refresh list.
  - `disconnectCalendarFeed(int $feedId): void` — Resolve feed, authorize `delete`, call `DisconnectCalendarFeedAction::execute($feed)`, toast, refresh list.
  - `loadCalendarFeeds(): array` (or `#[Computed] calendarFeeds`) — Return list of feeds for the authenticated user (id, name, last_synced_at, etc.) for the modal. Called by the frontend when the modal opens (e.g. Alpine `$wire.$call('loadCalendarFeeds')`), same pattern as `loadTrashItems` in `HandlesTrash`.
- **Validation key:** If the index uses a single payload property (e.g. `$calendarFeedPayload`), use rules keyed `calendarFeedPayload.feedUrl`, `calendarFeedPayload.name`; if top-level `$feedUrl`, `$feedName`, use rules `feedUrl`, `feedName`. Match the index's public properties.

---

## Phase 4: Validation & Authorization

**Goal:** Validate feed URL and name; ensure only the owner can manage feeds and optionally restrict editing of synced tasks.

### 4.1 Validation: `CalendarFeedPayloadValidation`

- **File:** `app/Support/Validation/CalendarFeedPayloadValidation.php`
- **Static methods:** `defaults(): array` (e.g. `'feedUrl' => '', 'name' => null` or `'calendarFeedPayload' => ['feedUrl' => '', 'name' => null]` to match other validations), `rules(): array` for validation rules.
- **Rules:** Key rules to match the Livewire component’s property structure (same pattern as `EventPayloadValidation` with `eventPayload.*`):
  - If using a single payload: `calendarFeedPayload.feedUrl`, `calendarFeedPayload.name`; if using top-level properties: `feedUrl`, `feedName`.
  - `feedUrl`: required, string, url, max length; optionally `url` rule with allowed host (e.g. `*brightspace.com*` or config-driven).
  - `name`: nullable, string, max:255.
- **Optional (if update feed is added later):** `allowedUpdateProperties(): array`, `rulesForProperty(string $property): array` (same pattern as `EventPayloadValidation` / `TaskPayloadValidation`).
- **Usage:** Called from `HandlesCalendarFeeds` (trait on index) when handling “connect feed” or “update feed” payloads.

### 4.2 Policy: `CalendarFeedPolicy`

- **File:** `app/Policies/CalendarFeedPolicy.php`
- **Methods:** `viewAny(User $user): bool`, `view(User $user, CalendarFeed $feed): bool` (owner only), `create(User $user): bool`, `update(User $user, CalendarFeed $feed): bool` (owner only), `delete(User $user, CalendarFeed $feed): bool` (owner only). Use `$feed->user_id === $user->id` for owner checks.
- **Register:** Ensure `CalendarFeed` model is registered in `AuthServiceProvider` or the policy discovery picks it up (Laravel auto-discovers by convention).

### 4.3 Task policy: optional guard for synced tasks

- **File:** `app/Policies/TaskPolicy.php`
- **Optional:** In `update` or `delete`, if you want to prevent editing synced tasks in the UI: if `$task->source_type !== null && $task->source_type !== TaskSourceType::Manual`, return false for update (and optionally allow delete so the user can remove a synced task from their list). Document the intended behavior.

---

## Phase 5: Scheduling & Console

**Goal:** Run sync periodically and optionally expose a manual sync command.

### 5.1 Console command: `calendar:sync-feeds`

- **File:** `app/Console/Commands/SyncCalendarFeedsCommand.php`
- **Signature:** `calendar:sync-feeds` (or `calendar:sync`).
- **Behavior:** Query `CalendarFeed::where('sync_enabled', true)->get()` and for each call `SyncCalendarFeedAction::execute($feed)` (or `CalendarFeedSyncService::sync($feed)`). Log success/failure per feed; do not fail the command if one feed fails (log and continue).
- **Optional:** Add `--feed-id=...` to sync a single feed.

### 5.2 Schedule registration

- **File:** `routes/console.php`
- **Add:** `Schedule::command('calendar:sync-feeds')->everyThirtyMinutes();` (or `hourly()` depending on desired frequency). Ensure the scheduler is running (e.g. cron for `php artisan schedule:run`).

---

## Phase 6: Edge Cases & Cleanup

**Goal:** Handle failures and optional cleanup of stale tasks.

### 6.1 HTTP and parsing failures

- In `CalendarFeedSyncService::sync`, catch HTTP exceptions and parsing exceptions; log and optionally set `last_synced_at` to null or leave unchanged. Do not delete or change existing tasks on fetch failure.

### 6.2 Stale tasks (removed from feed)

- **Leave as-is.** Tasks that were previously synced but no longer appear in the feed are not deleted or changed; they remain in the user's list until the user removes them.

### 6.3 Feed URL secrecy

- Ensure `feed_url` is never returned in API responses if you add an API later; use `$hidden` on the model or explicit resource. In Livewire, only pass feed id and name to the frontend.

### 6.4 Idempotency

- Sync is idempotent: same feed URL and same UIDs will update existing tasks. No duplicate tasks per user per external UID thanks to the unique index on `(user_id, source_type, source_id)` on `tasks`.

---

## Backend File Checklist

| Layer | File | Phase |
|-------|------|--------|
| Migration | `database/migrations/*_create_calendar_feeds_table.php` | 1 |
| Migration | `database/migrations/*_add_source_columns_to_tasks_table.php` | 1 |
| Enum | `app/Enums/TaskSourceType.php` | 1 |
| Model | `app/Models/CalendarFeed.php` | 1 |
| Model | `app/Models/Task.php` (extend) | 1 |
| Service | `app/Services/IcsParserService.php` | 2 |
| Service | `app/Services/CalendarFeedSyncService.php` | 2 |
| Service | `app/Services/TaskService.php` (optional helper) | 2 |
| Service | `app/Services/CalendarFeedService.php` | 3 |
| DTO | `app/DataTransferObjects/CalendarFeed/CreateCalendarFeedDto.php` | 3 |
| Action | `app/Actions/CalendarFeed/ConnectCalendarFeedAction.php` | 3 |
| Action | `app/Actions/CalendarFeed/SyncCalendarFeedAction.php` | 3 |
| Action | `app/Actions/CalendarFeed/DisconnectCalendarFeedAction.php` | 3 |
| Trait (Concern) | `app/Livewire/Concerns/HandlesCalendarFeeds.php` | 3 |
| Validation | `app/Support/Validation/CalendarFeedPayloadValidation.php` | 4 |
| Policy | `app/Policies/CalendarFeedPolicy.php` | 4 |
| Policy | `app/Policies/TaskPolicy.php` (optional guard) | 4 |
| Command | `app/Console/Commands/SyncCalendarFeedsCommand.php` | 5 |
| Schedule | `routes/console.php` (register command) | 5 |

---

## Backend–Frontend sync (audit)

| Backend (trait / validation / DTO) | Frontend (index view / Alpine / $wire) | Aligned? |
|-----------------------------------|----------------------------------------|----------|
| **HandlesCalendarFeeds** on index (index.php) | Modal and calendar live in index view; `$wire` = index. | Yes |
| `connectCalendarFeed(array $payload)` | Form submit or Alpine calls `$wire.connectCalendarFeed(payload)`. Payload keys must match validation (see below). | Yes |
| `syncCalendarFeed(int $feedId)` | Button calls `$wire.syncCalendarFeed(feed.id)`. | Yes |
| `disconnectCalendarFeed(int $feedId)` | Button calls `$wire.disconnectCalendarFeed(feed.id)`. | Yes |
| `loadCalendarFeeds(): array` | When modal opens: `$wire.$call('loadCalendarFeeds')`. Response: list of `{ id, name, last_synced_at, ... }` (no `feed_url`). | Yes |
| **Payload for connect** | Use (A) index props `$feedUrl`, `$feedName` with rules `feedUrl`, `feedName` and pass `$validated` to DTO; or (B) `$calendarFeedPayload` with rules `calendarFeedPayload.feedUrl` / `.name` and pass `$validated['calendarFeedPayload']` to DTO (same pattern as eventPayload in HandlesEvents). | Must match |
| **CalendarFeedPayloadValidation** | Rule keys must match index form property(ies). | Yes |
| **CreateCalendarFeedDto::fromValidated()** | Expects flat array: `feedUrl`, `name`, optionally `source`. Trait passes the correct slice. | Yes |
| **CalendarFeedPolicy** | Trait authorizes create/update/delete; owner checks. | Yes |

---

## Frontend Implementation Plan

**Goal:** Let the user connect a Brightspace calendar from the workspace: entry point in the **upcoming** area, with a **popover** (custom Blade component) that contains the form (feed URL + optional name) and list of connected feeds. **How we get the link:** user copies the Brightspace calendar subscribe URL (Calendar → Settings → Subscribe) and pastes it into a “Feed URL” input; optional “How do I get this link?” expandable with short steps.

### Consistency with current frontend

The flow below matches the existing workspace structure:

- **`resources/views/pages/workspace/⚡index/index.blade.php`** — The workspace root is a single Livewire component (index). It renders: date switcher, search, filter bar, **trash popover**, **pending-invitations popover**, then a 80/20 layout: left = **list** (child Livewire: `livewire:pages::workspace.list`), right = **calendar** + **upcoming** (Blade components). Blade components in this view (trash, filter-bar, calendar, upcoming, etc.) live in the index’s DOM; when they use `$wire`, `$wire` is the **index** component. The list is a **child** Livewire component and calls the parent via `$wire.$parent.$call('createTask', payload)` etc.
- **`resources/views/pages/workspace/⚡list/list.blade.php`** — List owns the “Add” dropdown and creation form. Form state is **Alpine** (x-model, formData); submit is **Livewire**: `$wire.$parent.$call('createEvent', payload)`. So: list does not use `wire:model` for the creation form; it uses Alpine and calls the parent on submit.
- **`resources/views/components/workspace/list-item-card.blade.php`** — Blade component with `wire:ignore`, Alpine for card state. Used inside the list; dispatches events and relies on parent for updates.
- **`resources/views/components/workspace/trash-popover.blade.php`** — Blade component with `wire:ignore`, Alpine for open/close and list state. Loads data via `$wire.$call('loadTrashItems')` (index). Uses a **custom popover-style panel** anchored to a trigger button; server calls go to the index via `$wire`.
- **`resources/views/components/workspace/filter-bar.blade.php`** — Uses `wire:model.change.live` on index’s filter properties (e.g. `filterTaskStatus`). So filter bar is in index view and binds directly to the index.

**Calendar-feeds flow aligned with the above:**

- **Backend on the index:** The **workspace index** (`index.php`) uses the **`HandlesCalendarFeeds`** trait. The index exposes: `connectCalendarFeed(payload)`, `syncCalendarFeed(feedId)`, `disconnectCalendarFeed(feedId)`, `loadCalendarFeeds()`. The frontend calls these on the **index** via **`$wire`** (e.g. `$wire.connectCalendarFeed(payload)`, `$wire.syncCalendarFeed(feedId)`, `$wire.loadCalendarFeeds()`), because the calendar-feeds popover lives in the index view so `$wire` is the index.
- **Trigger in upcoming:** Add a subtle link/button in the **upcoming** Blade component (`resources/views/components/workspace/upcoming.blade.php`) that opens a **calendar-feeds popover**, similar to how `pending-invitations-popover` and `collaborators-popover` work.
- **Custom popover component:** Implement a new Blade component, e.g. `resources/views/components/workspace/calendar-feeds-popover.blade.php`, that encapsulates the trigger and popover panel. It uses Alpine for open/close and positioning, mirroring the behavior of `date-picker`, `recurring-selection`, `workspace/pending-invitations-popover`, or `workspace/collaborators-popover`.
- **Form & list in popover:** Inside the popover panel, render the connect form and list of feeds. Use **Livewire state on the index** (`wire:model` or Alpine payload + `$wire.connectCalendarFeed(payload)`) and call `$wire.syncCalendarFeed(feedId)`, `$wire.disconnectCalendarFeed(feedId)` for per-feed actions.
- **Loading feeds:** When the popover opens, frontend calls `$wire.$call('loadCalendarFeeds')` (index trait method) to fetch feeds, similar to how trash and collaborators popovers fetch their data.

---

### 1. Entry point in upcoming component

**File:** `resources/views/components/workspace/upcoming.blade.php`

- In the **upcoming** header or footer, add a small link/button or chip: e.g. “Brightspace calendar” or “Connect calendar.”
- That trigger should render/use the new calendar-feeds popover component, e.g. `<x-workspace.calendar-feeds-popover />`, and delegate open/close behavior to that component’s Alpine logic.
- Reuse styling similar to other subtle controls in the upcoming area so it doesn’t dominate the panel.

---

### 2. Calendar feeds popover (in index view)

**No separate Livewire component.** The calendar-feeds UI lives in a **Blade popover component** that is rendered inside the **index view**; the **index** component uses **`HandlesCalendarFeeds`** and exposes the methods. Alpine / Livewire inside the popover call the index via **`$wire`**.

- **Renders:** A custom popover component, e.g. `<x-workspace.calendar-feeds-popover>`, containing:
  - **Title:** e.g. “Connect Brightspace calendar.”
  - **Form:** Feed URL (required input, type `url` or `text`), placeholder “Paste your Brightspace calendar subscribe URL”; Name (optional text input), placeholder “e.g. All Courses”; Submit button “Connect.”
  - **Optional:** “How do I get this link?” (collapsible or tooltip) with short steps: Brightspace → Calendar → Settings → enable feeds → Subscribe → copy the .ics URL. Optionally add a line that synced items (assignments, quizzes, exams) will appear as tasks in the workspace list.
  - **List of connected feeds** (below or above the form): for each feed, show name (or “Brightspace”), last synced time, “Sync now” button, “Disconnect” button.
- **State:** `$feedUrl`, `$feedName` (or a payload object) and a list of feeds (id, name, last_synced_at, etc.) loaded from backend.
- **Popover behavior:** Use Alpine to:
  - Track `open` state.
  - Compute panel placement classes based on trigger position and viewport (same pattern as `pending-invitations-popover` and `collaborators-popover`).
  - Close on outside click, Escape, and focus-out.
- **Backend:** Index uses **`HandlesCalendarFeeds`** trait; form submit and buttons call **index** via `$wire.connectCalendarFeed(payload)`, `$wire.syncCalendarFeed(feedId)`, `$wire.disconnectCalendarFeed(feedId)`; list data from `$wire.$call('loadCalendarFeeds')` when the popover opens.
---

### 3. Where to put the popover in the index view

**File:** `resources/views/pages/workspace/⚡index/index.blade.php`

- In the **right column**, ensure the calendar-feeds popover component is rendered in the same Livewire index DOM tree as `upcoming`, so `$wire` refers to the index. For example:
  - Render `<x-workspace.calendar-feeds-popover />` near `<x-workspace.upcoming />`, and have upcoming reference it visually; or
  - Render the popover inside `upcoming.blade.php`, but still within the index component.
- All `$wire` calls in the popover target the **index** (HandlesCalendarFeeds). Do not add a separate Livewire component for calendar feeds.
---

### 4. Optional: “How do I get this link?” content

- Short steps, e.g.: (1) In Brightspace, open Calendar; (2) Settings (or gear); (3) Turn on Calendar Feeds, click Subscribe; (4) Choose calendar (e.g. “All Courses”), copy the subscribe URL; (5) Paste it in the “Feed URL” field above. Can be a collapsible section or a tooltip next to the Feed URL label.

---

### 5. UX details

- **After connect:** Clear URL/name, show success toast, refresh feed list so the new feed appears with “Sync now” / “Disconnect.”
- **Sync now:** Loading state on the button for that feed; on success, update “Last synced” and toast.
- **Disconnect:** Optional confirmation then run disconnect and refresh list.
- **Errors:** Show validation errors under the form; do not close the modal so the user can fix the URL.

---

## Frontend File Checklist

| Item | File / location |
|------|------------------|
| Entry point | `resources/views/components/workspace/upcoming.blade.php` (header/footer control → uses calendar-feeds popover component) |
| Popover component | `resources/views/components/workspace/calendar-feeds-popover.blade.php` — trigger + popover panel with form and feed list; Alpine/Livewire call `$wire.connectCalendarFeed(...)`, `$wire.syncCalendarFeed(id)`, `$wire.$call('loadCalendarFeeds')` (index = $wire) |
| Index component | `resources/views/pages/workspace/⚡index/index.php` — add `use HandlesCalendarFeeds;` and inject calendar-feed actions; exposes `connectCalendarFeed`, `syncCalendarFeed`, `disconnectCalendarFeed`, `loadCalendarFeeds` |
| Trait | `app/Livewire/Concerns/HandlesCalendarFeeds.php` — used by the index component |
| Copy / instructions | Optional “How do I get this link?” content inside the popover view |

---

**End of plan.** Implement backend phases in order; Phase 1 unblocks Phase 2 and 3; Phase 4 can run in parallel with 2–3; Phase 5 after sync is working. Frontend can be implemented once the backend actions and validation are in place.
