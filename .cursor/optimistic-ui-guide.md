# Optimistic UI Implementation Guide: Alpine.js + Livewire 4

## TL;DR

**What it is:** Update the UI instantly before the server responds, creating SPA-like perceived performance.

**The Pattern:**
1. **Snapshot** current state (for rollback)
2. **Update UI immediately** (optimistic)
3. **Call server** asynchronously (`$wire.call()`)
4. **Handle response** - keep changes on success
5. **Rollback on error** - restore from snapshot

**Quick Setup:**
```blade
<div wire:ignore x-data="{ items: @js($this->items) }">
  <button @click="deleteItem(item)">Delete</button>
</div>

<script>
function deleteItem(item) {
  const snapshot = { ...item }
  try {
    this.items = this.items.filter(i => i.id !== item.id)  // Update instantly
    await $wire.call('deleteItem', item.id)  // Call server
  } catch (error) {
    this.items.push(snapshot)  // Rollback on error
  }
}
</script>
```

**When to use:** ✅ High-frequency actions (toggle, edit, delete with undo) | ❌ Critical operations (payments, account deletion)

**Key Rule:** Always create a snapshot BEFORE making any changes.

---

## Core Architecture Rules

### Rule 1: Use `wire:ignore` for Alpine Control
```blade
<div wire:ignore x-data="componentName()">
  <!-- Alpine controls all interactions here -->
</div>
```
**Why:** Prevents Livewire from re-rendering. Alpine has full control over DOM updates.

### Rule 2: Initialize State in Alpine
```blade
<div wire:ignore x-data="{
  items: @js($this->items),  // Use @js() to pass PHP to JS
  editingId: null,
  error: null
}">
```
**Key Points:**
- Use `@js()` to safely pass PHP variables to JavaScript
- State lives on client - no server round-trip for UI changes
- All property changes are instant

### Rule 3: Use Alpine Events, Not Livewire Directives
```blade
<!-- ❌ WRONG: wire:click waits for server -->
<button wire:click="deleteTask({{ $task->id }})">Delete</button>

<!-- ✅ CORRECT: @click with Alpine method (instant) -->
<button @click="deleteTask(task)">Delete</button>
```

---

## The 5-Phase Pattern (REQUIRED FOR ALL ACTIONS)

Every optimistic UI action MUST follow this pattern:

```javascript
async actionName(item) {
  // PHASE 1: Create snapshot BEFORE any changes
  const snapshot = { ...item }
  const itemsBackup = [...this.items]  // If modifying array

  try {
    // PHASE 2: Update UI immediately (optimistic)
    this.items = this.items.filter(i => i.id !== item.id)
    this.error = null

    // PHASE 3: Call server asynchronously (don't await yet)
    const promise = $wire.call('actionName', item.id)

    // PHASE 4: Handle response AFTER UI is updated
    await promise
    // Success - optimistic changes stay

  } catch (error) {
    // PHASE 5: Rollback on error - restore from snapshot
    this.items = itemsBackup
    this.error = `Failed: ${error.message}`
  }
}
```

### Phase Breakdown

**Phase 1: Snapshot** - Store copy of original data before any changes
**Phase 2: Optimistic Update** - Modify Alpine state without awaiting server
**Phase 3: Call Server** - Use `const promise = $wire.call()` (don't await immediately)
**Phase 4: Handle Response** - `await promise` after UI is already updated
**Phase 5: Error Rollback** - Restore from snapshot if server fails

---

## Implementation Patterns

### Pattern 1: CREATE (with Temporary ID)

```javascript
async createItem() {
  if (!this.newItem.title.trim()) {
    this.error = 'Title is required'
    return
  }

  // Create temporary ID
  const tempId = `temp-${Date.now()}`
  const tempItem = {
    id: tempId,
    title: this.newItem.title,
    description: this.newItem.description,
    created_at: new Date().toISOString().split('T')[0],
  }

  // Snapshot for rollback
  const itemsBackup = [...this.items]
  const formBackup = { ...this.newItem }

  try {
    // Optimistic update - add immediately
    this.items = [...this.items, tempItem]
    this.newItem = { title: '', description: '' }
    this.error = null

    // Call server
    const promise = $wire.call('createItem', formBackup.title, formBackup.description)

    // Handle response
    const response = await promise

    // Replace temp item with server response (real ID)
    this.items = this.items.map(item =>
      item.id === tempId ? response : item
    )

  } catch (error) {
    // Rollback
    this.items = itemsBackup
    this.newItem = formBackup
    this.error = `Failed to create: ${error.message}`
  }
}
```

**Livewire Method:**
```php
public function createItem($title, $description)
{
    $validated = $this->validate([
        'title' => 'required|string|max:255',
        'description' => 'nullable|string|max:1000',
    ]);

    try {
        $item = auth()->user()->items()->create($validated);

        return [
            'id' => $item->id,
            'title' => $item->title,
            'description' => $item->description,
            'created_at' => $item->created_at->toDateString(),
        ];
    } catch (\Exception $e) {
        throw new \Exception('Failed to create item');
    }
}
```

### Pattern 2: UPDATE

```javascript
startEdit(item) {
  // Create snapshot when editing starts
  this.editSnapshot = { ...item }
  this.editingId = item.id
}

async saveEdit(item) {
  if (!item.title.trim()) {
    this.error = 'Title is required'
    return
  }

  // Snapshot before changes
  const snapshot = { ...this.editSnapshot }

  try {
    // Data already changed via x-model (optimistic)
    this.error = null

    // Call server
    const promise = $wire.call('updateItem', item.id, item.title, item.description)

    // Handle response
    const response = await promise

    // Update with server response
    Object.assign(item, response)
    this.editingId = null
    this.editSnapshot = null

  } catch (error) {
    // Rollback to snapshot
    Object.assign(item, snapshot)
    this.error = `Failed to update: ${error.message}`
  }
}
```

**Livewire Method:**
```php
public function updateItem($itemId, $title, $description)
{
    $item = auth()->user()->items()->findOrFail($itemId);

    $validated = $this->validate([
        'title' => 'required|string|max:255',
        'description' => 'nullable|string|max:1000',
    ]);

    try {
        $item->update($validated);

        return [
            'id' => $item->id,
            'title' => $item->title,
            'description' => $item->description,
            'updated_at' => $item->updated_at->toDateString(),
        ];
    } catch (\Exception $e) {
        throw new \Exception('Failed to update item');
    }
}
```

### Pattern 3: DELETE

```javascript
async deleteItem(item) {
  // Snapshot for rollback
  const snapshot = { ...item }
  const itemIndex = this.items.indexOf(item)

  try {
    // Optimistic update - remove immediately
    this.items = this.items.filter(i => i.id !== item.id)
    this.error = null

    // Call server asynchronously
    const promise = $wire.call('deleteItem', item.id)

    // Handle response
    await promise
    // Success - item stays removed

  } catch (error) {
    // Rollback - restore item to list
    this.items.splice(itemIndex, 0, snapshot)
    this.error = `Failed to delete: ${error.message}`
  }
}
```

**Livewire Method:**
```php
public function deleteItem($itemId)
{
    $item = auth()->user()->items()->findOrFail($itemId);

    try {
        $item->delete();
        return ['success' => true];
    } catch (\Exception $e) {
        throw new \Exception('Failed to delete item');
    }
}
```

### Pattern 4: TOGGLE (Checkbox, Switch, etc.)

```javascript
async toggleItem(item) {
  // Snapshot current state
  const snapshot = item.completed

  try {
    // Optimistic update already happened (checkbox binding)
    this.error = null

    // Call server
    const promise = $wire.call('toggleItem', item.id, item.completed)

    // Handle response
    const response = await promise
    item.completed = response.completed

  } catch (error) {
    // Rollback to previous state
    item.completed = snapshot
    this.error = `Failed to update: ${error.message}`
  }
}
```

**Livewire Method:**
```php
public function toggleItem($itemId, $completed)
{
    $item = auth()->user()->items()->findOrFail($itemId);

    try {
        $item->update(['completed' => $completed]);
        return ['completed' => $item->completed];
    } catch (\Exception $e) {
        throw new \Exception('Failed to update item');
    }
}
```

---

## Livewire 4 Component Structure

```blade
<?php

use Livewire\Component;
use App\Models\Item;

new class extends Component
{
    public $items = [];

    public function mount()
    {
        $this->items = auth()->user()->items()
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }

    public function createItem($title, $description = '')
    {
        $validated = $this->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        try {
            $item = auth()->user()->items()->create($validated);

            return [
                'id' => $item->id,
                'title' => $item->title,
                'description' => $item->description,
                'created_at' => $item->created_at->toDateString(),
            ];
        } catch (\Exception $e) {
            throw new \Exception('Failed to create item');
        }
    }

    public function updateItem($itemId, $title, $description = '')
    {
        $item = auth()->user()->items()->findOrFail($itemId);

        $validated = $this->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        try {
            $item->update($validated);

            return [
                'id' => $item->id,
                'title' => $item->title,
                'description' => $item->description,
                'updated_at' => $item->updated_at->toDateString(),
            ];
        } catch (\Exception $e) {
            throw new \Exception('Failed to update item');
        }
    }

    public function deleteItem($itemId)
    {
        $item = auth()->user()->items()->findOrFail($itemId);

        try {
            $item->delete();
            return ['success' => true];
        } catch (\Exception $e) {
            throw new \Exception('Failed to delete item');
        }
    }

    public function render()
    {
        return view('livewire.item-manager');
    }
};
?>

<div wire:ignore x-data="itemManager()">
  <!-- Component template -->
</div>

<script>
function itemManager() {
  return {
    items: @js($this->items),
    // ... implementation patterns above
  }
}
</script>
```

---

## Error Handling Rules

### Rule 1: Always Snapshot Before Changes
```javascript
async action() {
  const snapshot = { ...this.data }  // ALWAYS snapshot first

  try {
    this.data.name = 'New Value'
    await $wire.call('action', this.data)
  } catch (error) {
    this.data = snapshot  // Restore on error
    this.error = error.message
  }
}
```

### Rule 2: Handle Different Error Types
```javascript
catch (error) {
  this.item = snapshot

  if (error.status === 422) {
    this.error = 'Validation error: ' + error.data.message
  } else if (error.status === 403) {
    this.error = 'Permission denied'
  } else if (error.status === 404) {
    this.items = this.items.filter(i => i.id !== this.item.id)
    this.error = 'Item no longer exists'
  } else {
    this.error = 'Something went wrong. Please try again.'
  }
}
```

### Rule 3: Show Loading State (Optional)
```javascript
async action() {
  this.loading = true
  const snapshot = { ...this.state }

  try {
    this.state = newState
    await $wire.call('action', params)
  } catch (error) {
    this.state = snapshot
    this.error = error.message
  } finally {
    this.loading = false
  }
}
```

---

## Throttle and Debounce

Prevent rapid-fire actions:

```blade
<!-- Throttle: Wait 250ms between actions -->
<button @click.throttle.250ms="deleteItem()">Delete</button>

<!-- Debounce: Wait for user to stop typing -->
<input @input.debounce.300ms="searchItems()" placeholder="Search...">

<!-- Disable during loading -->
<button
  @click.throttle.250ms="deleteItem()"
  :disabled="loading"
  class="disabled:opacity-50"
>
  Delete
</button>
```

---

## Decision Matrix: When to Use Optimistic UI

| Situation | Use Optimistic UI? | Reason |
|-----------|-------------------|--------|
| Mark complete/incomplete | ✅ YES | High frequency, low consequence |
| Archive/unarchive | ✅ YES | User expects instant feedback |
| Delete with undo | ✅ YES | Can easily restore |
| Toggle favorite/starred | ✅ YES | Non-destructive state change |
| Edit title/content | ✅ YES | User sees edits immediately |
| Change password | ❌ NO | High consequence, needs confirmation |
| Payment/billing | ❌ NO | Requires strict server confirmation |
| Create item (needs server ID) | ✅ YES | Use temporary ID pattern |
| Bulk operations | ⚠️ MAYBE | Use throttle to prevent accidents |

---

## Common Pitfalls (CRITICAL TO AVOID)

### ❌ WRONG: Awaiting Server Before UI Update
```javascript
async deleteTask(task) {
  await $wire.call('deleteTask', task.id)  // Waiting defeats purpose!
  this.tasks = this.tasks.filter(t => t.id !== task.id)
}
```

### ✅ CORRECT: Update UI First
```javascript
async deleteTask(task) {
  const snapshot = { ...task }
  try {
    this.tasks = this.tasks.filter(t => t.id !== task.id)  // Update first
    await $wire.call('deleteTask', task.id)  // Then call server
  } catch (error) {
    this.tasks = [snapshot, ...this.tasks]  // Rollback
  }
}
```

### ❌ WRONG: No Snapshot = No Rollback
```javascript
async updateTask(task) {
  task.name = 'Updated'  // Can't restore if error!
  await $wire.call('updateTask', task.id, task.name)
}
```

### ✅ CORRECT: Always Snapshot First
```javascript
async updateTask(task) {
  const snapshot = task.name  // Snapshot BEFORE changes
  try {
    task.name = 'Updated'
    await $wire.call('updateTask', task.id, task.name)
  } catch (error) {
    task.name = snapshot  // Restore from snapshot
  }
}
```

### ❌ WRONG: No Error Handling
```javascript
async deleteTask(task) {
  this.tasks = this.tasks.filter(t => t.id !== task.id)
  $wire.call('deleteTask', task.id)  // Error silently fails!
}
```

### ✅ CORRECT: Always Use Try-Catch
```javascript
async deleteTask(task) {
  const snapshot = { ...task }
  try {
    this.tasks = this.tasks.filter(t => t.id !== task.id)
    await $wire.call('deleteTask', task.id)
  } catch (error) {
    this.tasks = [snapshot, ...this.tasks]
    this.error = error.message
  }
}
```

### ❌ WRONG: Creating Items Without Temporary ID
```javascript
async createTask(title) {
  const task = { title: title }  // No ID!
  this.tasks = [...this.tasks, task]
  await $wire.call('createTask', title)
}
```

### ✅ CORRECT: Use Temporary ID
```javascript
async createTask(title) {
  const tempId = `temp-${Date.now()}`
  const task = { id: tempId, title: title }
  const backup = [...this.tasks]

  try {
    this.tasks = [...this.tasks, task]
    const response = await $wire.call('createTask', title)
    this.tasks = this.tasks.map(t => t.id === tempId ? response : t)
  } catch (error) {
    this.tasks = backup
  }
}
```

---

## Implementation Checklist

**Setup:**
- [ ] Wrap component with `wire:ignore`
- [ ] Move state from Livewire to Alpine (`x-data`)
- [ ] Use `@js()` to pass initial data from PHP to JavaScript

**For Each Action:**
- [ ] Create snapshot BEFORE any state changes
- [ ] Update Alpine state immediately (optimistic)
- [ ] Call `$wire.call()` WITHOUT awaiting initially
- [ ] Use try-catch block for error handling
- [ ] Restore from snapshot on error
- [ ] `await` promise AFTER UI updates
- [ ] Update UI with server response if needed
- [ ] Show error message to user on failure

**Testing:**
- [ ] Verify UI updates instantly before server response
- [ ] Test error handling by simulating server failures
- [ ] Verify rollback restores exact original state
- [ ] Test with form validation failures

**Polish:**
- [ ] Add loading indicators if needed
- [ ] Use `.throttle` for destructive actions
- [ ] Clear error messages after successful action
- [ ] Provide user feedback (success/error messages)

---

## Key Rules Summary

1. **Always snapshot BEFORE making changes** - Required for rollback
2. **Update UI immediately** - Don't wait for server
3. **Call server asynchronously** - Use `const promise = $wire.call()`
4. **Handle response after UI update** - `await promise` after UI changes
5. **Rollback on error** - Restore from snapshot if server fails
6. **Use temporary IDs for CREATE** - Replace with server ID on success
7. **Use `wire:ignore`** - Prevent Livewire from re-rendering Alpine sections
8. **Use Alpine events** - `@click` not `wire:click`, `@submit` not `wire:submit`
9. **Always use try-catch** - Never let errors fail silently
10. **Show user feedback** - Display error messages on failure
