# Workspace Index & List Refactoring Plan — Islands-Based Scroll Pagination

This document describes a phased refactor of the workspace index and list so that scroll pagination uses Livewire 4 **Islands** (replace mode). The goal is to avoid full list remounts on "load more" while keeping all existing behavior intact, including **list-item-card** and its subcomponents.

---

## 1. Current Architecture Summary

### 1.1 Component hierarchy

```
Index (Livewire) — pages::workspace.index
├── selectedDate, listRefresh, tasksPage, eventsPage, projectsPage
├── Computed: tasks(), events(), projects(), overdue(), getFilters(), pomodoroSettings()
├── Actions: loadMoreItems(), createTask(), createEvent(), createProject(), updateTaskProperty(), updateEventProperty(), updateProjectProperty(), deleteTask(), deleteEvent(), deleteProject(), createTag(), deleteTag(), … (HandlesTasks, HandlesEvents, HandlesProjects, HandlesTags, HandlesFocusSessions, etc.)
├── index.blade.php
│   ├── Date switcher, filters, trash, pending invitations
│   ├── <livewire:pages::workspace.list> (child) with :key="'workspace-list-'.selectedDate.'-'.listRefresh"
│   │   Props: selectedDate, projects, events, tasks, overdue, tags, filters, activeFocusSession, pomodoroSettings, hasMoreItems
│   └── Skeleton (wire:loading.delay for filter/date changes)
│
List (Livewire) — pages::workspace.list
├── Receives all data as props (no own queries)
├── list.blade.php
│   ├── @php: builds $items = overdue + (projects + events + tasks merged, sorted by created_at)
│   ├── Add dropdown + creation form (task/event/project) — calls $wire.$parent.$call('createTask'|'createEvent'|'createProject', …)
│   ├── Loading card (after submit)
│   ├── Empty state (when $items->isEmpty() && $overdue->isEmpty())
│   ├── Wrapper with visibleItemCount, @list-item-hidden.window, @list-item-shown.window
│   ├── @foreach ($items as $entry) → <x-workspace.list-item-card … wire:key="kind-id" />
│   └── Load-more sentinel (IntersectionObserver) → $wire.$parent.$call('loadMoreItems')
```

### 1.2 List-item-card and dependencies

- **list-item-card.blade.php**  
  Uses `ListItemCardViewModel` for `viewData()` and `alpineConfig()`. Renders focus bar, header, and delegates to:
  - **list-item-task.blade.php** / **list-item-event.blade.php** / **list-item-project.blade.php**
  - **comments.blade.php**
  - Other subcomponents (focus-bar, header, etc.)

- **list-item-card.js**  
  `listItemCard(config)` Alpine component:
  - Uses **$wire.$parent.$call(deleteMethod)**, **$wire.$parent.$call(updatePropertyMethod, …)** for delete, saveTitle, saveDescription, updateRecurrence.
  - Dispatches **list-item-hidden** / **list-item-shown** (window) for visible count.
  - Dispatches **toast**, **focus-session-updated**, etc.
  - Registers in `Alpine.store('listItemCards')`, uses focus controller, pomodoro, etc.

- **list-item-task.blade.php** / **list-item-event.blade.php**  
  Inline Alpine / Blade: **$wire.$parent.$call('createTag', …)**, **$wire.$parent.$call('deleteTag', …)**, **$wire.$parent.$call(updatePropertyMethod, …)**.

- **comments.blade.php**  
  **$wire.$parent.$call('loadMoreComments', …)**, **addComment**, **updateComment**, **deleteComment**.

- **activity-logs-popover.blade.php**  
  **$wire.$parent.$call('loadMoreActivityLogs', …)**.

- **collaborators-popover.blade.php**  
  **$wire.$parent.$call(…)** for invite, update permission, etc.

- **list-item-project.blade.php**  
  **$wire.$parent.$call(updatePropertyMethod, …)**.

All of these assume the card (or the component that contains them) is a **child** of the component that owns the actions — i.e. **Index**. So today: **$wire** = List, **$wire.$parent** = Index.

After refactor, when the feed is rendered by **Index** (inside an island), the cards will be under Index’s DOM. Then **$wire** = Index and **$wire.$parent** = layout. So any **$wire.$parent** in card or subcomponents would call the wrong component unless we introduce a **livewire call target** and use it everywhere.

### 1.3 Data flow

- **Index** owns: `tasks()`, `events()`, `projects()`, `overdue()` (computed), and pagination state.
- **List** receives these as props and in the view builds **$items** (overdue + merged date items, sorted).
- **Load more**: List’s sentinel calls `$wire.$parent.$call('loadMoreItems')` → Index increments `tasksPage`, `eventsPage`, `projectsPage`, **listRefresh**. Key change forces List remount → full list re-render.

### 1.4 Events and side effects

- **task-created** / **event-created** / **project-created**: List root listens and calls `resetForm()`.
- **tag-created.window** / **tag-deleted.window**: List updates local tags and form selection.
- **list-item-hidden** / **list-item-shown**: Wrapper in List updates `visibleItemCount` and toggles empty state after delay.
- **focus-session-updated.window**: List and cards sync focus state.

---

## 2. Refactor Goals

1. Use a **Livewire Island** (replace mode) for the **feed** (the list of cards) so that only the island re-renders on load more, not the whole List or page.
2. **Do not** increment **listRefresh** in **loadMoreItems()** so the List component is not remounted when loading more.
3. Keep **list-item-card** (Blade + Alpine) and all subcomponents working: same props, same behavior (focus, pomodoro, comments, collaborators, activity log, inline edit, delete, tags, recurrence, etc.).
4. Preserve empty state, visible-item count, and “load more” sentinel behavior.
5. Preserve filter/date change behavior (skeleton, list refresh, pagination reset).

---

## 3. Critical Invariants (Must Remain True)

- **list-item-card** always receives: `kind`, `item`, `listFilterDate`, `filters`, `availableTags`, `isOverdue`, `activeFocusSession`, `defaultWorkDurationMinutes`, `pomodoroSettings`.
- **ListItemCardViewModel** continues to be used for view data and Alpine config; no breaking changes to its public API.
- All Livewire actions that the card (and comments, collaborators, activity log, list-item-task/event/project) need live on **Index**: `updateTaskProperty`, `updateEventProperty`, `updateProjectProperty`, `deleteTask`, `deleteEvent`, `deleteProject`, `createTag`, `deleteTag`, `loadMoreComments`, `addComment`, `updateComment`, `deleteComment`, `loadMoreActivityLogs`, collaboration methods, etc.
- Cards must call **that** Livewire component. Today they do it via **$wire.$parent** (List’s parent = Index). After refactor, when the feed is rendered by Index, **$wire** will be Index, so we must use **$wire** (not **$wire.$parent**) for those calls. So we need a single **livewire call target** (Index) used by card and all subcomponents.

---

## 4. Phased Refactor Plan

### Phase 0: Preparation and safety net

**Goal:** Lock current behavior with tests and notes so refactor can be validated.

**Tasks:**

1. **Document current behavior**
   - List which tests already cover workspace list, filters, date change, load more, and list-item-card (e.g. TaskCrudLivewireTest, EventCrudLivewireTest, ProjectCrudLivewireTest, etc.).
   - Ensure `listRefresh` and pagination reset (date/filter) are covered (add a minimal test if missing).

2. **Smoke checklist (manual)**
   - Create task/event/project from list; edit title/description; change status/priority/dates; delete; restore from trash.
   - Focus mode / pomodoro on a task; start/complete/abandon.
   - Comments: add, edit, delete, load more.
   - Collaborators: invite, change permission.
   - Activity log: load more.
   - Tags on task/event (create tag, delete tag, select/deselect).
   - Recurrence: enable, change, skip occurrence.
   - Scroll to load more; change date; change filters; empty state and “no items” message.

3. **Optional: feature flag**
   - Add a config or env flag (e.g. `workspace.use_feed_island`) so the new island-based feed can be toggled off until Phase 4 is stable.

**Deliverables:** Test list, smoke checklist, optional feature flag. No code change to Index/List/card logic yet.

---

### Phase 1: Feed data on Index and “livewire call target”

**Goal:** Index owns the merged feed list and exposes a stable way for cards to call Index (whether card is rendered by List or later by Index).

**Tasks:**

1. **Add `feedItems()` computed on Index**
   - Implement the same merge logic that List view currently does in `@php`:
     - Overdue items (with `isOverdue` flag).
     - Date items: merge `projects`, `events`, `tasks` (from existing computeds), sort by `created_at` desc, then merge with overdue.
   - Return a collection of entries with keys: `kind`, `item`, `isOverdue` (and optionally `listFilterDate` per entry for convenience).
   - Use existing `overdue()`, `tasks()`, `events()`, `projects()`; no new queries, just merge/sort in PHP.
   - Add a simple test or assertion that `feedItems()` count matches current List `$items->count()` for the same state.

2. **Introduce “livewire call target” for the card**
   - **Option A (recommended):** Add a small helper in the card’s Alpine so that one place decides “call Index”:
     - Add to `ListItemCardViewModel::alpineConfig()` a key, e.g. `livewireCallTarget: 'parent'` (meaning “use $wire.$parent”).
     - When the card is rendered by **List**, keep `livewireCallTarget: 'parent'`.
     - When the card will be rendered by **Index** (Phase 3), pass `livewireCallTarget: 'self'` (meaning “use $wire”).
   - In **list-item-card.js**: add a getter, e.g. `get $livewireTarget() { return this.livewireCallTarget === 'self' ? this.$wire : this.$wire.$parent; }`, and replace every `this.$wire.$parent.$call(...)` with `this.$livewireTarget.$call(...)` (delete, saveTitle, saveDescription, updateRecurrence, and any other direct `$parent` call).
   - In **list-item-card.blade.php**: add `@props(['livewireCallTarget' => 'parent'])` and pass it into the ViewModel so it can be added to `alpineConfig()`.

3. **Subcomponents that use $wire.$parent**
   - **list-item-task.blade.php**, **list-item-event.blade.php**, **list-item-project.blade.php**, **comments.blade.php**, **activity-logs-popover.blade.php**, **collaborators-popover.blade.php** all use `$wire.$parent.$call(...)` in inline Alpine.
   - Preferred approach: have the **card** expose a single method, e.g. `callLivewire(method, ...args)` that does `return this.$livewireTarget.$call(method, ...args)`, and pass the target from the card’s Alpine scope. Child components sit inside the card’s `x-data`, so they can use `$root` (or the card’s Alpine component reference) to call `callLivewire`. So:
     - In **list-item-card.js**: add `callLivewire(method, ...args) { return this.$livewireTarget.$call(method, ...args); }`.
     - In **list-item-task** / **list-item-event** / **list-item-project**: replace `$wire.$parent.$call(...)` with `$root.callLivewire(...)` (or equivalent) using the card’s root.
     - In **comments.blade.php**, **activity-logs-popover**, **collaborators-popover**: same idea — call `$root.callLivewire(...)` if they are inside the card’s tree; otherwise keep a way to resolve “the Index” (e.g. still `$wire.$parent` when inside List). So we need to ensure comments/activity/collaborators are always under the card; then `$root` is the card and `$root.callLivewire` uses the card’s `$livewireTarget`.
   - Document in this file: which Blade files were changed to use `$root.callLivewire` and which still use `$wire.$parent` (if any).

**Deliverables:**  
- Index has `feedItems()` with the same logical content as List’s `$items`.  
- Card (and subcomponents) use a single “call Index” path via `$livewireTarget` or `callLivewire`, with `livewireCallTarget` defaulting to `'parent'` so current behavior is unchanged.  
- All existing tests and smoke checklist still pass.

---

### Phase 2: List-item-card works when rendered by Index (self target)

**Goal:** When the card is rendered by Index (e.g. in a test or a temporary duplicate block), it works with `livewireCallTarget: 'self'` so that `$wire` is Index.

**Tasks:**

1. **ViewModel and Blade**
   - Ensure `ListItemCardViewModel` (or the Blade that builds the config) can receive `livewireCallTarget` (e.g. from a prop) and that it is included in `alpineConfig()` so the JS uses it.
   - No change to list-item-card.js logic if Phase 1 is done; only the value of `livewireCallTarget` changes when the card is rendered from Index.

2. **Temporary test in Index view**
   - In Index blade, add a **temporary** block that renders one or two cards using `$this->feedItems()->take(2)` and pass `livewireCallTarget: 'self'`. Verify: inline edit, delete, focus, comments (if applicable). Remove this block before Phase 3 or guard it with the feature flag.

**Deliverables:** Confirmation that cards rendered with `livewireCallTarget: 'self'` and `$wire` = Index behave correctly. No production change to layout yet.

---

### Phase 3: Move feed into Index as an Island; List keeps chrome only

**Goal:** The feed (list of cards) is rendered by Index inside an `@island(name: 'feed')`. List no longer renders the card loop; it only renders Add dropdown, creation form, loading card, empty state, and load-more sentinel.

**Tasks:**

1. **Index view (index.blade.php)**
   - Where the current `<livewire:pages::workspace.list … />` is rendered, restructure so that:
     - The **feed** (cards) is rendered by Index in an island:
       - `@island(name: 'feed')`
         - Build `$feedItems = $this->feedItems` (use the computed from Phase 1).
         - If empty and no overdue: show the same empty-state UI (or delegate to a partial).
         - Else: `@foreach ($feedItems as $entry)` render `<x-workspace.list-item-card ... wire:key="..." livewireCallTarget="self" />` with the same props the List currently passes (kind, item, listFilterDate, filters, availableTags, isOverdue, activeFocusSession, defaultWorkDurationMinutes, pomodoroSettings).
       - `@endisland`
     - The **List** component is still rendered but with a reduced role: it receives the same props as today (for empty state and sentinel logic) but **does not** render the `@foreach ($items as $entry)` block or the load-more sentinel inside the list of cards. So List view must be changed to:
       - Render Add dropdown, creation form, loading card.
       - Render empty state when appropriate (can be driven by a prop from Index, e.g. `feedIsEmpty` or use List’s current logic if it still has access to items count).
       - Render the **load-more sentinel** (IntersectionObserver) that triggers the **parent’s** island: e.g. `$wire.$parent.$island('feed').loadMoreItems()` (because List’s parent is Index, and the island lives on Index). Use Livewire’s `wire:island` / `$wire.$parent.$island('feed')` as per docs.

2. **List view (list.blade.php)**
   - Remove the `@php` block that builds `$items` and the `@foreach ($items as $entry)` loop (the feed is now in Index).
   - Keep: Add dropdown, creation form, loading card, empty state block, and the wrapper that listens to `list-item-hidden` / `list-item-shown` if we keep visible count in List (see Phase 5).
   - Add the load-more sentinel that calls `$wire.$parent.$island('feed').loadMoreItems()` (and optionally use `wire:island.append` in a later phase; for replace mode we use normal island refresh).
   - List may need a prop such as `hasMoreItems` and `feedIsEmpty` (or `totalFeedCount`) from Index so it can show/hide empty state and “load more” correctly. Index can pass these from `feedItems()->isEmpty()`, `hasMoreTasks || hasMoreEvents || hasMoreProjects`, etc.

3. **List component (list.php)**
   - Remove or relax props that were only used for the card loop (e.g. tasks, events, projects, overdue can become optional or be removed if List no longer builds `$items`). Keep props needed for creation form (e.g. tags, project names) and for empty state / sentinel (e.g. hasMoreItems, and a way to know if feed is empty — either a new prop or derive from existing ones).
   - Ensure List still receives and forwards any data the creation form or empty state need (e.g. tags, filters for labels).

4. **Wire:key and listRefresh**
   - The list’s Livewire key can stay `'workspace-list-'.selectedDate.'-'.listRefresh` so that when date or filters change (and listRefresh is incremented), the List still remounts. For load more we will **not** increment listRefresh (Phase 4).

**Deliverables:**  
- Feed is rendered by Index inside `@island(name: 'feed')` with cards using `livewireCallTarget="self"`.  
- List no longer renders the card loop; it renders chrome + empty state + load-more sentinel that triggers Index’s island.  
- All card behavior (edit, delete, focus, comments, etc.) still works.  
- Manual smoke and existing tests pass.

---

### Phase 4: Load more only refreshes the Island; remove listRefresh from loadMoreItems

**Goal:** When the user scrolls and the sentinel fires, only the island re-renders; the List component does not remount.

**Tasks:**

1. **loadMoreItems()**
   - Remove `$this->listRefresh++` from `loadMoreItems()`. Keep incrementing `tasksPage`, `eventsPage`, `projectsPage` so `tasks()`, `events()`, `projects()` return more data and `feedItems()` on Index includes the new items.

2. **Trigger load more against the island**
   - Ensure the load-more sentinel in List calls the **parent (Index)** and targets the island, e.g. `$wire.$parent.$island('feed').loadMoreItems()` so that the request is run on Index and only the `feed` island’s HTML is re-rendered and replaced. No change to List’s key, so List does not remount.

3. **Verify**
   - Scroll to load more; confirm new cards appear and that the list (Add, form, empty state) does not remount (e.g. no loss of focus or form state).
   - Confirm date/filter change still increments listRefresh (via existing HandlesFiltering / updatedSelectedDate) so the list area still refreshes on context change.

**Deliverables:** Load more only updates the island; listRefresh is not used for load more. All tests and smoke checklist pass.

---

### Phase 5: Empty state and visible-item count

**Goal:** Empty state and “visible item count” (for showing empty state after all items are removed) still work when the feed lives in the island.

**Tasks:**

1. **Empty state**
   - Today: List decides empty state with `$items->isEmpty() && $overdue->isEmpty()` and shows a message. After refactor, Index has `feedItems()`; List may not have the full feed. So either:
     - Index passes a boolean prop to List, e.g. `feedIsEmpty`, and List shows the same empty-state UI when `feedIsEmpty` is true, or
     - Empty state is rendered **inside** the island when `feedItems()->isEmpty()` (and no overdue), and List does not render its own empty state when the island is used. Prefer one source of truth (Index) and pass `feedIsEmpty` to List so List can show the same block without duplicating logic.
   - Ensure “no items for :date” and filter-related messages stay correct.

2. **Visible item count (list-item-hidden / list-item-shown)**
   - Cards dispatch `list-item-hidden` and `list-item-shown` on the window. The wrapper that updates `visibleItemCount` and shows the delayed empty state is currently in List. After the feed moves to the island, the cards are in Index’s DOM; the events still bubble to window, so List can keep listening for `@list-item-hidden.window` and `@list-item-shown.window` and updating `visibleItemCount` **if** the “empty state after all hidden” UI remains in List. Alternatively, that wrapper could move to Index (e.g. around the island) so the component that owns the feed also owns the visible count. Decide and implement:
     - Either keep the wrapper in List and rely on window events (no change to event names).
     - Or move the wrapper to Index and have the island’s parent div listen for the same window events and hold `visibleItemCount`; then List no longer needs that logic.
   - Ensure when the last visible card is removed (e.g. delete or move to trash), the empty state appears after the usual short delay.

**Deliverables:** Empty state and “all items hidden” behavior work; no duplicate empty states; events and counts are consistent.

---

### Phase 6: Tests, cleanup, optional append mode

**Goal:** Automated coverage for the new flow; remove temporary code and feature flags; optionally consider island append mode later.

**Tasks:**

1. **Tests**
   - Run full workspace-related test suite (Task, Event, Project CRUD; filters; date change; etc.).
   - Add or adjust a test that: loads the workspace, triggers load more (e.g. via `$wire.$parent.$island('feed').loadMoreItems()` or the same action), and asserts that more items appear and that the List component did not remount (e.g. same wire:id for the list root if applicable).
   - Optionally add a test that creates/edits/deletes a task from a card and asserts success (already may be covered).

2. **Cleanup**
   - Remove any temporary “test” feed block from Index view.
   - Remove feature flag if added in Phase 0.
   - Remove unused props from List if some were only used for the old card loop.
   - Update this document with “Completed” and any deviations.

3. **Optional: Island append mode (later)**
   - To reduce payload on load more, the island could be switched to **append** mode: island content would render only the **next page** of items (single unified page index and a slice of `feedItems`). That requires a larger change (unified pagination, slice per page). Document as a future phase if desired.

**Deliverables:** All tests green; code clean; refactor plan updated.

---

## 5. File Change Summary (Checklist)

| Area | File(s) | Change |
|------|--------|--------|
| Index | `resources/views/pages/workspace/⚡index/index.php` | Add `feedItems()` computed; no listRefresh in loadMoreItems (Phase 4). |
| Index | `resources/views/pages/workspace/⚡index/index.blade.php` | Add `@island(name: 'feed')` with feed loop and list-item-cards; pass feedIsEmpty/hasMoreItems to List; keep List for chrome + sentinel. |
| List | `resources/views/pages/workspace/⚡list/list.php` | Optional: reduce props (e.g. drop tasks/events/projects/overdue if not needed for List view). |
| List | `resources/views/pages/workspace/⚡list/list.blade.php` | Remove feed @php and @foreach; keep Add, form, loading card, empty state, sentinel; sentinel calls $wire.$parent.$island('feed').loadMoreItems(). |
| Card | `resources/views/components/workspace/list-item-card.blade.php` | Add prop `livewireCallTarget` (default `'parent'`); pass to ViewModel/alpineConfig. |
| Card | `resources/js/alpine/list-item-card.js` | Use $livewireTarget (or callLivewire) everywhere instead of $wire.$parent. |
| ViewModel | `app/ViewModels/ListItemCardViewModel.php` | Accept optional livewireCallTarget and include in alpineConfig(). |
| Subcomponents | list-item-task, list-item-event, list-item-project, comments, activity-logs-popover, collaborators-popover | Replace $wire.$parent.$call with $root.callLivewire(...) (or equivalent) so they use the card’s livewire target. |

---

## 6. Rollback

If a phase causes regressions:

- **Phase 1–2:** Revert the livewire target and `feedItems()` changes; keep List as the only place that renders cards.
- **Phase 3–4:** Revert Index/List view and loadMoreItems so the feed is again in List and listRefresh is again incremented on load more.
- **Phase 5–6:** Revert empty state / visible count and test changes only.

Keeping Phase 0 (tests and checklist) and small, reviewable PRs per phase will make rollback easier.

---

## 7. References

- [Livewire 4 — Islands](https://livewire.laravel.com/docs/4.x/islands)
- [Livewire 4 — Append and prepend modes](https://livewire.laravel.com/docs/4.x/islands#append-and-prepend-modes)
- [Livewire 4 — Triggering islands from JavaScript](https://livewire.laravel.com/docs/4.x/islands#triggering-islands-from-javascript) (`$wire.$island('feed').loadMore()` etc.)
