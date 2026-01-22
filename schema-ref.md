# Database Migration Schema Documentation

This document provides a comprehensive overview of the database schema for the core modules: tasks, projects, events, comments, collaborations, recurring tasks and events (with their instances and exceptions), and tags.

## Table of Contents

1. [Projects](#projects)
2. [Tasks](#tasks)
3. [Events](#events)
4. [Comments](#comments)
5. [Collaborations](#collaborations)
6. [Tags](#tags)
7. [Taggables](#taggables)
8. [Recurring Tasks](#recurring-tasks)
9. [Task Instances](#task-instances)
10. [Task Exceptions](#task-exceptions)
11. [Recurring Events](#recurring-events)
12. [Event Instances](#event-instances)
13. [Event Exceptions](#event-exceptions)

---

## Projects

**Table Name:** `projects`

### Columns

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | bigint unsigned | No | Auto-increment | Primary key |
| `user_id` | bigint unsigned | No | - | Foreign key to `users.id` (cascade on delete) |
| `name` | string | No | - | Project name |
| `description` | text | Yes | NULL | Project description |
| `start_datetime` | datetime | Yes | NULL | Project start date and time |
| `end_datetime` | datetime | Yes | NULL | Project end date and time |
| `created_at` | timestamp | Yes | NULL | Record creation timestamp |
| `updated_at` | timestamp | Yes | NULL | Record update timestamp |
| `deleted_at` | timestamp | Yes | NULL | Soft delete timestamp |

### Foreign Keys

- `projects.user_id` → `users.id` (CASCADE on delete)

### Indexes

- Primary key on `id`
- Foreign key index on `user_id`

### Notes

- Supports soft deletes
- Date fields were migrated from `date` type to `datetime` type to support time information

---

## Tasks

**Table Name:** `tasks`

### Columns

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | bigint unsigned | No | Auto-increment | Primary key |
| `user_id` | bigint unsigned | No | - | Foreign key to `users.id` (cascade on delete) |
| `title` | string | No | - | Task title |
| `description` | text | Yes | NULL | Task description |
| `status` | enum | Yes | 'to_do' | Task status: 'to_do', 'doing', 'done' |
| `priority` | enum | Yes | 'medium' | Task priority: 'low', 'medium', 'high', 'urgent' |
| `complexity` | enum | Yes | 'moderate' | Task complexity: 'simple', 'moderate', 'complex' |
| `duration` | integer | Yes | NULL | Duration in minutes |
| `start_datetime` | datetime | Yes | NULL | Task start date and time |
| `end_datetime` | datetime | Yes | NULL | Task end date and time |
| `project_id` | bigint unsigned | Yes | NULL | Foreign key to `projects.id` (null on delete) |
| `event_id` | bigint unsigned | Yes | NULL | Foreign key to `events.id` (null on delete) |
| `completed_at` | timestamp | Yes | NULL | Task completion timestamp |
| `created_at` | timestamp | Yes | NULL | Record creation timestamp |
| `updated_at` | timestamp | Yes | NULL | Record update timestamp |
| `deleted_at` | timestamp | Yes | NULL | Soft delete timestamp |

### Foreign Keys

- `tasks.user_id` → `users.id` (CASCADE on delete)
- `tasks.project_id` → `projects.id` (SET NULL on delete)
- `tasks.event_id` → `events.id` (SET NULL on delete)

### Indexes

- Primary key on `id`
- Foreign key indexes on `user_id`, `project_id`, `event_id`

### Notes

- Supports soft deletes
- Status, priority, complexity, and date fields are nullable with defaults
- Date fields were migrated from separate `date` and `time` columns to combined `datetime` columns
- Can be linked to both projects and events

---

## Events

**Table Name:** `events`

### Columns

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | bigint unsigned | No | Auto-increment | Primary key |
| `user_id` | bigint unsigned | No | - | Foreign key to `users.id` (cascade on delete) |
| `title` | string | No | - | Event title |
| `description` | text | Yes | NULL | Event description |
| `start_datetime` | timestampTz | Yes | NULL | Event start date and time (with timezone) |
| `end_datetime` | timestampTz | Yes | NULL | Event end date and time (with timezone) |
| `all_day` | boolean | No | false | Whether the event is all-day |
| `timezone` | string | Yes | NULL | Event timezone |
| `location` | string | Yes | NULL | Event location |
| `color` | string | Yes | NULL | Event color |
| `status` | enum | Yes | 'scheduled' | Event status: 'scheduled', 'cancelled', 'completed', 'tentative', 'ongoing' |
| `created_at` | timestamp | Yes | NULL | Record creation timestamp |
| `updated_at` | timestamp | Yes | NULL | Record update timestamp |
| `deleted_at` | timestamp | Yes | NULL | Soft delete timestamp |

### Foreign Keys

- `events.user_id` → `users.id` (CASCADE on delete)

### Indexes

- Primary key on `id`
- Foreign key index on `user_id`

### Notes

- Supports soft deletes
- Uses timezone-aware timestamps (`timestampTz`)
- Start datetime, end datetime, timezone, and status are nullable with defaults
- Originally had a `recurring_event_id` foreign key which was removed in a later migration

---

## Comments

**Table Name:** `comments`

### Columns

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | bigint unsigned | No | Auto-increment | Primary key |
| `task_id` | bigint unsigned | No | - | Foreign key to `tasks.id` (cascade on delete) |
| `user_id` | bigint unsigned | No | - | Foreign key to `users.id` (cascade on delete) |
| `content` | text | No | - | Comment content |
| `is_edited` | boolean | No | false | Whether the comment has been edited |
| `edited_at` | timestamp | Yes | NULL | Timestamp when comment was edited |
| `is_pinned` | boolean | No | false | Whether the comment is pinned |
| `created_at` | timestamp | Yes | NULL | Record creation timestamp |
| `updated_at` | timestamp | Yes | NULL | Record update timestamp |

### Foreign Keys

- `comments.task_id` → `tasks.id` (CASCADE on delete)
- `comments.user_id` → `users.id` (CASCADE on delete)

### Indexes

- Primary key on `id`
- Index on `task_id`
- Index on `user_id`
- Composite index on `[task_id, created_at]` for efficient querying of comments by task

### Notes

- Comments are currently only associated with tasks
- Supports editing and pinning functionality

---

## Collaborations

**Table Name:** `collaborations`

### Columns

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | bigint unsigned | No | Auto-increment | Primary key |
| `collaboratable_type` | string | No | - | Polymorphic type (e.g., 'App\Models\Task', 'App\Models\Project', 'App\Models\Event') |
| `collaboratable_id` | bigint unsigned | No | - | Polymorphic ID |
| `user_id` | bigint unsigned | No | - | Foreign key to `users.id` (cascade on delete) |
| `permission` | string(20) | No | - | Permission level: 'view' or 'edit' |
| `created_at` | timestamp | Yes | NULL | Record creation timestamp |
| `updated_at` | timestamp | Yes | NULL | Record update timestamp |

### Foreign Keys

- `collaborations.user_id` → `users.id` (CASCADE on delete)

### Indexes

- Primary key on `id`
- Index on `user_id`
- Composite index on `[collaboratable_type, collaboratable_id]`
- Unique constraint on `[collaboratable_type, collaboratable_id, user_id]` (named `collaborations_unique`)

### Notes

- Polymorphic relationship allowing collaborations on tasks, projects, and events
- A user can only have one collaboration record per item
- Permission values were migrated from 'comment' to 'view' in a later migration

---

## Tags

**Table Name:** `tags`

### Columns

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | bigint unsigned | No | Auto-increment | Primary key |
| `name` | string | No | - | Tag name (unique) |
| `created_at` | timestamp | Yes | NULL | Record creation timestamp |
| `updated_at` | timestamp | Yes | NULL | Record update timestamp |

### Indexes

- Primary key on `id`
- Unique index on `name`

### Notes

- Tags are shared across the system and can be associated with multiple items through the polymorphic `taggables` table

---

## Taggables

**Table Name:** `taggables`

### Columns

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | bigint unsigned | No | Auto-increment | Primary key |
| `tag_id` | bigint unsigned | No | - | Foreign key to `tags.id` (cascade on delete) |
| `taggable_id` | bigint unsigned | No | - | Polymorphic ID |
| `taggable_type` | string | No | - | Polymorphic type (e.g., 'App\Models\Task', 'App\Models\Event') |
| `created_at` | timestamp | Yes | NULL | Record creation timestamp |
| `updated_at` | timestamp | Yes | NULL | Record update timestamp |

### Foreign Keys

- `taggables.tag_id` → `tags.id` (CASCADE on delete)

### Indexes

- Primary key on `id`
- Composite unique constraint on `[tag_id, taggable_id, taggable_type]` (named `taggables_unique`)
- Composite index on `[taggable_type, taggable_id]` (named `taggables_type_id_index`)

### Notes

- Polymorphic pivot table replacing the original `tag_task` and `tag_events` tables
- Allows tags to be associated with tasks and events (and potentially other models in the future)
- A tag can only be associated once with each item

---

## Recurring Tasks

**Table Name:** `recurring_tasks`

### Columns

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | bigint unsigned | No | Auto-increment | Primary key |
| `task_id` | bigint unsigned | No | - | Foreign key to `tasks.id` (unique, cascade on delete) |
| `recurrence_type` | enum | No | - | Recurrence pattern: 'daily', 'weekly', 'monthly' |
| `interval` | integer | No | - | Recurrence interval (e.g., every 2 weeks) |
| `start_datetime` | datetime | No | - | Recurrence start date and time |
| `end_datetime` | datetime | Yes | NULL | Recurrence end date and time (NULL = no end) |
| `days_of_week` | string | Yes | NULL | Days of week for weekly recurrence (comma-separated) |
| `created_at` | timestamp | Yes | NULL | Record creation timestamp |
| `updated_at` | timestamp | Yes | NULL | Record update timestamp |

### Foreign Keys

- `recurring_tasks.task_id` → `tasks.id` (CASCADE on delete, UNIQUE)

### Indexes

- Primary key on `id`
- Unique index on `task_id`

### Notes

- One-to-one relationship with tasks (each recurring task has one base task)
- Date fields were migrated from `date` type to `datetime` type
- `days_of_week` is used for weekly recurrence patterns

---

## Task Instances

**Table Name:** `task_instances`

### Columns

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | bigint unsigned | No | Auto-increment | Primary key |
| `recurring_task_id` | bigint unsigned | No | - | Foreign key to `recurring_tasks.id` (cascade on delete) |
| `task_id` | bigint unsigned | Yes | NULL | Foreign key to `tasks.id` (null on delete) |
| `instance_date` | date | No | - | Date of this task instance |
| `status` | enum | No | - | Instance status: 'to_do', 'doing', 'done' |
| `completed_at` | timestamp | Yes | NULL | Instance completion timestamp |
| `created_at` | timestamp | Yes | NULL | Record creation timestamp |
| `updated_at` | timestamp | Yes | NULL | Record update timestamp |

### Foreign Keys

- `task_instances.recurring_task_id` → `recurring_tasks.id` (CASCADE on delete)
- `task_instances.task_id` → `tasks.id` (SET NULL on delete)

### Indexes

- Primary key on `id`
- Foreign key indexes on `recurring_task_id` and `task_id`

### Notes

- Represents individual occurrences of a recurring task
- `task_id` is nullable and can reference a specific task instance if the user has customized it
- Originally had `overridden_title` and `overridden_description` columns which were removed in a later migration

---

## Task Exceptions

**Table Name:** `task_exceptions`

### Columns

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | bigint unsigned | No | Auto-increment | Primary key |
| `recurring_task_id` | bigint unsigned | No | - | Foreign key to `recurring_tasks.id` (cascade on delete) |
| `exception_date` | date | No | - | Date when the exception occurs |
| `is_deleted` | boolean | No | false | Whether this instance was deleted (skipped) |
| `replacement_instance_id` | bigint unsigned | Yes | NULL | Foreign key to `task_instances.id` (null on delete) |
| `reason` | text | Yes | NULL | Reason for the exception |
| `created_by` | bigint unsigned | Yes | NULL | Foreign key to `users.id` (null on delete) |
| `created_at` | timestamp | Yes | NULL | Record creation timestamp |
| `updated_at` | timestamp | Yes | NULL | Record update timestamp |

### Foreign Keys

- `task_exceptions.recurring_task_id` → `recurring_tasks.id` (CASCADE on delete)
- `task_exceptions.replacement_instance_id` → `task_instances.id` (SET NULL on delete)
- `task_exceptions.created_by` → `users.id` (SET NULL on delete)

### Indexes

- Primary key on `id`
- Unique constraint on `[recurring_task_id, exception_date]`
- Foreign key indexes on `recurring_task_id`, `replacement_instance_id`, `created_by`

### Notes

- Used to skip or modify specific occurrences of a recurring task
- Each recurring task can only have one exception per date
- Can reference a replacement instance if the exception was replaced with a modified version

---

## Recurring Events

**Table Name:** `recurring_events`

### Columns

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | bigint unsigned | No | Auto-increment | Primary key |
| `event_id` | bigint unsigned | No | - | Foreign key to `events.id` (unique, cascade on delete) |
| `recurrence_type` | enum | No | - | Recurrence pattern: 'daily', 'weekly', 'monthly', 'yearly', 'custom' |
| `interval` | integer | No | - | Recurrence interval (e.g., every 2 weeks) |
| `days_of_week` | string | Yes | NULL | Days of week for weekly recurrence (comma-separated) |
| `start_datetime` | datetime | No | - | Recurrence start date and time |
| `end_datetime` | datetime | Yes | NULL | Recurrence end date and time (NULL = no end) |
| `timezone` | string | Yes | NULL | Timezone for the recurring event |
| `created_at` | timestamp | Yes | NULL | Record creation timestamp |
| `updated_at` | timestamp | Yes | NULL | Record update timestamp |

### Foreign Keys

- `recurring_events.event_id` → `events.id` (CASCADE on delete, UNIQUE)

### Indexes

- Primary key on `id`
- Unique index on `event_id`

### Notes

- One-to-one relationship with events (each recurring event has one base event)
- Date fields were migrated from `date` type to `datetime` type
- Timezone field was made nullable in a later migration
- Originally included `day_of_month`, `nth_weekday`, `rrule`, and `occurrence_count` columns which were removed in a later migration

---

## Event Instances

**Table Name:** `event_instances`

### Columns

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | bigint unsigned | No | Auto-increment | Primary key |
| `recurring_event_id` | bigint unsigned | No | - | Foreign key to `recurring_events.id` (cascade on delete) |
| `event_id` | bigint unsigned | Yes | NULL | Foreign key to `events.id` (null on delete) |
| `instance_date` | date | No | - | Date of this event instance |
| `status` | enum | No | - | Instance status: 'scheduled', 'cancelled', 'completed', 'tentative', 'ongoing' |
| `cancelled` | boolean | No | false | Whether this instance is cancelled |
| `completed_at` | timestamp | Yes | NULL | Instance completion timestamp |
| `created_at` | timestamp | Yes | NULL | Record creation timestamp |
| `updated_at` | timestamp | Yes | NULL | Record update timestamp |

### Foreign Keys

- `event_instances.recurring_event_id` → `recurring_events.id` (CASCADE on delete)
- `event_instances.event_id` → `events.id` (SET NULL on delete)

### Indexes

- Primary key on `id`
- Foreign key indexes on `recurring_event_id` and `event_id`

### Notes

- Represents individual occurrences of a recurring event
- `event_id` is nullable and can reference a specific event instance if the user has customized it
- Table structure was simplified in later migrations, removing `instance_start`, `instance_end`, `overridden_title`, `overridden_description`, `overridden_location`, `all_day`, and `timezone` columns
- Status enum was extended to include 'ongoing' in a later migration

---

## Event Exceptions

**Table Name:** `event_exceptions`

### Columns

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | bigint unsigned | No | Auto-increment | Primary key |
| `recurring_event_id` | bigint unsigned | No | - | Foreign key to `recurring_events.id` (cascade on delete) |
| `exception_date` | date | No | - | Date when the exception occurs |
| `is_deleted` | boolean | No | false | Whether this instance was deleted (skipped) |
| `replacement_instance_id` | bigint unsigned | Yes | NULL | Foreign key to `event_instances.id` (null on delete) |
| `reason` | text | Yes | NULL | Reason for the exception |
| `created_by` | bigint unsigned | Yes | NULL | Foreign key to `users.id` (null on delete) |
| `created_at` | timestamp | Yes | NULL | Record creation timestamp |
| `updated_at` | timestamp | Yes | NULL | Record update timestamp |

### Foreign Keys

- `event_exceptions.recurring_event_id` → `recurring_events.id` (CASCADE on delete)
- `event_exceptions.replacement_instance_id` → `event_instances.id` (SET NULL on delete)
- `event_exceptions.created_by` → `users.id` (SET NULL on delete)

### Indexes

- Primary key on `id`
- Unique constraint on `[recurring_event_id, exception_date]`
- Foreign key indexes on `recurring_event_id`, `replacement_instance_id`, `created_by`

### Notes

- Used to skip or modify specific occurrences of a recurring event
- Each recurring event can only have one exception per date
- Can reference a replacement instance if the exception was replaced with a modified version

---

## Relationship Summary

### Core Entities
- **Projects** belong to **Users** (one-to-many)
- **Tasks** belong to **Users** and optionally to **Projects** and **Events** (many-to-one)
- **Events** belong to **Users** (one-to-many)
- **Comments** belong to **Tasks** and **Users** (many-to-one)

### Polymorphic Relationships
- **Collaborations** can belong to **Tasks**, **Projects**, or **Events** (polymorphic many-to-many with Users)
- **Taggables** link **Tags** to **Tasks** or **Events** (polymorphic many-to-many)

### Recurring Patterns
- **Recurring Tasks** have a one-to-one relationship with **Tasks**
- **Task Instances** belong to **Recurring Tasks** and optionally to **Tasks**
- **Task Exceptions** belong to **Recurring Tasks** and optionally to **Task Instances**
- **Recurring Events** have a one-to-one relationship with **Events**
- **Event Instances** belong to **Recurring Events** and optionally to **Events**
- **Event Exceptions** belong to **Recurring Events** and optionally to **Event Instances**

---

## Migration History Notes

### Date/Datetime Migrations
- Projects: `start_date` and `end_date` were converted to `start_datetime` and `end_datetime`
- Tasks: `start_date`, `start_time`, and `end_date` were combined into `start_datetime` and `end_datetime`
- Recurring Tasks: `start_date` and `end_date` were converted to `start_datetime` and `end_datetime`
- Recurring Events: `start_date` and `end_date` were converted to `start_datetime` and `end_datetime`

### Tag System Evolution
- Originally used separate pivot tables: `tag_task` and `tag_events`
- Migrated to a polymorphic `taggables` table to support future extensibility

### Soft Deletes
- Added to `projects`, `tasks`, and `events` tables

### Field Modifications
- Task fields (status, priority, complexity, dates) were made nullable with defaults
- Event fields (start_datetime, end_datetime, timezone, status) were made nullable with defaults
- Recurring events timezone was made nullable
- Event instances table was simplified, removing many override fields
- Task instances table had override fields removed
