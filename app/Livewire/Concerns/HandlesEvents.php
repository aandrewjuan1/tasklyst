<?php

namespace App\Livewire\Concerns;

use App\DataTransferObjects\Event\CreateEventDto;
use App\DataTransferObjects\Event\CreateEventExceptionDto;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\EventException;
use App\Models\RecurringEvent;
use App\Models\Tag;
use App\Models\User;
use App\Support\Validation\EventExceptionPayloadValidation;
use App\Support\Validation\EventPayloadValidation;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Async;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Renderless;

trait HandlesEvents
{
    /**
     * Pagination settings for workspace event list.
     */
    public int $eventsPerPage = 10;

    public int $eventsPage = 1;

    public bool $hasMoreEvents = false;

    /**
     * Create a new event for the authenticated user.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createEvent(array $payload): void
    {
        $user = $this->requireAuth(__('You must be logged in to create events.'));
        if ($user === null) {
            return;
        }

        $this->authorize('create', Event::class);

        $this->eventPayload = array_replace_recursive(EventPayloadValidation::defaults(), $payload);
        $this->eventPayload['tagIds'] = Tag::validIdsForUser($user->id, $this->eventPayload['tagIds'] ?? []);

        try {
            /** @var array{eventPayload: array<string, mixed>} $validated */
            $validated = $this->validate(EventPayloadValidation::rules());
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Event validation failed', [
                'errors' => $e->errors(),
                'payload' => $this->eventPayload,
            ]);
            $this->dispatch('toast', type: 'error', message: __('Please fix the event details and try again.'));

            return;
        }

        $validatedEvent = $validated['eventPayload'];

        if (($validatedEvent['pendingTagNames'] ?? []) !== []) {
            $this->authorize('create', Tag::class);
        }
        $tagIds = $this->tagService->resolveTagIdsFromPayload($user, $validatedEvent, 'event');
        $validatedEvent['tagIds'] = $tagIds;

        $dto = CreateEventDto::fromValidated($validatedEvent);

        try {
            $event = $this->createEventAction->execute($user, $dto);
        } catch (\Throwable $e) {
            Log::error('Failed to create event from workspace.', [
                'user_id' => $user->id,
                'payload' => $this->eventPayload,
                'exception' => $e,
            ]);

            $this->dispatch('toast', ...Event::toastPayload('create', false, $dto->title));

            return;
        }

        $this->dispatch('event-created', id: $event->id, title: $event->title);
        $this->dispatch('toast', ...Event::toastPayload('create', true, $event->title));

        if (method_exists($this, 'refreshWorkspaceItems')) {
            $this->refreshWorkspaceItems();
        }
        if (method_exists($this, 'dispatchWorkspaceVisibilityToastForCreatedItem')) {
            $this->dispatchWorkspaceVisibilityToastForCreatedItem('event', $event);
        }

        if (method_exists($this, 'refreshWorkspaceCalendar')) {
            $this->refreshWorkspaceCalendar();
        }
    }

    /**
     * Delete an event for the authenticated user.
     */
    #[Async]
    #[Renderless]
    public function deleteEvent(int $eventId): bool
    {
        $user = $this->requireAuth(__('You must be logged in to delete events.'));
        if ($user === null) {
            return false;
        }

        $event = Event::query()->forUser($user->id)->find($eventId);

        if ($event === null) {
            $this->dispatch('toast', type: 'error', message: __('Event not found.'));

            return false;
        }

        if ((int) $event->user_id !== (int) $user->id) {
            $this->dispatch('toast', type: 'error', message: __('Only the owner can delete this event.'));

            return false;
        }

        $this->authorize('delete', $event);

        try {
            $deleted = $this->deleteEventAction->execute($event, $user);
        } catch (\Throwable $e) {
            Log::error('Failed to delete event from workspace.', [
                'user_id' => $user->id,
                'event_id' => $eventId,
                'exception' => $e,
            ]);

            $this->dispatch('toast', ...Event::toastPayload('delete', false, $event->title));

            return false;
        }

        if (! $deleted) {
            $this->dispatch('toast', ...Event::toastPayload('delete', false, $event->title));

            return false;
        }

        $this->dispatch('toast', ...Event::toastPayload('delete', true, $event->title));

        if (method_exists($this, 'queueWorkspaceCalendarRefresh')) {
            $this->queueWorkspaceCalendarRefresh();
        }

        return true;
    }

    /**
     * Restore a soft-deleted event for the authenticated user.
     */
    #[Async]
    #[Renderless]
    public function restoreEvent(int $eventId): bool
    {
        $user = $this->requireAuth(__('You must be logged in to restore events.'));
        if ($user === null) {
            return false;
        }

        $event = Event::query()->onlyTrashed()->forUser($user->id)->find($eventId);

        if ($event === null) {
            $this->dispatch('toast', type: 'error', message: __('Event not found.'));

            return false;
        }

        if ((int) $event->user_id !== (int) $user->id) {
            $this->dispatch('toast', type: 'error', message: __('Only the owner can restore this event.'));

            return false;
        }

        $this->authorize('restore', $event);

        try {
            $restored = $this->restoreEventAction->execute($event, $user);
        } catch (\Throwable $e) {
            Log::error('Failed to restore event.', [
                'user_id' => $user->id,
                'event_id' => $eventId,
                'exception' => $e,
            ]);

            $this->dispatch('toast', type: 'error', message: __('Couldn’t restore the event. Try again.'));

            return false;
        }

        if (! $restored) {
            $this->dispatch('toast', type: 'error', message: __('Couldn’t restore the event. Try again.'));

            return false;
        }

        $this->dispatch('toast', type: 'success', message: __('Restored the event.'));

        if (method_exists($this, 'queueWorkspaceCalendarRefresh')) {
            $this->queueWorkspaceCalendarRefresh();
        }

        return true;
    }

    /**
     * Permanently delete an event for the authenticated user.
     */
    #[Async]
    #[Renderless]
    public function forceDeleteEvent(int $eventId): bool
    {
        $user = $this->requireAuth(__('You must be logged in to permanently delete events.'));
        if ($user === null) {
            return false;
        }

        $event = Event::query()->withTrashed()->forUser($user->id)->find($eventId);

        if ($event === null) {
            $this->dispatch('toast', type: 'error', message: __('Event not found.'));

            return false;
        }

        if ((int) $event->user_id !== (int) $user->id) {
            $this->dispatch('toast', type: 'error', message: __('Only the owner can permanently delete this event.'));

            return false;
        }

        $this->authorize('forceDelete', $event);

        try {
            $deleted = $this->forceDeleteEventAction->execute($event, $user);
        } catch (\Throwable $e) {
            Log::error('Failed to permanently delete event.', [
                'user_id' => $user->id,
                'event_id' => $eventId,
                'exception' => $e,
            ]);

            $this->dispatch('toast', type: 'error', message: __('Couldn’t permanently delete the event. Try again.'));

            return false;
        }

        if (! $deleted) {
            $this->dispatch('toast', type: 'error', message: __('Couldn’t permanently delete the event. Try again.'));

            return false;
        }

        $this->dispatch('toast', type: 'success', message: __('Permanently deleted the event.'));

        if (method_exists($this, 'queueWorkspaceCalendarRefresh')) {
            $this->queueWorkspaceCalendarRefresh();
        }

        return true;
    }

    /**
     * Update a single event property for the authenticated user (inline editing).
     *
     * @param  bool  $silentToasts  When true, do not dispatch success toast (e.g. when syncing tagIds after delete so only "Tag deleted." is shown).
     */
    #[Renderless]
    public function updateEventProperty(int $eventId, string $property, mixed $value, bool $silentToasts = false, ?string $occurrenceDate = null): bool|array
    {
        if ($property === 'tagIds') {
            Log::info('[TAG-SYNC] Livewire received updateEventProperty call', [
                'event_id' => $eventId,
                'property' => $property,
                'value' => $value,
                'value_type' => gettype($value),
                'value_is_array' => is_array($value),
                'value_count' => is_array($value) ? count($value) : null,
                'silent_toasts' => $silentToasts,
                'user_id' => Auth::id(),
            ]);
        }

        $user = $this->requireAuth(__('You must be logged in to update events.'));
        if ($user === null) {
            if ($property === 'tagIds') {
                Log::warning('[TAG-SYNC] Event update failed - no authenticated user', [
                    'event_id' => $eventId,
                    'property' => $property,
                ]);
            }

            return false;
        }

        $event = Event::query()->forUser($user->id)->with('recurringEvent')->withRecentActivityLogs(5)->find($eventId);

        if ($event === null) {
            if ($property === 'tagIds') {
                Log::warning('[TAG-SYNC] Event update failed - event not found', [
                    'event_id' => $eventId,
                    'property' => $property,
                    'user_id' => $user->id,
                ]);
            }

            $this->dispatch('toast', type: 'error', message: __('Event not found.'));

            return false;
        }

        $this->authorize('update', $event);

        // Only the owner can change date/recurrence/tag fields, even if collaborators can edit other properties.
        $isOwner = (int) $event->user_id === (int) $user->id;
        if (! $isOwner && in_array($property, ['startDatetime', 'endDatetime'], true)) {
            $this->dispatch('toast', type: 'error', message: __('Only the owner can change dates for this event.'));

            return false;
        }

        if (! $isOwner && $property === 'recurrence') {
            $this->dispatch('toast', type: 'error', message: __('Only the owner can change repeat for this event.'));

            return false;
        }

        if (! $isOwner && $property === 'tagIds') {
            $this->dispatch('toast', type: 'error', message: __('Only the owner can change tags for this event.'));

            return false;
        }

        if (! in_array($property, EventPayloadValidation::allowedUpdateProperties(), true)) {
            if ($property === 'tagIds') {
                Log::warning('[TAG-SYNC] Event update failed - invalid property', [
                    'event_id' => $eventId,
                    'property' => $property,
                ]);
            }

            $this->dispatch('toast', type: 'error', message: __('Invalid property for update.'));

            return false;
        }

        $rules = EventPayloadValidation::rulesForProperty($property);
        if ($rules === []) {
            if ($property === 'tagIds') {
                Log::warning('[TAG-SYNC] Event update failed - no rules for property', [
                    'event_id' => $eventId,
                    'property' => $property,
                ]);
            }

            $this->dispatch('toast', type: 'error', message: __('Invalid property for update.'));

            return false;
        }

        // Explicit validation for title property - reject empty/whitespace-only values before validator
        if ($property === 'title') {
            $trimmedValue = is_string($value) ? trim($value) : $value;
            if (empty($trimmedValue)) {
                $this->dispatch('toast', type: 'error', message: __('Title cannot be empty.'));

                return false;
            }
            $value = $trimmedValue;
        }

        if ($property === 'tagIds') {
            Log::info('[TAG-SYNC] About to validate tagIds', [
                'event_id' => $eventId,
                'value' => $value,
                'rules' => $rules,
            ]);
        }

        $validator = Validator::make(['value' => $value], $rules);
        if ($validator->fails()) {
            if ($property === 'tagIds') {
                Log::error('[TAG-SYNC] Event tagIds validation failed', [
                    'event_id' => $eventId,
                    'value' => $value,
                    'errors' => $validator->errors()->all(),
                ]);
            }

            $this->dispatch('toast', type: 'error', message: $validator->errors()->first('value') ?: __('Invalid value.'));

            return false;
        }

        $validatedValue = $validator->validated()['value'];

        if ($property === 'tagIds') {
            Log::info('[TAG-SYNC] Validation passed, calling action', [
                'event_id' => $eventId,
                'validated_value' => $validatedValue,
            ]);
        }

        $result = $this->updateEventPropertyAction->execute($event, $property, $validatedValue, $occurrenceDate, $user);

        if ($property === 'tagIds') {
            Log::info('[TAG-SYNC] Action execution completed', [
                'event_id' => $eventId,
                'success' => $result->success,
                'old_value' => $result->oldValue,
                'new_value' => $result->newValue,
                'error_message' => $result->errorMessage,
            ]);
        }

        if (! $result->success) {
            if ($property === 'tagIds') {
                Log::error('[TAG-SYNC] Event tag update failed', [
                    'event_id' => $eventId,
                    'old_value' => $result->oldValue,
                    'new_value' => $result->newValue,
                    'error_message' => $result->errorMessage,
                ]);
            }

            if ($result->errorMessage !== null) {
                $this->dispatch('toast', type: 'error', message: $result->errorMessage);
            } else {
                $this->dispatch('toast', ...Event::toastPayloadForPropertyUpdate($property, $result->oldValue, $result->newValue, false, $event->title));
            }

            return false;
        }

        if ($property === 'tagIds') {
            Log::info('[TAG-SYNC] Event tag update succeeded, dispatching toast', [
                'event_id' => $eventId,
                'silent_toasts' => $silentToasts,
            ]);
        }

        if (! $silentToasts) {
            $this->dispatch('toast', ...Event::toastPayloadForPropertyUpdate(
                $property,
                $result->oldValue,
                $result->newValue,
                true,
                $event->title,
                $result->addedTagName,
                $result->removedTagName
            ));
        }

        $this->maybeQueueWorkspaceCalendarRefreshAfterEventPropertyUpdate($property);

        if ($property === 'recurrence') {
            $event->load('recurringEvent');

            return ['success' => true, 'recurringEventId' => $event->recurringEvent?->id];
        }

        return true;
    }

    /**
     * Get events for the selected date for the authenticated user.
     * Uses batch recurrence expansion to avoid N+1 queries.
     * When "search all items" is active, returns events across all dates (no date scope).
     */
    #[Computed]
    public function events(): Collection
    {
        if (method_exists($this, 'isOverdueStateFilterActive') && $this->isOverdueStateFilterActive()) {
            return collect();
        }

        // Early return: Skip if filtered to other item types (before any work)
        $filterItemType = property_exists($this, 'filterItemType') ? $this->normalizeFilterValue($this->filterItemType) : null;
        if ($filterItemType !== null && $filterItemType !== 'events') {
            return collect();
        }

        $userId = Auth::id();

        if ($userId === null) {
            return collect();
        }

        $eventsPerPage = property_exists($this, 'eventsPerPage') ? (int) $this->eventsPerPage : 10;
        $eventsPage = property_exists($this, 'eventsPage') ? max(1, (int) $this->eventsPage) : 1;
        $visibleLimit = $eventsPerPage * $eventsPage;
        $queryLimit = $visibleLimit + 1;

        $searchAllItems = method_exists($this, 'shouldSearchAllItems')
            && $this->shouldSearchAllItems()
            && (! method_exists($this, 'isDueStateFilterActive') || ! $this->isDueStateFilterActive());

        $eventQuery = Event::query()
            ->with([
                'tasks',
                'user',
                'recurringEvent',
                'tags',
                'collaborations',
                'collaborators',
                'collaborationInvitations.invitee',
            ])
            ->withCount('comments')
            ->withCount('tasks')
            ->withCount('activityLogs')
            ->forUser($userId);

        if (! $searchAllItems) {
            $date = method_exists($this, 'getParsedSelectedDate')
                ? $this->getParsedSelectedDate()
                : Carbon::parse($this->selectedDate);
            $eventQuery->activeForDate($date);

            $isDueStateFilterActive = method_exists($this, 'isDueStateFilterActive') && $this->isDueStateFilterActive();
            if ($date->isToday() && ! $isDueStateFilterActive) {
                $eventQuery->where(function (Builder $q): void {
                    $q->whereHas('recurringEvent')
                        ->orWhere(function (Builder $nonRecurring): void {
                            $nonRecurring->whereNull('end_datetime')
                                ->orWhere('end_datetime', '>=', now());
                        });
                });
            }
        }

        if (method_exists($this, 'isDueStateFilterActive') && $this->isDueStateFilterActive()) {
            $selectedDate = method_exists($this, 'getParsedSelectedDate')
                ? $this->getParsedSelectedDate()
                : Carbon::parse($this->selectedDate);
            $eventQuery->whereNotNull('end_datetime')
                ->whereDate('end_datetime', $selectedDate->toDateString());
        }

        if (method_exists($this, 'applyEventFilters')) {
            $this->applyEventFilters($eventQuery);
        } else {
            $eventQuery->notCancelled();
        }

        if (method_exists($this, 'applyWorkspaceSearchToEventQuery')) {
            $this->applyWorkspaceSearchToEventQuery($eventQuery);
        }

        $events = $eventQuery
            ->orderByRaw(
                'CASE
                    WHEN COALESCE(start_datetime, end_datetime) IS NULL THEN 1
                    ELSE 0
                END'
            )
            ->orderByRaw('COALESCE(start_datetime, end_datetime) ASC')
            ->orderByDesc('id')
            ->limit($queryLimit)
            ->get();

        $this->hasMoreEvents = $events->count() > $visibleLimit;
        $visibleEvents = $events->take($visibleLimit);

        if ($searchAllItems) {
            $result = $visibleEvents;
        } else {
            $date = method_exists($this, 'getParsedSelectedDate')
                ? $this->getParsedSelectedDate()
                : Carbon::parse($this->selectedDate);
            $result = $this->eventService->processRecurringEventsForDate($visibleEvents, $date);
        }

        if (method_exists($this, 'filterEventCollection')) {
            $result = $this->filterEventCollection($result);
        }

        return $result->filter(function (Event $event): bool {
            $effective = $event->effectiveStatusForDate ?? $event->status;

            return $effective !== EventStatus::Completed && $effective !== EventStatus::Cancelled;
        })->values();
    }

    /**
     * Completed/cancelled events for the current workspace scope.
     *
     * @return Collection<int, Event>
     */
    #[Computed]
    public function completedEvents(): Collection
    {
        if (! method_exists($this, 'shouldShowCompleted') || ! $this->shouldShowCompleted()) {
            return collect();
        }
        if (method_exists($this, 'isOverdueStateFilterActive') && $this->isOverdueStateFilterActive()) {
            return collect();
        }
        $filterItemType = property_exists($this, 'filterItemType') ? $this->normalizeFilterValue($this->filterItemType) : null;
        if ($filterItemType !== null && $filterItemType !== 'events') {
            return collect();
        }

        $userId = Auth::id();
        if ($userId === null) {
            return collect();
        }

        $visibleLimit = (property_exists($this, 'eventsPerPage') ? (int) $this->eventsPerPage : 10)
            * (property_exists($this, 'eventsPage') ? max(1, (int) $this->eventsPage) : 1);
        $queryLimit = $visibleLimit + 1;
        $searchAllItems = method_exists($this, 'shouldSearchAllItems')
            && $this->shouldSearchAllItems()
            && (! method_exists($this, 'isDueStateFilterActive') || ! $this->isDueStateFilterActive());

        $eventQuery = Event::query()
            ->with([
                'tasks',
                'user',
                'recurringEvent',
                'tags',
                'collaborations',
                'collaborators',
                'collaborationInvitations.invitee',
            ])
            ->withCount('comments')
            ->withCount('tasks')
            ->withCount('activityLogs')
            ->forUser($userId);

        if (! $searchAllItems) {
            $date = method_exists($this, 'getParsedSelectedDate')
                ? $this->getParsedSelectedDate()
                : Carbon::parse($this->selectedDate);
            $eventQuery->activeForDate($date);
        }

        if (method_exists($this, 'isDueStateFilterActive') && $this->isDueStateFilterActive()) {
            $selectedDate = method_exists($this, 'getParsedSelectedDate')
                ? $this->getParsedSelectedDate()
                : Carbon::parse($this->selectedDate);
            $eventQuery->whereNotNull('end_datetime')
                ->whereDate('end_datetime', $selectedDate->toDateString());
        }

        if (method_exists($this, 'applyEventFilters')) {
            $this->applyEventFilters($eventQuery);
        }
        if (method_exists($this, 'applyWorkspaceSearchToEventQuery')) {
            $this->applyWorkspaceSearchToEventQuery($eventQuery);
        }

        $events = $eventQuery
            ->orderByRaw('COALESCE(start_datetime, end_datetime) DESC')
            ->orderByDesc('id')
            ->limit($queryLimit)
            ->get()
            ->take($visibleLimit);

        $result = $searchAllItems
            ? $events
            : $this->eventService->processRecurringEventsForDate(
                $events,
                method_exists($this, 'getParsedSelectedDate') ? $this->getParsedSelectedDate() : Carbon::parse($this->selectedDate)
            );

        if (method_exists($this, 'filterEventCollection')) {
            $result = $this->filterEventCollection($result);
        }

        return $result->filter(function (Event $event): bool {
            $effective = $event->effectiveStatusForDate ?? $event->status;

            return $effective === EventStatus::Completed || $effective === EventStatus::Cancelled;
        })->values();
    }

    /**
     * Load events for parent selection (e.g. "Put task in event" popover).
     * No date filter; returns all non-cancelled events for the user.
     *
     * @return array{items: array<int, array{id: int, title: string}>, hasMore: bool}
     */
    public function loadEventsForParentSelection(?int $cursorId = null, int $limit = 50): array
    {
        $userId = Auth::id();
        if ($userId === null) {
            return ['items' => [], 'hasMore' => false];
        }

        $query = Event::query()
            ->forUser($userId)
            ->notCancelled()
            ->orderBy('title')
            ->limit($limit + 1);

        if ($cursorId !== null) {
            $query->where('id', '>', $cursorId);
        }

        $events = $query->get(['id', 'title']);
        $hasMore = $events->count() > $limit;
        $items = $events->take($limit)->map(fn (Event $e) => ['id' => $e->id, 'title' => $e->title])->values()->all();

        return ['items' => $items, 'hasMore' => $hasMore];
    }

    /**
     * Skip a recurring event occurrence (create an event exception for the given date).
     * Returns the exception id on success, null on validation/authorization/failure.
     *
     * @param  array<string, mixed>  $payload  Must contain eventExceptionPayload with recurringEventId, exceptionDate, optional isDeleted, reason, replacementInstanceId
     */
    #[Async]
    #[Renderless]
    public function skipRecurringEventOccurrence(array $payload): ?int
    {
        $user = $this->requireAuth(__('You must be logged in to skip an occurrence.'));
        if ($user === null) {
            return null;
        }

        $payload = array_replace_recursive(EventExceptionPayloadValidation::createDefaults(), $payload);
        $validator = Validator::make(['eventExceptionPayload' => $payload], EventExceptionPayloadValidation::createRules());
        if ($validator->fails()) {
            $this->dispatch('toast', type: 'error', message: $validator->errors()->first() ?: __('Invalid request.'));

            return null;
        }

        $validated = $validator->validated()['eventExceptionPayload'];
        $recurring = RecurringEvent::query()->with('event')->find((int) $validated['recurringEventId']);
        if ($recurring === null || $recurring->event === null) {
            $this->dispatch('toast', type: 'error', message: __('Event not found.'));

            return null;
        }

        $event = Event::query()->forUser($user->id)->find($recurring->event->id);
        if ($event === null) {
            $this->dispatch('toast', type: 'error', message: __('Event not found.'));

            return null;
        }

        $this->authorize('update', $event);

        $dto = CreateEventExceptionDto::fromValidated($validated);

        try {
            $exception = $this->createEventExceptionAction->execute($user, $dto);
        } catch (\Throwable $e) {
            Log::error('Failed to skip recurring event occurrence.', [
                'user_id' => $user->id,
                'recurring_event_id' => $recurring->id,
                'exception' => $e,
            ]);
            $this->dispatch('toast', type: 'error', message: __('Could not skip occurrence. Please try again.'));

            return null;
        }

        $this->dispatch('recurring-event-occurrence-skipped', eventExceptionId: $exception->id, eventId: $event->id);
        $this->dispatch('toast', type: 'success', message: __('Occurrence skipped.'));

        if (method_exists($this, 'queueWorkspaceCalendarRefresh')) {
            $this->queueWorkspaceCalendarRefresh();
        }

        return $exception->id;
    }

    /**
     * Restore a recurring event occurrence (delete the event exception so the occurrence appears again).
     */
    #[Async]
    #[Renderless]
    public function restoreRecurringEventOccurrence(int $eventExceptionId): bool
    {
        $user = $this->requireAuth(__('You must be logged in to restore an occurrence.'));
        if ($user === null) {
            return false;
        }

        $exception = EventException::query()->with('recurringEvent.event')->find($eventExceptionId);
        if ($exception === null) {
            $this->dispatch('toast', type: 'error', message: __('Exception not found.'));

            return false;
        }

        $this->authorize('delete', $exception);

        try {
            $deleted = $this->deleteEventExceptionAction->execute($exception);
        } catch (\Throwable $e) {
            Log::error('Failed to restore recurring event occurrence.', [
                'user_id' => $user->id,
                'event_exception_id' => $eventExceptionId,
                'exception' => $e,
            ]);
            $this->dispatch('toast', type: 'error', message: __('Could not restore occurrence. Please try again.'));

            return false;
        }

        if (! $deleted) {
            $this->dispatch('toast', type: 'error', message: __('Could not restore occurrence. Please try again.'));

            return false;
        }

        $eventId = $exception->recurringEvent?->event_id;
        $this->dispatch('recurring-event-occurrence-restored', eventExceptionId: $eventExceptionId, eventId: $eventId);
        $this->dispatch('toast', type: 'success', message: __('Occurrence restored.'));

        if (method_exists($this, 'queueWorkspaceCalendarRefresh')) {
            $this->queueWorkspaceCalendarRefresh();
        }

        return true;
    }

    /**
     * Get event exceptions (skipped dates) for a recurring event. For use in "Skipped dates" / Restore UI.
     *
     * @return array<int, array{id: int, exception_date: string, reason: string|null}>
     */
    public function getEventExceptions(int $recurringEventId): array
    {
        $user = $this->requireAuth(__('You must be logged in to view exceptions.'));
        if ($user === null) {
            return [];
        }

        $recurring = RecurringEvent::query()->with('event')->find($recurringEventId);
        if ($recurring === null || $recurring->event === null) {
            return [];
        }

        $event = Event::query()->forUser($user->id)->find($recurring->event->id);
        if ($event === null) {
            return [];
        }

        $this->authorize('update', $event);

        return $recurring->eventExceptions()
            ->orderBy('exception_date', 'desc')
            ->get()
            ->map(fn (EventException $ex) => [
                'id' => $ex->id,
                'exception_date' => $ex->exception_date->format('Y-m-d'),
                'reason' => $ex->reason,
            ])
            ->values()
            ->all();
    }

    protected function maybeQueueWorkspaceCalendarRefreshAfterEventPropertyUpdate(string $property): void
    {
        if (! method_exists($this, 'queueWorkspaceCalendarRefresh')) {
            return;
        }

        if (! in_array($property, ['startDatetime', 'endDatetime', 'allDay', 'status', 'recurrence'], true)) {
            return;
        }

        $this->queueWorkspaceCalendarRefresh();
    }
}
