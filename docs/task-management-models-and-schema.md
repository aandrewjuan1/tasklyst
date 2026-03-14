# Task Management System — Models & Schema

This document describes the **base task management** (and related) models and database schema. 

---

## 1. Overview

The system is organized around:

- **Core work items**: `User`, `Task`, `Event`, `Project`
- **Recurrence**: `RecurringTask`, `RecurringEvent`, `TaskInstance`, `EventInstance`, `TaskException`, `EventException`
- **Tagging**: `Tag` + polymorphic `taggables` pivot
- **Comments & activity**: `Comment`, `ActivityLog` (polymorphic)
- **Collaboration**: `Collaboration`, `CollaborationInvitation` (polymorphic)
- **Calendar & focus**: `CalendarFeed`, `FocusSession`, `PomodoroSetting`

All task-management data is scoped by **user** (owner or collaborator). Tasks can be linked to **projects** and **events**; tasks and events support **recurrence** with instances and exceptions.

---

## 2. Eloquent Models (In Depth)

### 2.1 User

**Table:** `users`

| Column             | Type     | Notes                          |
|--------------------|----------|--------------------------------|
| id                 | integer  | PK                             |
| name               | varchar  |                                |
| email              | varchar  | Unique                         |
| email_verified_at  | datetime | Nullable                       |
| workos_id          | varchar  | Unique (external auth)         |
| remember_token     | varchar  | Nullable                       |
| avatar             | text     | Nullable                       |
| created_at         | datetime |                                |
| updated_at         | datetime |                                |

**Relations:**

- `collaborations` → HasMany `Collaboration`
- `comments` → HasMany `Comment`
- `pomodoroSetting` → HasOne `PomodoroSetting`

**Notes:** Tasks, events, projects, tags, calendar feeds, focus sessions, and activity logs are associated via `user_id` on those models. The User model itself does not define those relations; they are defined on the related models (e.g. `Task::user()`).

---

### 2.2 Task

**Table:** `tasks`

| Column           | Type     | Notes                                      |
|------------------|----------|--------------------------------------------|
| id               | integer  | PK                                         |
| user_id          | integer  | FK → users.id, CASCADE                     |
| title            | varchar  |                                            |
| description      | text     | Nullable                                   |
| status           | varchar  | Enum: TaskStatus                           |
| priority         | varchar  | Enum: TaskPriority                         |
| complexity       | varchar  | Enum: TaskComplexity                       |
| duration         | integer  | Nullable (minutes)                         |
| start_datetime   | datetime | Nullable                                   |
| end_datetime     | datetime | Nullable                                   |
| project_id       | integer  | FK → projects.id, SET NULL                 |
| event_id         | integer  | FK → events.id, SET NULL                   |
| completed_at     | datetime | Nullable                                   |
| deleted_at       | datetime | Soft deletes                               |
| source_type      | varchar  | Enum: TaskSourceType (e.g. manual, brightspace) |
| source_id        | varchar  | Nullable                                   |
| calendar_feed_id  | integer  | FK → calendar_feeds.id, SET NULL            |
| source_url       | text     | Nullable                                   |
| teacher_name     | varchar  | Nullable (student context)                 |
| subject_name     | varchar  | Nullable (student context)                 |
| created_at       | datetime |                                            |
| updated_at       | datetime |                                            |

**Indexes:** `tasks_user_start_end_index`, `tasks_end_datetime_index`, `tasks_user_completed_index`, `tasks_user_source_unique` (user_id, source_type, source_id).

**Relations:**

- `user` → BelongsTo `User`
- `project` → BelongsTo `Project`
- `event` → BelongsTo `Event`
- `calendarFeed` → BelongsTo `CalendarFeed`
- `recurringTask` → HasOne `RecurringTask`
- `tags` → MorphToMany `Tag` (taggable)
- `comments` → MorphMany `Comment` (commentable)
- `activityLogs` → MorphMany `ActivityLog` (loggable)
- `collaborations` → MorphMany `Collaboration` (collaboratable)
- `collaborationInvitations` → MorphMany `CollaborationInvitation` (collaboratable)
- `focusSessions` → MorphMany `FocusSession` (focusable)
- `collaborators` → MorphToMany `User` (via collaborations, with pivot `permission`)

**Scopes (examples):** `forUser`, `activeForUser`, `forProject`, `forEvent`, `fromFeed`, `native`, `incomplete`, `relevantForDate`, `overdue`, `orderByPriority`, `withNoDate`, `highPriority`, `dueSoon`, `summaryColumns`, `forIds`.

**Casts:** `status` → TaskStatus, `priority` → TaskPriority, `complexity` → TaskComplexity, `source_type` → TaskSourceType, `duration` → integer, datetimes → datetime.

**Traits:** HasFactory, SoftDeletes. On delete: collaborations and collaboration invitations deleted; on force delete, recurring task deleted.

---

### 2.3 Event

**Table:** `events`

| Column           | Type     | Notes                |
|------------------|----------|----------------------|
| id               | integer  | PK                   |
| user_id          | integer  | FK → users.id, CASCADE |
| title            | varchar  |                      |
| description      | text     | Nullable             |
| start_datetime   | datetime | Nullable             |
| end_datetime     | datetime | Nullable             |
| all_day          | tinyint  | Boolean              |
| status           | varchar  | Enum: EventStatus    |
| created_at       | datetime |                      |
| updated_at       | datetime |                      |
| deleted_at       | datetime | Soft deletes         |

**Indexes:** `events_user_start_end_index`, `events_user_status_index`, `events_end_datetime_index`.

**Relations:**

- `user` → BelongsTo `User`
- `recurringEvent` → HasOne `RecurringEvent`
- `tasks` → HasMany `Task`
- `collaborations`, `collaborationInvitations`, `collaborators` — same pattern as Task
- `tags` → MorphToMany `Tag`
- `comments` → MorphMany `Comment`
- `activityLogs` → MorphMany `ActivityLog`

**Scopes (examples):** `forUser`, `activeForDate`, `notCancelled`, `notCompleted`, `byStatus`, `orderByStartTime`, `withNoDate`, `overdue`, `upcoming`, `startingSoon`, `upcomingForUser`, `conflictingWithWindow`, `happeningNow`, `allDay`, `timed`.

**Casts:** `status` → EventStatus, `start_datetime` / `end_datetime` → datetime, `all_day` → boolean.

**Traits:** HasFactory, SoftDeletes. On delete: collaborations and invitations; on force delete, recurring event deleted.

---

### 2.4 Project

**Table:** `projects`

| Column           | Type     | Notes                |
|------------------|----------|----------------------|
| id               | integer  | PK                   |
| user_id          | integer  | FK → users.id, CASCADE |
| name             | varchar  |                      |
| description      | text     | Nullable             |
| start_datetime   | datetime | Nullable             |
| end_datetime     | datetime | Nullable             |
| created_at       | datetime |                      |
| updated_at       | datetime |                      |
| deleted_at       | datetime | Soft deletes         |

**Indexes:** `projects_user_deleted_index`, `projects_user_start_end_index`.

**Relations:**

- `user` → BelongsTo `User`
- `tasks` → HasMany `Task`
- `collaborations`, `collaborationInvitations`, `collaborators` — same polymorphic pattern
- `comments` → MorphMany `Comment`
- `activityLogs` → MorphMany `ActivityLog`

**Scopes (examples):** `forUser`, `notArchived`, `activeForDate`, `overdue`, `upcoming`, `withNoDate`, `orderByStartTime`, `startingSoon`, `withIncompleteTasks`, `withTasks`.

**Casts:** `start_datetime`, `end_datetime` → datetime.

**Traits:** HasFactory, SoftDeletes. On delete: collaborations and invitations only.

---

### 2.5 Tag

**Table:** `tags`

| Column     | Type     | Notes                |
|------------|----------|----------------------|
| id         | integer  | PK                   |
| name       | varchar  |                      |
| user_id    | integer  | FK → users.id, CASCADE |
| created_at | datetime |                      |
| updated_at | datetime |                      |

**Index:** `tags_user_id_name_unique` (user_id, name).

**Relations:**

- `user` → BelongsTo `User`
- `tasks` → MorphToMany (morphedByMany Task, taggable)
- `events` → MorphToMany (morphedByMany Event, taggable)

**Scopes:** `forUser`, `byName`. Static: `validIdsForUser(userId, tagIds)`.

---

### 2.6 Taggables (pivot)

**Table:** `taggables`

| Column       | Type     | Notes                          |
|--------------|----------|--------------------------------|
| id           | integer  | PK                             |
| tag_id       | integer  | FK → tags.id, CASCADE          |
| taggable_id  | integer  | Polymorphic ID                 |
| taggable_type| varchar  | Polymorphic type (e.g. Task, Event) |
| created_at   | datetime |                                |
| updated_at   | datetime |                                |

**Indexes:** `taggables_type_id_index`, `taggables_unique` (tag_id, taggable_id, taggable_type).

---

### 2.7 Comment

**Table:** `comments`

| Column          | Type     | Notes                |
|-----------------|----------|----------------------|
| id              | integer  | PK                   |
| user_id         | integer  | FK → users.id, CASCADE |
| content         | text     |                      |
| is_edited       | tinyint  | Boolean              |
| edited_at       | datetime | Nullable             |
| is_pinned       | tinyint  | Boolean              |
| commentable_id  | integer  | Polymorphic ID       |
| commentable_type| varchar  | Polymorphic type     |
| created_at      | datetime |                      |
| updated_at      | datetime |                      |

**Indexes:** `comments_commentable_id_commentable_type_index`, `comments_commentable_id_commentable_type_created_at_index`.

**Relations:** `commentable` → MorphTo (Task, Event, Project); `user` → BelongsTo `User`.

**Scopes:** `forItem(Model $item)`.

**Casts:** `is_edited`, `is_pinned` → boolean, `edited_at` → datetime.

---

### 2.8 ActivityLog

**Table:** `activity_logs`

| Column       | Type     | Notes                |
|--------------|----------|----------------------|
| id           | integer  | PK                   |
| loggable_type| varchar  | Polymorphic type     |
| loggable_id  | integer  | Polymorphic ID       |
| user_id      | integer  | FK → users.id, SET NULL |
| action       | varchar  | Enum: ActivityLogAction |
| payload      | text     | JSON                 |
| created_at   | datetime |                      |
| updated_at   | datetime |                      |

**Indexes:** `activity_logs_loggable_type_loggable_id_created_at_index`, `activity_logs_user_id_created_at_index`.

**Relations:** `loggable` → MorphTo (Task, Event, Project); `user` → BelongsTo `User`.

**Casts:** `action` → ActivityLogAction, `payload` → array.

**Scopes:** `forItem`, `forActor`. Method `message()` for human-readable log line.

---

### 2.9 Collaboration

**Table:** `collaborations`

| Column             | Type     | Notes                |
|--------------------|----------|----------------------|
| id                 | integer  | PK                   |
| collaboratable_type| varchar  | Polymorphic type     |
| collaboratable_id   | integer  | Polymorphic ID       |
| user_id            | integer  | FK → users.id, CASCADE |
| permission         | varchar  | Enum: CollaborationPermission |
| created_at         | datetime |                      |
| updated_at         | datetime |                      |

**Indexes:** `collaborations_collaboratable_type_collaboratable_id_index`, `collaborations_unique` (collaboratable_type, collaboratable_id, user_id).

**Relations:** `collaboratable` → MorphTo (Task, Event, Project); `user` → BelongsTo `User`.

**Casts:** `permission` → CollaborationPermission.

---

### 2.10 CollaborationInvitation

**Table:** `collaboration_invitations`

| Column             | Type     | Notes                |
|--------------------|----------|----------------------|
| id                 | integer  | PK                   |
| collaboratable_type| varchar  | Polymorphic type     |
| collaboratable_id   | integer  | Polymorphic ID       |
| inviter_id         | integer  | FK → users.id, CASCADE |
| invitee_email      | varchar  |                      |
| invitee_user_id    | integer  | FK → users.id, SET NULL |
| permission         | varchar  | Enum: CollaborationPermission |
| token              | varchar  | Unique               |
| status             | varchar  | e.g. pending         |
| expires_at         | datetime | Nullable             |
| created_at         | datetime |                      |
| updated_at         | datetime |                      |

**Indexes:** `collaboration_invitations_collaboratable_type_collaboratable_id_index`, `collaboration_invitations_token_unique`.

**Relations:** `collaboratable` → MorphTo; `inviter` → BelongsTo User (inviter_id); `invitee` → BelongsTo User (invitee_user_id).

**Scopes:** `pendingForUser(User $user)`. On creating: token and status defaulted if empty.

---

### 2.11 CalendarFeed

**Table:** `calendar_feeds`

| Column        | Type     | Notes                |
|---------------|----------|----------------------|
| id            | integer  | PK                   |
| user_id       | integer  | FK → users.id, CASCADE |
| name          | varchar  |                      |
| feed_url      | text     |                      |
| source        | varchar  |                      |
| sync_enabled  | tinyint  | Boolean              |
| last_synced_at| datetime | Nullable             |
| created_at    | datetime |                      |
| updated_at    | datetime |                      |

**Relations:** `user` → BelongsTo `User`; `tasks` → HasMany `Task` (calendar_feed_id).

**Casts:** `sync_enabled` → bool, `last_synced_at` → datetime. `feed_url` in `$hidden`.

**Scopes:** `syncEnabled`.

---

### 2.12 FocusSession

**Table:** `focus_sessions`

| Column          | Type     | Notes                |
|-----------------|----------|----------------------|
| id              | integer  | PK                   |
| user_id         | integer  | FK → users.id, CASCADE |
| focusable_type  | varchar  | Polymorphic type     |
| focusable_id    | integer  | Polymorphic ID       |
| type            | varchar  | Enum: FocusSessionType (work, short_break, long_break) |
| focus_mode_type | varchar  | Enum: FocusModeType (sprint, pomodoro) |
| sequence_number | integer  |                      |
| duration_seconds| integer  |                      |
| completed       | tinyint  | Boolean              |
| started_at     | datetime |                      |
| ended_at       | datetime | Nullable             |
| paused_seconds  | integer  |                      |
| paused_at      | datetime | Nullable             |
| payload        | text     | JSON                 |
| created_at     | datetime |                      |
| updated_at     | datetime |                      |

**Indexes:** `focus_sessions_user_id_started_at_index`, `focus_sessions_focusable_type_focusable_id_started_at_index`.

**Relations:** `user` → BelongsTo `User`; `focusable` → MorphTo (e.g. Task).

**Casts:** `type` → FocusSessionType, `focus_mode_type` → FocusModeType, datetimes, `completed` → boolean, `payload` → array.

**Scopes:** `forUser`, `forTask`, `work`, `completed`, `inProgress`, `today`, `thisWeek`. Method `flushPausedAt()` to apply current pause segment.

---

### 2.13 PomodoroSetting

**Table:** `pomodoro_settings`

| Column                     | Type     | Notes                |
|----------------------------|----------|----------------------|
| id                         | integer  | PK                   |
| user_id                    | integer  | FK → users.id, CASCADE, UNIQUE |
| work_duration_minutes      | integer  |                      |
| short_break_minutes        | integer  |                      |
| long_break_minutes         | integer  |                      |
| long_break_after_pomodoros | integer  |                      |
| auto_start_break           | tinyint  | Boolean              |
| auto_start_pomodoro        | tinyint  | Boolean              |
| sound_enabled              | tinyint  | Boolean              |
| sound_volume               | integer  |                      |
| created_at                 | datetime |                      |
| updated_at                 | datetime |                      |

**Index:** `pomodoro_settings_user_id_unique`.

**Relations:** `user` → BelongsTo `User`.

**Casts:** booleans for auto_start_break, auto_start_pomodoro, sound_enabled. Accessor: `work_duration_seconds`. Static: `defaults()` from config.

---

### 2.14 RecurringTask

**Table:** `recurring_tasks`

| Column           | Type     | Notes                |
|------------------|----------|----------------------|
| id               | integer  | PK                   |
| task_id          | integer  | FK → tasks.id, CASCADE, UNIQUE |
| recurrence_type  | varchar  | Enum: TaskRecurrenceType |
| interval         | integer  |                      |
| start_datetime   | datetime | Nullable             |
| end_datetime     | datetime | Nullable             |
| days_of_week     | varchar  | JSON (e.g. [0,1,2] for Sun,Mon,Tue) |
| created_at       | datetime |                      |
| updated_at       | datetime |                      |

**Relations:** `task` → BelongsTo `Task`; `taskInstances` → HasMany `TaskInstance`; `taskExceptions` → HasMany `TaskException`.

**Casts:** `recurrence_type` → TaskRecurrenceType, `start_datetime` / `end_datetime` → datetime.

**Static:** `toPayloadArray(?RecurringTask $recurring)` for frontend recurrence payload.

---

### 2.15 RecurringEvent

**Table:** `recurring_events`

| Column           | Type     | Notes                |
|------------------|----------|----------------------|
| id               | integer  | PK                   |
| event_id         | integer  | FK → events.id, CASCADE, UNIQUE |
| recurrence_type  | varchar  | Enum: EventRecurrenceType |
| interval         | integer  |                      |
| days_of_week     | varchar  | JSON                 |
| start_datetime   | datetime | Nullable             |
| end_datetime     | datetime | Nullable             |
| created_at       | datetime |                      |
| updated_at       | datetime |                      |

**Relations:** `event` → BelongsTo `Event`; `eventInstances` → HasMany `EventInstance`; `eventExceptions` → HasMany `EventException`.

**Casts:** `recurrence_type` → EventRecurrenceType, datetimes.

**Static:** `toPayloadArray(?RecurringEvent $recurring)` for frontend.

---

### 2.16 TaskInstance

**Table:** `task_instances`

| Column             | Type     | Notes                |
|--------------------|----------|----------------------|
| id                 | integer  | PK                   |
| recurring_task_id  | integer  | FK → recurring_tasks.id, CASCADE |
| task_id            | integer  | FK → tasks.id, SET NULL |
| instance_date      | date     |                      |
| status             | varchar  | Enum: TaskStatus     |
| completed_at       | datetime | Nullable             |
| created_at         | datetime |                      |
| updated_at         | datetime |                      |

**Indexes:** `task_instances_recurring_task_id_index`, `task_instances_recurring_date_index`.

**Relations:** `recurringTask` → BelongsTo `RecurringTask`; `task` → BelongsTo `Task`.

**Casts:** `instance_date` → date, `status` → TaskStatus, `completed_at` → datetime.

---

### 2.17 EventInstance

**Table:** `event_instances`

| Column             | Type     | Notes                |
|--------------------|----------|----------------------|
| id                 | integer  | PK                   |
| recurring_event_id | integer  | FK → recurring_events.id, CASCADE |
| event_id           | integer  | FK → events.id, SET NULL |
| instance_date      | date     |                      |
| status             | varchar  | Enum: EventStatus    |
| cancelled          | tinyint  | Boolean              |
| completed_at       | datetime | Nullable             |
| created_at         | datetime |                      |
| updated_at         | datetime |                      |

**Indexes:** `event_instances_recurring_event_id_index`, `event_instances_recurring_date_index`.

**Relations:** `recurringEvent` → BelongsTo `RecurringEvent`; `event` → BelongsTo `Event`.

**Casts:** `instance_date` → date, `status` → EventStatus, `cancelled` → boolean, `completed_at` → datetime.

---

### 2.18 TaskException

**Table:** `task_exceptions`

| Column                 | Type     | Notes                |
|------------------------|----------|----------------------|
| id                     | integer  | PK                   |
| recurring_task_id      | integer  | FK → recurring_tasks.id, CASCADE |
| exception_date         | date     |                      |
| is_deleted             | tinyint  | Boolean (skip this instance) |
| replacement_instance_id| integer  | FK → task_instances.id, SET NULL |
| reason                 | text     | Nullable             |
| created_by             | integer  | FK → users.id, SET NULL |
| created_at             | datetime |                      |
| updated_at             | datetime |                      |

**Unique:** (recurring_task_id, exception_date).

**Relations:** `recurringTask` → BelongsTo `RecurringTask`; `replacementInstance` → BelongsTo `TaskInstance`; `createdBy` → BelongsTo `User`.

**Casts:** `exception_date` → date, `is_deleted` → boolean.

---

### 2.19 EventException

**Table:** `event_exceptions`

| Column                 | Type     | Notes                |
|------------------------|----------|----------------------|
| id                     | integer  | PK                   |
| recurring_event_id     | integer  | FK → recurring_events.id, CASCADE |
| exception_date         | date     |                      |
| is_deleted             | tinyint  | Boolean              |
| replacement_instance_id| integer  | FK → event_instances.id, SET NULL |
| reason                 | text     | Nullable             |
| created_by             | integer  | FK → users.id, SET NULL |
| created_at             | datetime |                      |
| updated_at             | datetime |                      |

**Unique:** (recurring_event_id, exception_date).

**Relations:** `recurringEvent` → BelongsTo `RecurringEvent`; `replacementInstance` → BelongsTo `EventInstance`; `createdBy` → BelongsTo `User`.

**Casts:** `exception_date` → date, `is_deleted` → boolean.

---

## 3. Enums (Task Management)

| Enum                     | Cases                                                                 |
|--------------------------|-----------------------------------------------------------------------|
| **TaskStatus**           | ToDo, Doing, Done                                                     |
| **TaskPriority**         | Low, Medium, High, Urgent                                             |
| **TaskComplexity**       | Simple, Moderate, Complex                                            |
| **TaskSourceType**       | Manual, Brightspace                                                  |
| **TaskRecurrenceType**   | Daily, Weekly, Monthly, Yearly                                       |
| **EventStatus**          | Scheduled, Cancelled, Completed, Tentative, Ongoing                  |
| **EventRecurrenceType**  | Daily, Weekly, Monthly, Yearly                                       |
| **CollaborationPermission** | View, Edit                                                       |
| **ActivityLogAction**    | ItemCreated, ItemDeleted, ItemRestored, FieldUpdated, CommentCreated, CommentUpdated, CommentDeleted, CollaboratorInvited, CollaboratorInvitationAccepted, CollaboratorInvitationDeclined, CollaboratorLeft, CollaboratorRemoved, CollaboratorPermissionUpdated, FocusSessionCompleted |
| **FocusSessionType**     | Work, ShortBreak, LongBreak                                          |
| **FocusModeType**        | Sprint, Pomodoro                                                     |

---

## 4. Polymorphic Summary

| Relation        | Morph name      | Types (models)     | Pivot / table           |
|-----------------|-----------------|--------------------|--------------------------|
| Taggable        | taggable        | Task, Event        | taggables                |
| Commentable     | commentable     | Task, Event, Project | —                      |
| Loggable        | loggable        | Task, Event, Project | —                      |
| Collaboratable  | collaboratable  | Task, Event, Project | collaborations (user_id, permission) |
| Focusable       | focusable       | Task (and optionally others) | —                 |

---

## 5. Key Conventions

- **Ownership:** Tasks, events, projects, tags, calendar feeds, and focus sessions have `user_id` (owner). Access control often uses a “for user” concept: owner or collaborator (via `Collaboration`).
- **Soft deletes:** Task, Event, Project use `deleted_at`.
- **Recurrence:** One-to-one from Task → RecurringTask and Event → RecurringEvent. Instances (TaskInstance / EventInstance) represent occurrences; TaskException / EventException represent skipped or modified occurrences.
- **Student context:** Task has optional `teacher_name`, `subject_name`, and `source_type`/`source_id`/`source_url` for external systems (e.g. Brightspace).
- **Uniqueness:** One RecurringTask per Task, one RecurringEvent per Event; one PomodoroSetting per User; (user_id, name) unique per Tag; (user_id, source_type, source_id) unique per Task for external sources.

This file is the single source of truth for the **base task management** models and schema (excluding LLM/chat).
