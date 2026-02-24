## How AI agents should use this file

This file is a **backend context guide for AI agents** working on this codebase.

- **Primary purpose**: keep new backend features and changes aligned with the existing layering (Volt component → traits → validation → DTOs → actions → services → models/policies).
- **Authoritative patterns**: when in doubt about where logic should live, **follow the examples and flows in this file** instead of inventing a new pattern.
- **Scope**: applies to all backend features (tasks, events, projects, focus sessions, pomodoro, calendar feeds, collaborations, comments, tags, etc.).

### Agent checklist when adding/changing backend code

- **Before coding**
  - **Identify the feature’s domain** (task, event, project, focus, pomodoro, calendar feed, collaboration, comment, tag, etc.).
  - **Locate existing analogues** (e.g. for a new task‑like entity, review `TaskService`, `HandlesTasks`, task DTOs and actions).
  - Decide which **layers** are involved:
    - Volt component / Livewire trait
    - Validation (`Support\Validation\*`)
    - DTO (`DataTransferObjects\*`)
    - Action (`Actions\*`)
    - Service (`Services\*`)
    - Model/enum/policy

- **When implementing**
  - **Do** put request orchestration in **traits on the Volt component** (`app/Livewire/Concerns/*`).
  - **Do** validate all incoming data using **support validation classes**, not ad‑hoc rules in traits or services.
  - **Do** convert validated payloads into **DTOs** before calling services/actions.
  - **Do** implement business logic in **services** (and sometimes actions), not in controllers/traits.
  - **Do** use **actions** for discrete use‑cases (create, update property, delete, skip occurrence, etc.) and call them from traits.
  - **Do** use **policies** and `$this->authorize(...)` for access control.
  - **Do** record significant changes via `ActivityLogRecorder` following existing examples.
  - **Do** reuse existing scopes/relationships on models instead of re‑implementing queries.
  - **Don’t** add new persistence or business logic directly into Volt components or Blade.
  - **Don’t** bypass services/actions when there is already a service/action for that behavior.
  - **Don’t** introduce new architectural layers or patterns unless they clearly match existing ones.

- **When wiring Livewire / Volt**
  - Expose new behavior via **traits** on `resources/views/pages/workspace/⚡index/index.php`.
  - Inject new actions/services via the Volt component’s `boot(...)` method.
  - Ensure **naming and signatures** for new trait methods match existing ones (e.g. `createX`, `updateXProperty`, `deleteX`, `skipRecurringXOccurrence`).

- **Before finishing**
  - Compare your changes against the **Feature Flow Examples** at the bottom of this file and confirm the new flow mirrors them.
  - If you added a new domain, make sure it has **model, validation, DTOs, actions, services, policies** wired similarly to tasks/events/projects.

Use the rest of this document as **reference** for concrete implementations and patterns.

---

## Backend Architecture Overview

This document describes the **current backend architecture** of the Tasklyst application, focusing on how requests flow through Livewire components, traits, DTOs, actions, services, validation, and domain models. It is intentionally written to mirror the patterns already in use, not to propose a new architecture.

At a high level, the backend follows these principles:

- **Feature‑oriented structure**: Code is grouped by domain (tasks, events, projects, focus sessions, pomodoro, calendar feeds, etc.).
- **Layered responsibilities**: Livewire components and traits handle UI interactions and orchestration; *actions* and *services* encapsulate application logic; *DTOs* carry validated data; *support* classes centralize validation and helpers; *policies* and *enums* codify authorization and domain rules.
- **Laravel 12 + Livewire Volt**: The main workspace runs as a Volt component, using Livewire attributes like `#[Title]`, `#[Async]`, `#[Computed]`, and `#[Url]`.

---

## High‑Level Request Flow

### Entry Points and Routing

- **Routes** are defined in `routes/web.php`, `routes/auth.php`, and `routes/settings.php`.
- Authenticated workspace routes are protected with:
  - The standard `auth` middleware.
  - A custom `ValidateWorkOSSession` middleware for SSO/session integrity.
- The **workspace** itself is served via Livewire:
  - `Route::livewire('/', 'pages::workspace.index');`
  - `Route::livewire('workspace', 'pages::workspace.index')->name('workspace');`

The `pages::workspace.index` Livewire component is defined using Volt in `resources/views/pages/workspace/⚡index/index.php`. This component is the primary entry point for most user interactions on the backend.

### Workspace Component and Traits

The Volt workspace component:

- Extends `Livewire\Component` and uses `AuthorizesRequests`.
- Composes many **feature traits**:
  - `HandlesTasks`, `HandlesEvents`, `HandlesProjects`, `HandlesTags`
  - `HandlesComments`, `HandlesActivityLogs`, `HandlesCollaborations`
  - `HandlesCalendarFeeds`, `HandlesFocusSessions`, `HandlesPomodoroSettings`, `HandlesTrash`, `HandlesFiltering`
- Declares **Livewire attributes**:
  - `#[Title('Workspace')]` for page title.
  - `#[Url(as: 'date')]` for `selectedDate` URL synchronization.
  - `#[Computed]` for derived properties (e.g. `pomodoroSettings`, `calendarMonth`, `calendarYear`, `overdue`, `upcoming`).
  - `#[Async]` and `#[Renderless]` for background operations and non‑rendering actions.

The component’s `boot(...)` method receives all required **services** and **actions** via dependency injection and stores them as protected properties. Traits then access these properties to perform their work, which keeps the Volt file thin while centralizing wiring in one place.

---

## Layered Backend Structure

### 1. Presentation & Orchestration Layer (Livewire + Traits)

**Where**:

- `resources/views/pages/workspace/⚡index/index.php`
- `app/Livewire/Concerns/*` (e.g. `HandlesTasks`, `HandlesProjects`, `HandlesEvents`, etc.)

**Responsibilities**:

- Accept inputs from the UI (Livewire payloads).
- Enforce authentication and authorization using:
  - `requireAuth(...)` helper on the workspace component.
  - `AuthorizesRequests` methods like `$this->authorize(...)`.
- Normalize, augment, and validate input using support validation classes (e.g. `TaskPayloadValidation`).
- Construct **DTOs** from validated payloads.
- Delegate actual work to **actions** and **services**.
- Dispatch Livewire browser events (`$this->dispatch(...)`) for toasts, front‑end sync, and UI updates.
- Expose **computed collections** for lists (e.g. tasks, overdue, upcoming) which are then rendered in Blade components.

**Example: Tasks**

- `HandlesTasks::createTask(array $payload)`:
  - Uses `requireAuth` to ensure the user is logged in.
  - Authorizes with `$this->authorize('create', Task::class)`.
  - Merges `TaskPayloadValidation::defaults()` with the incoming payload.
  - Resolves tag IDs via `TagService::resolveTagIdsFromPayload(...)`.
  - Validates the `taskPayload` against `TaskPayloadValidation::rules()` using Livewire’s `$this->validate`.
  - Ensures related `Project`/`Event` exist and are authorized for the current user.
  - Builds `CreateTaskDto::fromValidated($validatedTask)`.
  - Calls `$this->createTaskAction->execute($user, $dto)`.
  - On success/failure, updates internal state and dispatches toast and Livewire events.

- `HandlesTasks::updateTaskProperty(...)`:
  - Ensures the user is authenticated and the `Task` exists and is authorized.
  - Enforces **ownership‑only** constraints for certain fields (`startDatetime`, `endDatetime`, `recurrence`, `tagIds`).
  - Checks `TaskPayloadValidation::allowedUpdateProperties()` to guard against invalid properties.
  - Looks up property‑specific rules via `TaskPayloadValidation::rulesForProperty($property)` and validates with `Validator::make`.
  - Delegates the actual update to `UpdateTaskPropertyAction::execute(...)`.
  - Uses the returned `UpdateTaskPropertyResult` to decide whether to show success/error toasts and to refresh UI.

- `HandlesTasks::tasks()`:
  - Builds an Eloquent query for tasks with all necessary relationships (`project`, `event`, `user`, `recurringTask`, `tags`, `collaborations`, etc.) and scopes (`forUser`, `relevantForDate`).
  - Applies filters (`applyTaskFilters`) and search (`applySearchToQuery`).
  - Applies pagination and determines whether there are more tasks to load (`hasMoreTasks`).
  - When date‑scoped (not “search all items”), calls `TaskService::processRecurringTasksForDate(...)` to expand recurrence and attach `instanceForDate` and `effectiveStatusForDate` to each task.

This pattern repeats for other features (events, projects, calendar feeds, focus sessions, pomodoro) via their own `Handles*` traits.

---

### 2. Validation & Defaults Layer (Support\Validation)

**Where**:

- `app\Support\Validation\*` (e.g. `TaskPayloadValidation`, `EventExceptionPayloadValidation`, `TagPayloadValidation`, etc.)

**Responsibilities**:

- Provide **default payload structures** used by Livewire traits to prefill form state.
- Centralize **validation rules** for both full payloads and individual property updates.
- Enforce domain‑specific invariants that are shared between front‑end and back‑end (e.g. allowed status/priority/complexity enums, duration limits, recurrence rules).

**Example: `TaskPayloadValidation`**

- `defaults()` defines the shape of `taskPayload`, including `title`, `description`, `status`, `priority`, `complexity`, `duration`, `startDatetime`, `endDatetime`, `projectId`, `eventId`, `tagIds`, `pendingTagNames`, and nested `recurrence`.
- `rules()` returns Livewire rules for `taskPayload.*`, leveraging:
  - Enum classes (`TaskStatus`, `TaskPriority`, `TaskComplexity`, `TaskRecurrenceType`).
  - Existence checks tied to the authenticated user (e.g. tag IDs belong to the user).
- `allowedUpdateProperties()` whitelists which properties can be updated inline.
- `rulesForProperty($property)` returns compact rules for validating a single property via a `value` key, supporting inline updates.
- `validateTaskDateRangeForUpdate(...)` encapsulates logic ensuring:
  - End date is not before start date.
  - When start and end are same day and duration is set, end time is at least `duration` minutes after start.

This validation layer keeps Livewire traits thin and consistent while ensuring that both bulk and inline operations share the same business rules.

---

### 3. Data Transfer Objects (DTOs)

**Where**:

- `app\DataTransferObjects\*` (e.g. `Task\CreateTaskDto`, `Task\CreateTaskExceptionDto`, `Event\CreateEventExceptionDto`, etc.)

**Responsibilities**:

- Represent **immutable, validated data** flowing between Livewire/validation and the action/service layers.
- Convert from **UI‑oriented payloads** (camelCase, nested structures) to **service‑friendly attributes** (snake_case, Eloquent expectations).

**Example: `CreateTaskDto`**

- Constructor fields capture all relevant task information, including:
  - Core fields (`title`, `description`, `status`, `priority`, `complexity`, `duration`).
  - Temporal fields (`startDatetime`, `endDatetime` as `Carbon` instances).
  - Relations (`projectId`, `eventId`).
  - Tags (`tagIds`) and recurrence data.
- `fromValidated(array $validated)`:
  - Accepts the already validated `taskPayload`.
  - Normalizes types (cast to `string`, `int`, etc.).
  - Uses `DateHelper::parseOptional` to convert date strings into `Carbon` instances.
  - Normalizes recurrence so that disabled recurrence becomes `null`.
- `toServiceAttributes()`:
  - Produces the exact attribute shape expected by `TaskService::createTask` (`start_datetime`, `end_datetime`, `project_id`, `event_id`, etc.).

DTOs ensure that actions and services receive strongly‑typed, domain‑friendly data free of Livewire or front‑end concerns.

---

### 4. Actions (Use‑Case Layer)

**Where**:

- `app/Actions/*` (grouped by feature: `Task`, `Event`, `Project`, `Pomodoro`, `FocusSession`, `Collaboration`, `CalendarFeed`, etc.)

**Responsibilities**:

- Represent **application‑level use cases** (e.g. “update a task property”, “create a task”, “start a focus session”, “sync a calendar feed”).
- Orchestrate:
  - DTOs and validated payloads.
  - Domain services and models.
  - Activity logging via `ActivityLogRecorder`.
  - Side effects like tag synchronization, recurrence management, and exceptions.
- Return **result DTOs** or primitives to the Livewire layer, simplifying error handling and UI messaging.

**Example: `UpdateTaskPropertyAction`**

- Dependencies:
  - `ActivityLogRecorder` (for domain audit trail).
  - `TaskService` (for recurring logic and core updates).
- `execute(Task $task, string $property, mixed $validatedValue, ?string $occurrenceDate = null)`:
  - Routes to specialized private methods depending on `$property`:
    - `updateTagIds` for tag changes.
    - `updateRecurrence` for recurrence payload updates.
    - `updateRecurringStatus` when updating `status` on a recurring task (optionally for a specific occurrence date).
    - `updateSimpleProperty` for scalar changes like title, description, dates, priority, etc.
- `updateTagIds`:
  - Reads current tags, computes added/removed IDs.
  - Resolves tag names for toasts when only one tag is added/removed.
  - Calls `$task->tags()->sync($validatedValue)` in a try/catch with extensive logging.
  - Records an `ActivityLogAction::FieldUpdated` entry.
  - Returns `UpdateTaskPropertyResult::success(...)` or `::failure(...)`.
- `updateRecurrence`:
  - Loads `recurringTask`, converts to a payload array, and passes new recurrence data to `TaskService::updateOrCreateRecurringTask`.
  - Logs the change via `ActivityLogRecorder`.
- `updateRecurringStatus`:
  - Ensures a `RecurringTask` exists.
  - Uses `TaskService::updateRecurringOccurrenceStatus` for a specific occurrence or `TaskService::updateTask` for the base task.
  - Records a status change in the activity log.
- `updateSimpleProperty`:
  - Maps property names to DB columns using `Task::propertyToColumn`.
  - Handles date parsing through `DateHelper` and checks date/duration consistency with `TaskPayloadValidation::validateTaskDateRangeForUpdate`.
  - Uses `TaskService::updateTask` to persist the change.
  - Records the change in `ActivityLogRecorder`.

This pattern of **thin controller/Livewire → action → service/model** is consistent across the codebase.

---

### 5. Domain Services

**Where**:

- `app/Services/*` (e.g. `TaskService`, `ProjectService`, `EventService`, `CalendarFeedService`, `CalendarFeedSyncService`, `FocusSessionService`, `PomodoroSettingsService`, `CommentService`, `ActivityLogRecorder`, `RecurrenceExpander`, `TagService`)

**Responsibilities**:

- Encapsulate **domain logic** that would otherwise bloat Eloquent models or Livewire components.
- Provide reusable APIs for:
  - Creating/updating/deleting/restoring domain entities with correct side effects.
  - Handling recurrence and date‑based behavior.
  - Building query pipelines for “list” views (workspace lists, per‑project/per‑event lists).
  - Recording activity logs and other cross‑cutting concerns.

**Example: `TaskService`**

- `createTask(User $user, array $attributes): Task`:
  - Runs inside `DB::transaction`.
  - Splits out tag IDs and recurrence data from `$attributes`.
  - Creates the `Task` with `user_id` and other attributes.
  - Attaches tags if present.
  - Creates a `RecurringTask` if recurrence is enabled.
  - Records an `ActivityLogAction::ItemCreated` entry.
- `updateTask(Task $task, array $attributes): Task`:
  - Normalizes `status` updates, including setting/clearing `completed_at`.
  - Runs in a transaction, calling `$task->fill(...)` and `$task->save()`.
  - Calls `syncRecurringTaskDatesIfNeeded` to keep recurrence dates in sync with task dates.
- `deleteTask`, `restoreTask`, `forceDeleteTask`:
  - Wrap destructive operations in transactions.
  - Record activity logs for create/delete/restore.
  - Clean up related `CollaborationInvitation` records on force delete.
- Recurring behavior:
  - `updateOrCreateRecurringTask`, `createRecurringTask`, `normalizeBaseStatusForRecurringTask`, `updateRecurringOccurrenceStatus`, `completeRecurringOccurrence`.
  - `getEffectiveStatusForDate` and `getEffectiveStatusForDateResolved` to compute how a task should appear on a given date.
  - `processRecurringTasksForDate`:
    - Determines which recurring tasks are relevant for a date.
    - Batch loads `TaskInstance` records.
    - Attaches `instanceForDate` and `effectiveStatusForDate` to each task model.
  - `getOccurrencesForDateRange` delegates recurrence expansion to `RecurrenceExpander`.
- List queries:
  - `taskListBaseQuery` centralizes the base Eloquent query for workspace‑style task lists:
    - Eager loads relationships and counts.
    - Uses `forUser` and `relevantForDate` scopes.
    - Adds a “today” special case to filter out past‑ended tasks.

Other services (e.g. `EventService`, `ProjectService`, `CalendarFeedSyncService`, `FocusSessionService`) follow similar patterns tailored to their domains.

---

### 6. Domain Models, Enums, and Policies

**Where**:

- Models: `app/Models/*` (`Task`, `Event`, `Project`, `RecurringTask`, `TaskInstance`, `TaskException`, `Comment`, `ActivityLog`, `CalendarFeed`, `FocusSession`, `PomodoroSetting`, `CollaborationInvitation`, `User`, etc.)
- Enums: `app/Enums/*` (`TaskStatus`, `TaskPriority`, `TaskComplexity`, `TaskRecurrenceType`, `FocusSessionType`, `FocusModeType`, `ActivityLogAction`, `TaskSourceType`, etc.)
- Policies: `app/Policies/*` (`TaskPolicy`, `EventPolicy`, `ProjectPolicy`, `FocusSessionPolicy`, `PomodoroSettingPolicy`, `CalendarFeedPolicy`, `CollaborationPolicy`, `CollaborationInvitationPolicy`, etc.)

**Responsibilities**:

- Models encapsulate:
  - Relationships (e.g. `Task` → `project`, `event`, `recurringTask`, `tags`, `collaborations`, `collaborators`, `comments`, `activityLogs`).
  - Query scopes (e.g. `forUser`, `relevantForDate`, `overdue`, `dueSoon`, `startingSoon`, `notCancelled`, `notArchived`).
  - Accessors/mutators and helpers used by actions/services.
- Enums codify domain vocabularies and keep status/priority/complexity/etc. strongly typed.
- Policies enforce **authorization rules** for:
  - `view`, `viewAny`, `create`, `update`, `delete`, `restore`, `forceDelete`.
  - Collaboration‑specific rules (e.g. owners vs collaborators, permissions on shared items).

Livewire traits and actions rely heavily on policies and enums to ensure consistent domain behavior across the app.

---

### 7. Support & Cross‑Cutting Concerns

**Where**:

- `app\Support/*` (e.g. `DateHelper`, validation helpers, domain‑specific helpers).
- `app\Services\ActivityLogRecorder` and `app\Models\ActivityLog`.
- Middleware such as `app\Http\Middleware\ValidateWorkOSSession`.

**Responsibilities**:

- Date/time parsing and normalization (`DateHelper::parseOptional` and related helpers).
- Activity logging for all significant domain events (create/update/delete/field changes).
- External/session‑level concerns (e.g. WorkOS session validation).

These components are reused across multiple features and layers, avoiding duplication.

---

## Feature Flow Examples

### Task Creation Flow

1. **UI / Livewire**:
   - User submits a create‑task form in the workspace.
   - `HandlesTasks::createTask($payload)` is invoked.
2. **Auth & Authorization**:
   - `requireAuth` ensures there is a logged‑in user.
   - `$this->authorize('create', Task::class)` checks the policy.
3. **Validation & Enrichment**:
   - Payload is merged with `TaskPayloadValidation::defaults()`.
   - Tag IDs are normalized via `TagService`.
   - The full `taskPayload` is validated using `TaskPayloadValidation::rules()`.
   - Related `Project`/`Event` IDs are resolved and authorized.
4. **DTO Construction**:
   - `CreateTaskDto::fromValidated($validatedTask)` builds a strongly‑typed DTO.
5. **Action Execution**:
   - `$this->createTaskAction->execute($user, $dto)` orchestrates the use case.
6. **Service & Model Operations** (inside the action):
   - The action delegates to `TaskService::createTask($user, $dto->toServiceAttributes())`.
   - `TaskService`:
     - Wraps logic in a `DB::transaction`.
     - Creates the `Task`.
     - Attaches tags and sets up recurrence if enabled.
     - Records an activity log entry.
7. **Result Handling**:
   - On success, Livewire:
     - Increments `listRefresh` to refresh the task list.
     - Dispatches `task-created` and a success toast.
   - On failure, Livewire logs the error and dispatches an error toast.

---

### Inline Task Update Flow

1. **UI / Livewire**:
   - User changes a single field (e.g. title, status, tags) inline.
   - `HandlesTasks::updateTaskProperty($taskId, $property, $value, $silentToasts, $occurrenceDate)` is called.
2. **Auth & Authorization**:
   - `requireAuth` ensures the user is logged in.
   - Task is loaded via `Task::query()->forUser($user->id)...`.
   - `$this->authorize('update', $task)` enforces policy.
   - Additional owner‑only checks for date, recurrence, and tags.
3. **Validation**:
   - Property is checked against `TaskPayloadValidation::allowedUpdateProperties()`.
   - Property‑specific rules from `TaskPayloadValidation::rulesForProperty($property)` are applied using `Validator::make`.
4. **Action Execution**:
   - `$this->updateTaskPropertyAction->execute($task, $property, $validatedValue, $occurrenceDate)` is invoked.
5. **Service & Model Operations**:
   - For `tagIds`, tags are synced and activity is logged.
   - For `recurrence`, `TaskService::updateOrCreateRecurringTask` is called and activity is logged.
   - For `status` on recurring tasks, `TaskService::updateRecurringOccurrenceStatus` or `TaskService::updateTask` is used, depending on whether an occurrence date is provided.
   - For other properties, `TaskService::updateTask` persists the change, after optional date range validation.
6. **Result Handling**:
   - `UpdateTaskPropertyResult` is inspected.
   - On failure, Livewire shows an error toast (possibly with a property‑specific message).
   - On success, Livewire shows an appropriate toast (unless `silentToasts` is true) and may return additional data (e.g. `recurringTaskId` for recurrence).

---

### Task Listing and Recurrence Flow

1. **UI / Livewire**:
   - The workspace queries `HandlesTasks::tasks()` for the current date/context.
2. **Query Setup**:
   - Builds a `Task::query()` with necessary relationships and scopes (`forUser`, `relevantForDate`).
   - Applies filters and search.
   - Handles project/event context when `listContextProjectId` or `listContextEventId` are set, with authorization checks.
3. **Pagination & Limits**:
   - Computes `visibleLimit` and `queryLimit` based on `tasksPerPage` and `tasksPage`.
   - Fetches `queryLimit` tasks and determines `hasMoreTasks`.
4. **Recurrence Processing**:
   - For non “search all items” mode, calls `TaskService::processRecurringTasksForDate($visibleTasks, $date)`:
     - Determines which recurring tasks are relevant to the date using `RecurrenceExpander`.
     - Batch loads `TaskInstance` records (no N+1).
     - Attaches `instanceForDate` and `effectiveStatusForDate` for each task.
5. **Post‑Processing**:
   - Optionally applies `filterTaskCollection` for additional in‑memory filtering.
   - Returns a collection ready for rendering in the list view.

---

### Focus Sessions and Pomodoro Flow (Overview)

**Focus sessions and pomodoro** features use the same layered approach:

- Livewire traits (`HandlesFocusSessions`, `HandlesPomodoroSettings`) expose methods and computed properties to the workspace.
- The Volt component injects focus and pomodoro actions like:
  - `StartFocusSessionAction`, `PauseFocusSessionAction`, `ResumeFocusSessionAction`, `CompleteFocusSessionAction`, `AbandonFocusSessionAction`, `GetActiveFocusSessionAction`.
  - `GetOrCreatePomodoroSettingsAction`, `UpdatePomodoroSettingsAction`, `CompletePomodoroSessionAction`, `GetPomodoroSequenceNumberAction`, `GetNextPomodoroSessionTypeAction`.
- Domain services (e.g. `FocusSessionService`, `PomodoroSettingsService`) encapsulate:
  - How sessions are started, paused, resumed, and completed.
  - How pomodoro cycles are sequenced and how settings are stored.
- Policies (`FocusSessionPolicy`, `PomodoroSettingPolicy`) control access to these resources.

This keeps time‑tracking logic reusable and testable, separate from Livewire UI concerns.

---

## How to Extend the Backend in This Architecture

When adding a new feature or extending an existing one, follow the existing layering pattern:

1. **Model & Enums**:
   - Add or update Eloquent models and enums in `app/Models` and `app/Enums` as needed.
   - Define relationships and query scopes on the model for common queries.
2. **Validation**:
   - Create a `Support\Validation\*` class to define defaults and validation rules for payloads and inline updates.
3. **DTOs**:
   - Add DTO classes in `app/DataTransferObjects/<Feature>` to bridge between Livewire/validation and services.
4. **Services**:
   - Implement domain logic in `app/Services/<Feature>Service`:
     - Handle creation, updates, deletion, and complex queries.
     - Wrap multi‑step operations in transactions when needed.
5. **Actions**:
   - Implement use‑case classes in `app/Actions/<Feature>` that:
     - Accept models/DTOs/IDs as inputs.
     - Delegate to services and models.
     - Record activity logs and return clear result objects.
6. **Livewire Traits & Component Wiring**:
   - Create or update `Handles<Feature>` traits in `app/Livewire/Concerns` to:
     - Validate inputs using the validation layer.
     - Build DTOs.
     - Call actions and services.
     - Dispatch events and toasts.
   - Update `resources/views/pages/workspace/⚡index/index.php`:
     - Add trait `use Handles<Feature>;`.
     - Inject new services/actions via the `boot(...)` method.
7. **Policies & Authorization**:
   - Add or update policies under `app/Policies` and register them.
   - Use `$this->authorize(...)` consistently from Livewire traits.

By following these steps and reusing the existing layers, new backend features will integrate cleanly into the Tasklyst architecture and remain consistent with current Laravel 12 and Livewire best practices.

