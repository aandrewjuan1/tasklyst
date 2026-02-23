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
- A scheduled command (or manual trigger) fetches the ICS, parses VEVENTs, and upserts into `events` using `source_type` + `source_id` to avoid duplicates and allow updates.
- Events created from a feed are first-class `Event` records but are identifiable as read-only/synced (no activity log, optional UI guard via policy or attribute).

### Conventions Used

- **Tables:** Plural snake_case (`calendar_feeds`).
- **Actions:** `App\Actions\CalendarFeed\{Verb}CalendarFeedAction`, inject services, `execute(User, Dto)` or `execute(CalendarFeed)`.
- **Services:** `CalendarFeedService` (feed CRUD + orchestrate sync), `IcsParserService` (parse ICS string → array of event data). Event persistence from sync does **not** call `ActivityLogRecorder`.
- **DTOs:** `CreateCalendarFeedDto` (and optionally `SyncCalendarFeedDto` if needed) with `fromValidated()` and `toServiceAttributes()`.
- **Validation:** `App\Support\Validation\CalendarFeedPayloadValidation` with `rules()` and `defaults()` (and optional `rulesForProperty()` / `allowedUpdateProperties()` if we add update-feed later).
- **Traits (Livewire Concerns):** `App\Livewire\Concerns\HandlesCalendarFeeds` — used by the **workspace index** (`resources/views/pages/workspace/⚡index/index.php`). Alpine/frontend calls index trait methods via `$wire` (e.g. `$wire.connectCalendarFeed(payload)`, `$wire.loadCalendarFeeds()`). Same pattern as the index’s HandlesEvents, HandlesTasks, HandlesTrash on the index.
- **Enums:** `EventSourceType` (e.g. `Manual`, `Brightspace`) for `events.source_type`; optional `CalendarFeedSource` for `calendar_feeds.source` if we support multiple feed types later.

### Backend layers checklist (consistency audit)

| Layer | In plan? | Notes |
|-------|----------|--------|
| **Migrations** | Yes | `create_calendar_feeds_table`, `add_source_columns_to_events_table`. |
| **Models** | Yes | `CalendarFeed`, extend `Event`. |
| **Enums** | Yes | `EventSourceType`; optional `CalendarFeedSource`. |
| **Services** | Yes | `IcsParserService`, `CalendarFeedSyncService`, `CalendarFeedService`; optional `EventService::createOrUpdateEventFromFeed`. |
| **Actions** | Yes | `ConnectCalendarFeedAction`, `SyncCalendarFeedAction`, `DisconnectCalendarFeedAction`. |
| **DTOs** | Yes | `CreateCalendarFeedDto` (`fromValidated`, `toServiceAttributes`). |
| **Validation** | Yes | `CalendarFeedPayloadValidation` (`defaults()`, `rules()`). Key rules to match Livewire properties (e.g. `feedUrl`, `feedName` or `calendarFeedPayload.feedUrl`). |
| **Traits (Concerns)** | Yes | `HandlesCalendarFeeds` — used by the **workspace index** (index.php); frontend calls index via `$wire`. |
| **Policies** | Yes | `CalendarFeedPolicy`; optional guard in `EventPolicy`. |
| **Console / Schedule** | Yes | `SyncCalendarFeedsCommand`, `routes/console.php`. |
| **Form Requests** | N/A | This app uses `Support\Validation\*PayloadValidation` classes, not Form Request classes. Plan uses that. |

---

## Phase 1: Schema & Core Models

**Goal:** Add storage for calendar feeds and extend events so they can be linked to an external source.

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

### 1.2 Migration: `add_source_columns_to_events_table`

- **File:** `database/migrations/YYYY_MM_DD_HHMMSS_add_source_columns_to_events_table.php`
- **Changes on `events`:**
  - `source_type` (string, nullable) — e.g. `'manual'`, `'brightspace'`. Use enum-backed values.
  - `source_id` (string, nullable) — external UID from ICS (e.g. `6606-927@eac.brightspace.com`)
  - Optional: `calendar_feed_id` (foreign key to `calendar_feeds.id`, nullable, null on delete) — so we know which feed an event came from (helps on disconnect).
- **Unique index:** `(user_id, source_type, source_id)` where `source_type` and `source_id` are not null — ensures one event per external UID per user for upsert.
- **Note:** If you re-add `location` for display of Brightspace LOCATION, add a nullable `location` column in this or a separate migration.

### 1.3 Enum: `EventSourceType`

- **File:** `app/Enums/EventSourceType.php`
- **Cases:** `Manual = 'manual'`, `Brightspace = 'brightspace'`. Optionally `Ical = 'ical'` for generic ICS later.
- **Methods (optional):** `label()` for UI; `isExternal(): bool` (true for non-Manual) to drive read-only behavior in policy or attributes.

### 1.4 Model: `CalendarFeed`

- **File:** `app/Models/CalendarFeed.php`
- **Fillable:** `user_id`, `name`, `feed_url`, `source`, `sync_enabled`, `last_synced_at`
- **Casts:** `sync_enabled` => boolean, `last_synced_at` => datetime
- **Relations:** `belongsTo(User::class)`; `hasMany(Event::class, 'calendar_feed_id')` if you added `calendar_feed_id` on events.
- **Scopes (optional):** `scopeSyncEnabled($query)` for scheduled sync.
- **Security:** Consider `$hidden = ['feed_url']` on serialization so the token is never leaked in JSON; or leave visible only to backend and never expose in API responses.

### 1.5 Model: `Event` — extend

- **File:** `app/Models/Event.php`
- **Changes:** Add `source_type`, `source_id`, and optionally `calendar_feed_id` to `$fillable`. Add cast for `source_type` => `EventSourceType::class` (nullable). Add relation `belongsTo(CalendarFeed::class, 'calendar_feed_id')` if column exists.
- **Scopes:** `scopeFromFeed($query)` (where not null `source_type`), `scopeNative($query)` (where `source_type` is null or `EventSourceType::Manual`). Optional: `scopeFromCalendarFeed($query, CalendarFeed $feed)` if `calendar_feed_id` exists.
- **Attribute/accessor (optional):** `isFromExternalFeed(): bool` for use in policy or UI.

---

## Phase 2: ICS Parsing & Sync Services

**Goal:** Fetch an ICS URL, parse VEVENTs, and upsert events without duplicating or logging as user actions.

### 2.1 Service: `IcsParserService`

- **File:** `app/Services/IcsParserService.php`
- **Responsibility:** Parse a raw ICS string and return a list of event-like arrays (or DTOs) for consumption by the sync service.
- **Public method:** `parse(string $icsContent): array` — returns array of shapes with at least: `uid`, `summary`, `dtstart`, `dtend`, `location` (nullable), `description` (nullable), `all_day` (bool).
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
    - Upsert: `Event::query()->updateOrCreate(
          ['user_id' => $user->id, 'source_type' => EventSourceType::Brightspace->value, 'source_id' => $vevent['uid']],
          ['title' => $vevent['summary'], 'description' => $vevent['description'], 'start_datetime' => $vevent['dtstart'], 'end_datetime' => $vevent['dtend'], 'all_day' => $vevent['all_day'], 'location' => $vevent['location'] ?? null, 'status' => EventStatus::Scheduled, 'calendar_feed_id' => $feed->id]
      )`. Do **not** call `ActivityLogRecorder`. Use `Event::updateOrCreate` directly (or a dedicated method on `EventService` that skips activity log).
  - After successful sync: `$feed->update(['last_synced_at' => now()])`.
- **Deletes policy:** Decide how to handle events that were previously synced but no longer appear in the feed (e.g. removed in Brightspace). Options: (A) Leave as-is; (B) Mark `status = cancelled`; (C) Delete. Document and implement one. Option (B) is often safest.

### 2.3 EventService: optional persistence helper for sync

- **File:** `app/Services/EventService.php`
- **Optional:** Add `createOrUpdateEventFromFeed(User $user, array $attributes, EventSourceType $sourceType, string $sourceId, ?int $calendarFeedId = null): Event` that performs `Event::updateOrCreate(...)` with the given keys and **does not** call `activityLogRecorder`. Use this from `CalendarFeedSyncService` if you want all event persistence to go through the service for consistency (e.g. ensuring no recurrence/tags for feed events). Otherwise, calling `Event::updateOrCreate` in `CalendarFeedSyncService` is fine.

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
  - `deleteFeed(CalendarFeed $feed): bool` — delete feed. Optionally: set `sync_enabled = false` and delete or cancel events that have `calendar_feed_id = $feed->id` (or where `source_type` + `source_id` were only ever from this feed). Implement as per product decision.

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
- **Method:** `execute(CalendarFeed $feed, ?User $actor = null): bool` — call `$this->calendarFeedService->deleteFeed($feed)`. Implement inside service: delete feed and optionally clean up events (delete or set cancelled) that belong to this feed.

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

**Goal:** Validate feed URL and name; ensure only the owner can manage feeds and optionally restrict editing of synced events.

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

### 4.3 Event policy: optional guard for synced events

- **File:** `app/Policies/EventPolicy.php`
- **Optional:** In `update` or `delete`, if you want to prevent editing/deleting synced events in the UI, add: if `$event->source_type !== null && $event->source_type !== EventSourceType::Manual`, return false (or a separate rule like “only owner can delete, but not if from feed”). Document the intended behavior.

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

**Goal:** Handle failures and optional cleanup of stale events.

### 6.1 HTTP and parsing failures

- In `CalendarFeedSyncService::sync`, catch HTTP exceptions and parsing exceptions; log and optionally set `last_synced_at` to null or leave unchanged. Do not delete existing events on fetch failure.

### 6.2 Stale events (removed from feed)

- Implement the chosen policy: leave as-is, mark cancelled, or delete. If “mark cancelled,” in the same sync pass after upserting, query events for this feed with `source_id` not in the current UID list and update `status = cancelled`. If “delete,” same pattern but delete (or soft delete) instead.

### 6.3 Feed URL secrecy

- Ensure `feed_url` is never returned in API responses if you add an API later; use `$hidden` on the model or explicit resource. In Livewire, only pass feed id and name to the frontend.

### 6.4 Idempotency

- Sync is idempotent: same feed URL and same UIDs will update existing events. No duplicate events per user per external UID thanks to the unique index.

---

## Backend File Checklist

| Layer | File | Phase |
|-------|------|--------|
| Migration | `database/migrations/*_create_calendar_feeds_table.php` | 1 |
| Migration | `database/migrations/*_add_source_columns_to_events_table.php` | 1 |
| Enum | `app/Enums/EventSourceType.php` | 1 |
| Model | `app/Models/CalendarFeed.php` | 1 |
| Model | `app/Models/Event.php` (extend) | 1 |
| Service | `app/Services/IcsParserService.php` | 2 |
| Service | `app/Services/CalendarFeedSyncService.php` | 2 |
| Service | `app/Services/EventService.php` (optional helper) | 2 |
| Service | `app/Services/CalendarFeedService.php` | 3 |
| DTO | `app/DataTransferObjects/CalendarFeed/CreateCalendarFeedDto.php` | 3 |
| Action | `app/Actions/CalendarFeed/ConnectCalendarFeedAction.php` | 3 |
| Action | `app/Actions/CalendarFeed/SyncCalendarFeedAction.php` | 3 |
| Action | `app/Actions/CalendarFeed/DisconnectCalendarFeedAction.php` | 3 |
| Trait (Concern) | `app/Livewire/Concerns/HandlesCalendarFeeds.php` | 3 |
| Validation | `app/Support/Validation/CalendarFeedPayloadValidation.php` | 4 |
| Policy | `app/Policies/CalendarFeedPolicy.php` | 4 |
| Policy | `app/Policies/EventPolicy.php` (optional guard) | 4 |
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

**Goal:** Let the user connect a Brightspace calendar from the workspace: entry point in the calendar area, modal with form (feed URL + optional name) and list of connected feeds. **How we get the link:** user copies the Brightspace calendar subscribe URL (Calendar → Settings → Subscribe) and pastes it into a “Feed URL” input; optional “How do I get this link?” expandable with short steps.

### Consistency with current frontend

The flow below matches the existing workspace structure:

- **`resources/views/pages/workspace/⚡index/index.blade.php`** — The workspace root is a single Livewire component (index). It renders: date switcher, search, filter bar, **trash popover**, **pending-invitations popover**, then a 80/20 layout: left = **list** (child Livewire: `livewire:pages::workspace.list`), right = **calendar** + **upcoming** (Blade components). Blade components in this view (trash, filter-bar, calendar, etc.) live in the index’s DOM; when they use `$wire`, `$wire` is the **index** component. The list is a **child** Livewire component and calls the parent via `$wire.$parent.$call('createTask', payload)` etc.
- **`resources/views/pages/workspace/⚡list/list.blade.php`** — List owns the “Add” dropdown and creation form. Form state is **Alpine** (x-model, formData); submit is **Livewire**: `$wire.$parent.$call('createEvent', payload)`. So: list does not use `wire:model` for the creation form; it uses Alpine and calls the parent on submit.
- **`resources/views/components/workspace/list-item-card.blade.php`** — Blade component with `wire:ignore`, Alpine for card state. Used inside the list; dispatches events and relies on parent for updates.
- **`resources/views/components/workspace/trash-popover.blade.php`** — Blade component with `wire:ignore`, Alpine for open/close and list state. Loads data via `$wire.$call('loadTrashItems')` (index). Contains **Flux modals** (e.g. `flux:modal name="delete-selected"`) opened with `$flux.modal('name').show()` from Alpine. So: trigger in layout → open popover/modal by name; server calls go to index via `$wire`.
- **`resources/views/components/workspace/filter-bar.blade.php`** — Uses `wire:model.change.live` on index’s filter properties (e.g. `filterTaskStatus`). So filter bar is in index view and binds directly to the index.

**Calendar-feeds flow aligned with the above:**

- **Backend on the index:** The **workspace index** (`index.php`) uses the **`HandlesCalendarFeeds`** trait. The index exposes: `connectCalendarFeed(payload)`, `syncCalendarFeed(feedId)`, `disconnectCalendarFeed(feedId)`, `loadCalendarFeeds()`. The frontend calls these on the **index** via **`$wire`** (e.g. `$wire.connectCalendarFeed(payload)`, `$wire.syncCalendarFeed(feedId)`, `$wire.loadCalendarFeeds()`), because the modal and calendar live in the index view so `$wire` is the index.
- **Trigger:** Add a link/button in the **calendar** Blade component (`calendar.blade.php`). On click: `$flux.modal('connect-calendar-feed').show()`. Same pattern as trash.
- **Modal in index view:** The calendar-feeds modal is rendered **inside the index view** (`index.blade.php`), not a separate Livewire component. Modal content calls **index** methods via `$wire` (e.g. `$wire.connectCalendarFeed(...)`, `$wire.syncCalendarFeed(feedId)`, `$wire.$call('loadCalendarFeeds')`). Same pattern as trash: `$wire.$call('loadTrashItems')` targets the index.
- **No separate modal component:** All calendar-feed logic lives in the index (HandlesCalendarFeeds trait); do **not** add a separate Livewire component (e.g. CalendarFeedsModal). The modal markup is embedded in **index.blade.php** (e.g. after the right column or at end of section), same way the **list** is a dedicated component embedded in the index. The modal component owns: feed list, form (feed URL, name), connect/sync/disconnect via the trait. So we do **not** add that logic to the index component; we keep it in the new component + trait so the index stays slim (consistent with “list” being its own component).
- **Form in modal:** Use **Livewire state on the index** (`wire:model` for feedUrl, feedName or calendarFeedPayload) and `wire:submit` (or Alpine) calling the index's `connectCalendarFeed`. Validation and actions run in the index's HandlesCalendarFeeds trait.
- **Flux modal by name:** Same as trash-popover’s confirm modals: `flux:modal name="connect-calendar-feed"` in the modal component’s view; calendar button calls `$flux.modal('connect-calendar-feed').show()`.
- **Loading feeds:** When the modal opens, frontend calls `$wire.$call('loadCalendarFeeds')` (index trait method). Same as trash: `$wire.$call('loadTrashItems')` on the index.

---

### 1. Entry point in calendar component

**File:** `resources/views/components/workspace/calendar.blade.php`

- In the **footer** (e.g. below the “Today” button, or a second row), add a link or button: e.g. “Calendar feeds” or “Connect calendar.”
- On click: open the Flux modal, e.g. `$flux.modal('connect-calendar-feed').show()`. The modal is rendered in the index view, so Flux will find it by name; `$wire` in the modal is the index.
- Reuse the same styling as the “Today” button (muted text, hover) so it doesn’t dominate the footer.

---

### 2. Calendar feeds modal (in index view)

**No separate Livewire component.** The modal markup lives in the **index view**; the **index** component uses **`HandlesCalendarFeeds`** and exposes the methods. Alpine / Livewire in the modal call the index via **`$wire`**.

- **Renders:** A single Flux modal: `<flux:modal name="connect-calendar-feed">` containing:
  - **Title:** e.g. “Connect Brightspace calendar.”
  - **Form:** Feed URL (required input, type `url` or `text`), placeholder “Paste your Brightspace calendar subscribe URL”; Name (optional text input), placeholder “e.g. All Courses”; Submit button “Connect.”
  - **Optional:** “How do I get this link?” (collapsible or tooltip) with short steps: Brightspace → Calendar → Settings → enable feeds → Subscribe → copy the .ics URL.
  - **List of connected feeds** (below or above the form): for each feed, show name (or “Brightspace”), last synced time, “Sync now” button, “Disconnect” button.
- **State:** `$feedUrl`, `$feedName`; list of feeds (id, name, last_synced_at, etc.) loaded from backend.
- **Backend:** Index uses **`HandlesCalendarFeeds`** trait; form submit and buttons call **index** via `$wire.connectCalendarFeed(payload)`, `$wire.syncCalendarFeed(feedId)`, `$wire.disconnectCalendarFeed(feedId)`; list data from `$wire.$call('loadCalendarFeeds')` when modal opens.
---

### 3. Where to put the modal in the index view

**File:** `resources/views/pages/workspace/⚡index/index.blade.php`

- In the **right column**, after `<x-workspace.upcoming />` (or after the sticky block that contains calendar + upcoming), add the **Flux modal** markup: `<flux:modal name="connect-calendar-feed">` with form and feed list. All `$wire` calls in the modal target the **index** (HandlesCalendarFeeds). Do not add a separate Livewire component. The calendar button opens the modal with `$flux.modal('connect-calendar-feed').show()`.’s
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
| Entry point | `resources/views/components/workspace/calendar.blade.php` (footer link/button → open modal via `$flux.modal('connect-calendar-feed').show()`) |
| Index component | `resources/views/pages/workspace/⚡index/index.php` — add `use HandlesCalendarFeeds;` and inject calendar-feed actions; exposes `connectCalendarFeed`, `syncCalendarFeed`, `disconnectCalendarFeed`, `loadCalendarFeeds` |
| Modal markup | `resources/views/pages/workspace/⚡index/index.blade.php` — `<flux:modal name="connect-calendar-feed">` with form and feed list; Alpine/Livewire call `$wire.connectCalendarFeed(...)`, `$wire.syncCalendarFeed(id)`, `$wire.$call('loadCalendarFeeds')` (index = $wire) |
| Trait | `app/Livewire/Concerns/HandlesCalendarFeeds.php` — used by the index component |
| Copy / instructions | Optional “How do I get this link?” in the modal view |

---

**End of plan.** Implement backend phases in order; Phase 1 unblocks Phase 2 and 3; Phase 4 can run in parallel with 2–3; Phase 5 after sync is working. Frontend can be implemented once the backend actions and validation are in place.
