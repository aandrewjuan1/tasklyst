## How AI agents should use this file

This file is a **frontend context guide for AI agents** working on this codebase.

- **Primary purpose**: keep new UI and interaction work consistent with the current **Livewire Volt + Blade + Alpine + optimistic UI** architecture.
- **Authoritative patterns**: treat the flows and examples here as the **source of truth** for how to:
  - Call backend trait methods from Alpine using `$wire` / `$wire.$parent.$call(...)`.
  - Implement optimistic UI with proper snapshot, rollback, and error handling.
  - Structure interactive components (list items, tags, recurrence, comments, focus/pomodoro).

### Agent checklist when adding/changing frontend code

- **Before coding**
  - Identify where your change belongs:
    - **Volt/Livewire** (new property/method for backend state or behavior).
    - **Blade** (layout/composition).
    - **Alpine** (local interaction, optimistic behavior, UI state).
  - Find an **existing, similar pattern**:
    - For list items → see `list-item-card.blade.php` + `list-item-card.js`.
    - For dropdowns/pickers → see `recurring-selection.blade.php`, `tag-selection.blade.php`.
    - For optimistic CRUD → see comments, list item delete/skip, tag create/delete.

- **When implementing**
  - **Do** keep Livewire responsible for **data and authorization**, Alpine for **UI state and optimistic UX**.
  - **Do** wrap complex Alpine regions in `wire:ignore` to prevent Livewire from re‑rendering them.
  - **Do** pass initial data into Alpine via `@js(...)` and configure `x-data` objects with pure data (functions defined in JS modules, not inline PHP).
  - **Do** follow the optimistic pattern from `.cursor/optimistic-ui-guide.md`:
    - Snapshot state.
    - Apply optimistic update.
    - Call `$wire.$parent.$call(...)` in `try/catch`.
    - Roll back and show toast on error.
  - **Do** communicate between components with custom events (`$dispatch`, `window.dispatchEvent`) the same way the list, tags, recurrence, comments, focus, and trash modules do.
  - **Do** reuse existing helpers (focus controller, pomodoro utils, relevance helpers) instead of duplicating logic.
  - **Don’t** use `wire:click` for interactions that should be optimistic or fully controlled by Alpine.
  - **Don’t** introduce new state management patterns when Alpine stores/events can handle it.

- **When calling backend methods**
  - **Prefer** `$wire.$parent.$call('methodName', ...)` from Alpine for workspace‑level trait methods, matching the signatures used in this document.
  - Ensure **method names** align with trait methods in `index.php` (`createTask`, `updateTaskProperty`, `skipRecurringTaskOccurrence`, `createTag`, `deleteTag`, `addComment`, etc.).

- **Before finishing**
  - Check that:
    - New components follow the same **structure** as existing ones (trigger + panel, `x-data` structure, placement logic).
    - All optimistic flows implement **snapshot → optimistic update → `$wire` call → rollback on error**.
    - Any new Livewire method you call has a **backend implementation** that follows the backend architecture guide.

Use the rest of this document as **reference** when you need concrete examples of the patterns above.

---

## Frontend Architecture Overview

This document explains the **current frontend architecture** of Tasklyst, focusing on:

- How **Livewire Volt** components expose state and methods from the backend.
- How **Blade** templates compose the workspace UI.
- How **Alpine.js** components implement rich, optimistic UI behavior.
- How the **optimistic UI guide** in `.cursor/optimistic-ui-guide.md` is applied across list items, tag/recurrence selection, and comments.

It describes the patterns already used in the codebase, not a new proposal.

---

## High‑Level Frontend Flow

### Entry: Workspace Volt Component

The primary frontend entry point is the **workspace Volt component**:

- Defined in `resources/views/pages/workspace/⚡index/index.php` as an anonymous Livewire component:
  - Uses `#[Title('Workspace')]`.
  - Uses many traits (`HandlesTasks`, `HandlesEvents`, `HandlesProjects`, `HandlesTags`, `HandlesComments`, `HandlesFiltering`, `HandlesFocusSessions`, etc.).
- Exposes **public properties and computed values** that drive the UI:
  - `selectedDate`, `itemsPage`, `itemsPerPage`, `listContextProjectId`, `listContextEventId`.
  - `tasks`, `events`, `projects`, `overdue`, `upcoming`, `pomodoroSettings`, `tags`, `pendingInvitationsForUser`.
  - `activeFocusSession` for focus/pomodoro UI.
- The `boot(...)` method injects all required **services** and **actions**, which traits call in response to Alpine/Livewire interactions.

The matching Blade template `resources/views/pages/workspace/⚡index/index.blade.php` renders:

- A top toolbar with **search**, filter controls, and invitations.
- A **two‑column layout**: main list (left) and calendar/upcoming panel (right).
- A nested Livewire child component: `<livewire:pages::workspace.list ... />` for the main list.

### Livewire + Alpine Responsibilities

Across the workspace:

- **Livewire** is responsible for:
  - Exposing backend state (`tasks`, `projects`, `events`, `tags`, `filters`, `pomodoroSettings`, etc.).
  - Executing traits’ methods (e.g. `createTask`, `updateTaskProperty`, `skipRecurringTaskOccurrence`) via `$wire.$parent.$call(...)` from Alpine.
  - Handling server‑side search, filters, pagination, and authorization.
- **Alpine.js** is responsible for:
  - **Local UI state** and interactivity (dropdowns, modals, inline editing, focus mode, tag chips, comments UI, skeletons).
  - **Optimistic UI** behavior (instant updates before server responses, with rollback).
  - Communication with Livewire via `$wire.call()` / `$wire.$parent.$call()` following the optimistic UI patterns defined in `.cursor/optimistic-ui-guide.md`.

---

## Optimistic UI Guide and How It’s Applied

The file `.cursor/optimistic-ui-guide.md` defines a **standard pattern** for Alpine + Livewire optimistic actions:

- **Snapshot** current state for rollback.
- **Update UI immediately** (optimistic).
- **Call server asynchronously** with `$wire.call()` (wrapped in `try/catch`).
- **Handle response**: keep optimistic state on success.
- **Rollback on error**: restore from snapshot and show toast.

Key rules from the guide that are systematically applied:

- Use `wire:ignore` on Alpine‑controlled regions so Livewire does not re‑render them.
- Initialize state in `x-data` using `@js()` to safely pass PHP data.
- Prefer **Alpine events** (`@click`, `@input`) over `wire:click` for high‑frequency interactions and optimistic updates.
- For create operations, use **temporary IDs** (`temp-<timestamp>`) to render items before the server returns a real ID.
- Always wrap `$wire.call` in `try/catch` and show error toasts.

These principles are visible in:

- **List item cards** (`resources/views/components/workspace/list-item-card.blade.php` + `resources/js/alpine/list-item-card.js`).
- **Task creation and tag handling** in the workspace list (`resources/views/pages/workspace/⚡list/list.blade.php`).
- **Recurring selection** (`resources/views/components/recurring-selection.blade.php`).
- **Tag selection** (`resources/views/components/workspace/tag-selection.blade.php`).
- **Comments UI** (`resources/views/components/workspace/comments.blade.php`).

---

## Workspace Page Layout and Livewire Integration

### `index.blade.php`: Wiring the Workspace

The workspace Blade view (`index.blade.php`):

- Wraps the entire page in an Alpine root:

  - The top‑level `<section>` uses `x-data="{}"` and configures an Alpine store `focusSession` for cross‑component focus state.
  - List loading states are controlled via Livewire’s `wire:loading` + Alpine skeleton UI.

- Renders the **main list** as a Livewire child:

  ```blade
  <livewire:pages::workspace.list
      :key="'workspace-list-'.$this->selectedDate.'-'.$this->listRefresh"
      :selected-date="$this->selectedDate"
      :items-page="$this->itemsPage"
      :items-per-page="$this->itemsPerPage"
      :projects="$this->projects"
      :events="$this->events"
      :tasks="$this->tasks"
      :overdue="$this->overdue"
      :tags="$this->tags"
      :filters="$this->getFilters()"
      :active-focus-session="$this->activeFocusSession"
      :pomodoro-settings="$this->pomodoroSettings"
      :has-more-items="..."
  />
  ```

  - Livewire passes **hydrated models and collections** into the list component.
  - The list component then **restructures them** into `$items` collections and passes them to `x-workspace.list-item-card`.

### `pages::workspace.list`: Blade + Alpine for Creation and Listing

`resources/views/pages/workspace/⚡list/list.blade.php` is a Blade view used by the `pages::workspace.list` component. It:

- Declares a large Alpine `x-data` block for **inline creation** of tasks, events, and projects:
  - Maintains `formData` for each kind (task, event, project).
  - Implements **client‑side date‑range validation** in `validateDateRange()` mirroring backend `TaskPayloadValidation::validateTaskDateRangeForUpdate()`.
  - Implements **tag selection and creation** logic (`toggleTag`, `isTagSelected`, `createTagOptimistic`, `deleteTagOptimistic`).
  - Submits via `$wire.$parent.$call('createTask', payload)` / `createEvent` / `createProject` with a minimum loading animation delay.
  - Dispatches and listens to events (`@tag-created.window`, `@tag-deleted.window`, `@date-picker-updated`, `@recurring-selection-updated`, `@item-form-updated`).

- Provides an **"Add" dropdown** using Flux UI and Alpine:
  - Toggles `creationKind` (`task` / `event` / `project`) and shows a creation card.
  - Uses `<x-recurring-selection>` and `<x-workspace.tag-selection>` inside the card, wired to the same Alpine state.

- Prepares **combined and paginated items**:
  - Merges overdue items and date‑filtered items into a unified `$items` collection.
  - Uses `$itemsPerPage` and `$itemsPage` to compute `visibleItemCount` and whether to show infinite scrolling.
  - Uses Alpine (`x-data`) to track `visibleItemCount` and show an empty state with a **debounced delay** when all items disappear (e.g. via optimistic deletion or filter change).

- Renders each item through the **list item card component**:

  ```blade
  <x-workspace.list-item-card
      :kind="$entry['kind']"
      :item="$entry['item']"
      :list-filter-date="$entry['isOverdue'] ? null : $selectedDate"
      :filters="$filters"
      :available-tags="$tags"
      :is-overdue="$entry['isOverdue']"
      :active-focus-session="$activeFocusSession ?? null"
      :default-work-duration-minutes="$defaultWorkDurationMinutes"
      :pomodoro-settings="$this->pomodoroSettings"
      wire:key="{{ $entry['kind'] }}-{{ $entry['item']->id }}"
  />
  ```

- Implements **infinite scroll** using `x-intersect` to call `loadMoreItems` on the parent Livewire component.

This view is the bridge between backend collections (Livewire) and Alpine components for item creation and listing.

---

## List Item Card Architecture

### Blade Wrapper: `list-item-card.blade.php`

`resources/views/components/workspace/list-item-card.blade.php`:

- Uses a **ViewModel** (`ListItemCardViewModel`) to compute:
  - Derived display data (status labels, classes, badges, tags, effective status, overdue flags).
  - The `alpineConfig` object containing **pure data configuration** for the Alpine component (no functions).

- Renders a `div` with:

  - `wire:ignore` to give full DOM control to Alpine.
  - `x-data="listItemCard(@js($alpineConfig))"` initializing the Alpine component from `resources/js/alpine/list-item-card.js`.
  - `x-init="alpineReady = true"` and `x-cleanup="destroy()"` to register/unregister the card.
  - `x-show="!hideCard"` and transitions for appearing/disappearing.

- Listens to **Alpine events**:
  - `@dropdown-opened` / `@dropdown-closed` for z‑index and locking.
  - `@recurring-selection-updated`, `@recurring-revert` to sync Alpine recurrence with the shared recurrence component.
  - `@item-property-updated`, `@item-update-rollback` so external updates (e.g. other widgets or background actions) can influence the card.
  - `@focus-session-updated.window`, `@task-duration-updated` to react to focus/pomodoro changes.

- Renders two contexts:

  - A **teleported focus modal** for tasks (`x-teleport="body"`), which:
    - Reuses the same header + body as the in‑list card.
    - Adds a focus bar (`list-item-card.focus-bar`) for focus/pomodoro controls.
    - Traps focus and communicates with a global `focusModal` Alpine store.

  - The **in‑list card**, including:
    - A “Focus” button for tasks that sets up focus mode.
    - Kind‑specific partials: `list-item-project`, `list-item-event`, `list-item-task`.
    - Subtasks panel for projects/events (`<x-workspace.subtasks>`).
    - Comments (`<x-workspace.comments>`).

The card is the primary **Alpine‑controlled shell** around each item.

### Alpine Component: `list-item-card.js`

`resources/js/alpine/list-item-card.js` exports `listItemCard(config)`:

- Merges `config` from the ViewModel with **rich Alpine behavior**:
  - Focus session and pomodoro handling via `createFocusSessionController()` and utility libs.
  - Item relevance and filter logic via `list-relevance.js`.
  - Pomodoro settings via `pomodoro.js`.

Key responsibilities:

1. **Global Registration and Stores**
   - In `init()`, each card:
     - Creates a focus controller (`this._focus = createFocusSessionController()`).
     - Registers itself in `Alpine.store('listItemCards')` by item ID for cross‑card interaction.
     - Watches `isFocusModalOpen` and keeps a `focusModal` store in sync (to support scroll locking).
     - Subscribes to global custom events (`workspace-subtask-unbound`, `workspace-task-parent-set`, `workspace-project-name-updated`, `workspace-event-title-updated`, `workspace-project-trashed`, `workspace-event-trashed`) to:
       - Update project/event chips on cards.
       - Hide/restore relationships when parent items change.

2. **Focus / Pomodoro UI**
   - Derived getters like `isFocusModalOpen`, `isFocused`, `isBreakFocused`, `isPomodoroSession`, `pomodoroSequenceText`, `nextSessionDurationText`.
   - Wrapper methods that delegate to `this._focus`:
     - `enterFocusReady`, `startFocusFromReady`, `startFocusMode`, `stopFocus`, `pauseFocus`, `resumeFocus`, `completePomodoroSession`, `startNextSession`, etc.
   - Plays completion sounds and manages countdown tickers via the focus controller.

3. **Optimistic Item Deletion**

   The `deleteItem` method implements the **5‑phase optimistic pattern**:

   - **Phase 1 (snapshot)**:
     - Saves `hideCard`, `focusReady`, and `activeFocusSession` into a snapshot object.
   - **Phase 2 (optimistic update)**:
     - Calls `hideFromList()` (sets `hideCard = true`, clears focus, dispatches `list-item-hidden`).
     - Dispatches global events (`workspace-subtask-trashed`, `workspace-project-trashed`, `workspace-event-trashed`) for parent UIs.
     - Dispatches `workspace-item-trashed` so the trash popover can **optimistically** show the deleted item.
   - **Phase 3 (server call)**:
     - Uses `$wire.$parent.$call(this.deleteMethod, this.itemId)` to call Livewire trait methods like `deleteTask`, `deleteEvent`, or `deleteProject` on the parent workspace component.
   - **Phase 4/5 (response + rollback)**:
     - On failure or exception, calls `rollbackDeleteItem(snapshot, wasOverdue)`, which:
       - Restores visibility and focus state.
       - Dispatches `list-item-shown` and `workspace-item-trashed-rollback` events.
       - Shows an error toast via `$wire.$dispatch('toast', ...)`.

   This follows the optimistic UI guide’s DELETE pattern almost verbatim.

4. **Optimistic Recurrence Skip**

   `skipThisOccurrence` implements optimistic skip of recurring occurrences:

   - Guards on `recurringEventId` / `recurringTaskId` and `exceptionDate`.
   - **Snapshots** `hideCard`.
   - **Optimistically hides** the card and dispatches `list-item-hidden`.
   - Calls `$wire.$parent.$call('skipRecurringEventOccurrence'|'skipRecurringTaskOccurrence', payload)`.
   - On failure, **restores** `hideCard` and dispatches `list-item-shown`, showing an error toast.

5. **Inline Title and Description Editing**

   - `startEditingTitle`, `saveTitle`, `cancelEditingTitle`:
     - Snapshot original title.
     - Optimistically update `editedTitle` locally.
     - Call `$wire.$parent.$call(updatePropertyMethod, itemId, 'title', trimmedTitle, false)` (which forwards to `HandlesTasks::updateTaskProperty`).
     - On error or validation failure, restore snapshot and show toast.
   - The same pattern applies to **description** editing with `saveDescription`, handling null values and whitespace normalization.

6. **Recurrence Updates**

   - `updateRecurrence(value)`:
     - Snapshots `this.recurrence`.
     - Sets `this.recurrence = value` optimistically and updates skip UI.
     - Calls the trait’s `updateTaskProperty`/`updateEventProperty` via `$wire.$parent.$call(updatePropertyMethod, itemId, 'recurrence', value, false)`.
     - Handles the special **response payload** containing `recurringTaskId` / `recurringEventId`.
     - On failure, reverts value, dispatches `recurring-revert` for the shared `x-recurring-selection` component, and shows a toast.

7. **Filter‑Aware Hiding and Rollback**

   - `shouldHideAfterPropertyUpdate(detail)` examines:
     - Date ranges vs. list filter date and overdue state.
     - Active filters (status, priority, complexity, tag IDs, recurring filters).
   - If a property update makes the item irrelevant to current filters, `onItemPropertyUpdated` hides the card optimistically.
   - `onItemUpdateRollback` re‑shows the card if a backend update fails.

In sum, `listItemCard.js` is the **core optimistic UI engine** for each list item, consistently following the guide’s snapshot → optimistic update → `$wire.call` → rollback pattern.

---

## Shared Components and Their Frontend Flows

### Recurrence Selection: `recurring-selection.blade.php`

This component provides the UI for **configuring recurrence** (daily/weekly/monthly/yearly) and managing skipped dates:

- Props:
  - `model` (Alpine path, e.g. `formData.item.recurrence`).
  - `position`, `align` (popover placement).
  - `initialValue` (server‑side recurrence payload).
  - `kind` (`task`/`event`).
  - `readonly`, `hideWhenDisabled`, `compactWhenDisabled`.
  - `recurringEventId`, `recurringTaskId` for fetching exceptions.

- Alpine `x-data` state:
  - Copies `initialRecurrence` into `enabled`, `type`, `interval`, `daysOfWeek`.
  - Maintains `currentValue` and `valueWhenOpened` to detect changes.
  - Computes `formatDisplayValue()` for the trigger label (e.g. “Every 2 weeks (Mon, Wed)”).

- **Opening behavior (`toggle`)**:
  - Computes placement based on trigger position and viewport.
  - Auto‑enables recurrence and sets type to `daily` if disabled when opening.
  - Stores `valueWhenOpened` and dispatches `recurring-selection-opened`.
  - If `recurringEventId` or `recurringTaskId` is set, triggers `loadSkippedDates()` to fetch skipped exceptions optimistically from Livewire trait methods `getEventExceptions` / `getTaskExceptions`.

- **Skipped dates UI**:
  - Shows a scrollable list of exceptions with **Restore** buttons.
  - `restoreException(ex)` uses the optimistic DELETE pattern:
    - Snapshot (removed row), optimistically remove the exception from UI.
    - Call `restoreRecurringEventOccurrence` or `restoreRecurringTaskOccurrence`.
    - On failure, restore the row and show a toast using `getRestoreErrorMessage`.

- **Closing behavior (`close`)**:
  - If the value changed relative to `valueWhenOpened`, emits:

    ```js
    this.$dispatch('recurring-selection-updated', {
      path: this.modelPath,
      value: this.getCurrentRecurrenceValue(),
    });
    ```

  - The card’s Alpine component listens for this and calls the backend update actions.

This component encapsulates recurrence UX, while delegating persistence and optimistic updates to the list‑item Alpine and Livewire traits.

### Tag Selection: `workspace/tag-selection.blade.php`

The tag selection component provides:

- A **trigger button** that:
  - Shows **server‑rendered tags** for first paint.
  - Switches to **Alpine‑rendered tags** once hydrated (using `selectedTagPills`).

- Alpine `x-data` encapsulating:
  - `mergedTags`: deduped, sorted union of tags provided from the server and tags selected on first paint.
  - `selectedTagPills`: derived from `formData.item.tagIds` and `tags`/`initialSelectedTags`.
  - Placement logic for its dropdown (same pattern as recurrence & date picker).

- Dropdown menu listing all tags:
  - Each row:
    - A checkbox reflecting `isTagSelected(tag.id)`.
    - Emits `@tag-toggled` with `{ tagId }` on click.
    - Has a delete button that emits `@tag-delete-request` with `{ tag }`.

The workspace list’s Alpine root (`list.blade.php`) listens for:

- `@tag-toggled` → uses `toggleTag(tagId)` to manage `formData.item.tagIds`.
- `@tag-create-request` → uses `createTagOptimistic(tagName)`:
  - Uses a **temp ID** and pushes an optimistic tag into `this.tags`.
  - If tag already exists (case‑insensitive), toggles selection and shows “Tag already exists.” toast.
  - Calls `$wire.$parent.$call('createTag', tagName)` and relies on `@tag-created` events to replace temp IDs.
- `@tag-delete-request` → uses `deleteTagOptimistic(tag)`:
  - Snapshots `tags` and `tagIds`.
  - Optimistically removes the tag and its selection, then calls `$wire.$parent.$call('deleteTag', tag.id)`.
  - On error, restores tags and selection, shows a toast.

This component is a concrete application of **Alpine‑driven optimistic behavior**:

- The dropdown itself only emits events.
- The list‑level Alpine logic performs snapshots, optimistic updates, `$wire.$parent.$call`, and rollback.

### Comments: `workspace/comments.blade.php`

The comments component provides a **self‑contained optimistic UI** for item comments:

- Props:
  - `item` (Task/Event/Project model).
  - `kind` (for labeling).
  - `readonly` (read‑only mode for collaborators without comment permissions).

- Initial server rendering:
  - `comments` collection is transformed into `commentsForJs` with:
    - `id`, `userName`, `initials`, `content`, `createdDiff`, `canManage`.
  - Renders a basic “Comments (N)” button and, if present, a simple list.

- Alpine `x-data`:
  - Replaces the server rendering on hydration.
  - State:
    - `comments`, `totalCount`, `visibleCount`, `visibleComments`.
    - `isAddingComment`, `newCommentContent`, snapshot/backup fields.
    - `editingCommentId`, `editedCommentContent`, snapshot for edit.
    - `deletingCommentIds` set.
  - Methods:
    - `updateVisibleComments`, `loadMore` (with optional `$wire.$parent.$call('loadMoreComments', ...)`).
    - `startAddingComment` / `cancelAddingComment`.
    - `saveComment`:
      - **Snapshots** lists and counts.
      - Creates an optimistic comment with temp ID and current user info.
      - Calls `$wire.$parent.$call('addComment', payload)` and replaces temp ID on success.
      - On error, restores from snapshot and shows `commentErrorToast`.
    - `startEditingExistingComment`, `cancelEditingExistingComment`, `saveEditedComment`:
      - Snapshots the comment, applies optimistic content update.
      - Calls `$wire.$parent.$call('updateComment', id, payload)`.
      - On failure, restores snapshot and shows `commentUpdateErrorToast`.
    - `deleteExistingComment`:
      - Snapshots the entire comments list and counts.
      - Optimistically removes the comment and decreases counts.
      - Calls `$wire.$parent.$call('deleteComment', id)`.
      - On failure, restores snapshot and shows `deleteCommentErrorToast`.

The comments component is a **textbook implementation** of the optimistic UI guide: snapshots, temp IDs, `$wire.$parent.$call`, and robust error handling.

---

## Event and Trait Method Calls from Alpine

Throughout these components, **calling trait methods** from Alpine follows a consistent pattern:

- For **workspace‑wide actions** defined on the parent Volt component (using `Handles*` traits):

  ```javascript
  $wire.$parent.$call('createTask', payload)
  $wire.$parent.$call('createEvent', payload)
  $wire.$parent.$call('createProject', payload)
  $wire.$parent.$call('updateTaskProperty', itemId, property, value, silentToasts, occurrenceDate)
  $wire.$parent.$call('deleteTask', itemId)
  $wire.$parent.$call('skipRecurringTaskOccurrence', payload)
  $wire.$parent.$call('getTaskExceptions', recurringTaskId)
  $wire.$parent.$call('restoreRecurringTaskOccurrence', exceptionId)
  $wire.$parent.$call('createTag', tagName)
  $wire.$parent.$call('deleteTag', tagId)
  $wire.$parent.$call('addComment', payload)
  $wire.$parent.$call('updateComment', id, payload)
  $wire.$parent.$call('deleteComment', id)
  ```

- For **nested Livewire components** (e.g. focus session handling inside cards), Alpine uses `$wire` on the current component, and `$parent` when needed, while keeping the backend method names aligned with trait methods.

All of these calls:

- Follow the **non‑blocking** pattern from the optimistic UI guide (`const promise = $wire.$parent.$call(...); const result = await promise;`).
- Use `try/catch` to detect failures and rollback UI.
- Use `$wire.$dispatch('toast', ...)` to show consistent toasts across the workspace.

---

## How to Extend the Frontend in This Architecture

When adding new frontend behavior, follow these patterns:

1. **Decide who owns the state**:
   - **Server/Liwewire** for data that must be persisted or authorized (tasks, events, focus sessions, tags).
   - **Alpine** for presentation state (open/closed, modals, temporary inputs, loading flags, optimistic state).

2. **Expose backend operations** on the Volt component via traits:
   - Add/extend trait methods (`HandlesX`) that call actions/services.
   - Call those methods from Alpine via `$wire.$parent.$call(...)`.

3. **Use `wire:ignore`** for any Alpine‑controlled areas where DOM changes are complex or heavily interactive (cards, dropdowns, tag pickers, comments).

4. **Apply the optimistic UI pattern for interactive features**:
   - Snapshot local state before changes.
   - Update Alpine state immediately to reflect the user’s intent.
   - Call `$wire.call` / `$wire.$parent.$call` in `try/catch`.
   - On success, leave state as is or reconcile with the server response.
   - On error, rollback to snapshot and show a toast.

5. **Communicate via custom events**:
   - Use `$dispatch` and `window.dispatchEvent(new CustomEvent(...))` to keep related Alpine components in sync (subtasks, trash, tag creation/deletion, focus session updates).
   - Listen with `@event-name` or `@event-name.window` as appropriate.

6. **Hydrate progressively**:
   - Render a **server‑side fallback** first (e.g. plain tags or comments).
   - Hide it when `alpineReady` is true and replace it with the Alpine‑rendered version.

By following these patterns, new frontend features will integrate cleanly with the existing Livewire + Alpine architecture and maintain the same optimistic, responsive user experience. 

