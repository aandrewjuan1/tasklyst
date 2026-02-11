<?php

namespace App\Livewire\Concerns;

use App\DataTransferObjects\Event\CreateEventDto;
use App\Models\Event;
use App\Models\Tag;
use App\Models\User;
use App\Support\Validation\EventPayloadValidation;
use Carbon\Carbon;
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

        $this->listRefresh++;
        $this->dispatch('event-created', id: $event->id, title: $event->title);
        $this->dispatch('toast', ...Event::toastPayload('create', true, $event->title));
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
            $deleted = $this->deleteEventAction->execute($event);
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

        return true;
    }

    /**
     * Update a single event property for the authenticated user (inline editing).
     *
     * @param  bool  $silentToasts  When true, do not dispatch success toast (e.g. when syncing tagIds after delete so only "Tag deleted." is shown).
     */
    #[Async]
    #[Renderless]
    public function updateEventProperty(int $eventId, string $property, mixed $value, bool $silentToasts = false, ?string $occurrenceDate = null): bool
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

        $event = Event::query()->forUser($user->id)->with('recurringEvent')->find($eventId);

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

        $result = $this->updateEventPropertyAction->execute($event, $property, $validatedValue, $occurrenceDate);

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

        return true;
    }

    /**
     * Get events for the selected date for the authenticated user.
     * Uses batch recurrence expansion to avoid N+1 queries.
     */
    #[Computed]
    public function events(): Collection
    {
        $userId = Auth::id();

        if ($userId === null) {
            return collect();
        }

        $filterItemType = property_exists($this, 'filterItemType') ? $this->normalizeFilterValue($this->filterItemType) : null;
        if ($filterItemType !== null && $filterItemType !== 'events') {
            return collect();
        }

        $date = Carbon::parse($this->selectedDate);

        $eventQuery = Event::query()
            ->with([
                'user',
                'recurringEvent',
                'tags',
                'collaborations',
                'collaborators',
                'collaborationInvitations.invitee',
                'comments.user',
            ])
            ->forUser($userId)
            ->activeForDate($date);

        if (method_exists($this, 'applyEventFilters')) {
            $this->applyEventFilters($eventQuery);
        } else {
            $eventQuery->notCancelled()->notCompleted();
        }

        $events = $eventQuery->orderByDesc('created_at')->limit(50)->get();

        $result = $this->eventService->processRecurringEventsForDate($events, $date);

        if (method_exists($this, 'filterEventCollection')) {
            $result = $this->filterEventCollection($result);
        }

        return $result;
    }
}
