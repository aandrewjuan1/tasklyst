<?php

namespace App\Livewire\Concerns;

use App\Actions\CalendarFeed\ConnectCalendarFeedAction;
use App\Actions\CalendarFeed\DisconnectCalendarFeedAction;
use App\Actions\CalendarFeed\SyncCalendarFeedAction;
use App\DataTransferObjects\CalendarFeed\CreateCalendarFeedDto;
use App\Models\CalendarFeed;
use App\Models\User;
use App\Support\Validation\CalendarFeedPayloadValidation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

trait HandlesCalendarFeeds
{
    /**
     * @var array<string, mixed>
     */
    public array $calendarFeedPayload = [
        'feedUrl' => '',
        'name' => null,
    ];

    public function bootHandlesCalendarFeeds(
        ConnectCalendarFeedAction $connectCalendarFeedAction,
        SyncCalendarFeedAction $syncCalendarFeedAction,
        DisconnectCalendarFeedAction $disconnectCalendarFeedAction
    ): void {
        $this->connectCalendarFeedAction = $connectCalendarFeedAction;
        $this->syncCalendarFeedAction = $syncCalendarFeedAction;
        $this->disconnectCalendarFeedAction = $disconnectCalendarFeedAction;
    }

    public function connectCalendarFeed(array $payload): void
    {
        /** @var User|null $user */
        $user = $this->requireAuth(__('You must be logged in to connect a calendar feed.'));
        if ($user === null) {
            return;
        }

        $this->authorize('create', CalendarFeed::class);

        $this->calendarFeedPayload = array_replace_recursive(
            CalendarFeedPayloadValidation::defaults(),
            $payload
        );

        try {
            /** @var array{calendarFeedPayload: array<string, mixed>} $validated */
            $validated = $this->validate(CalendarFeedPayloadValidation::rules());
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Calendar feed validation failed', [
                'errors' => $e->errors(),
                'payload' => $this->calendarFeedPayload,
            ]);

            $this->dispatch('toast', type: 'error', message: __('Please check the Brightspace calendar URL and try again.'));

            return;
        }

        $payload = $validated['calendarFeedPayload'];

        $dto = CreateCalendarFeedDto::fromValidated($payload);

        try {
            $feed = $this->connectCalendarFeedAction->execute($user, $dto);
        } catch (\Throwable $e) {
            Log::error('Failed to connect calendar feed.', [
                'user_id' => $user->id,
                'payload' => $this->calendarFeedPayload,
                'exception' => $e,
            ]);

            $this->dispatch('toast', type: 'error', message: __('Couldn’t connect the calendar feed. Try again.'));

            return;
        }

        $this->calendarFeedPayload = CalendarFeedPayloadValidation::defaults();

        $this->dispatch('toast', type: 'success', message: __('Connected Brightspace calendar.'));
        $this->dispatch('calendar-feed-connected', id: $feed->id);
    }

    public function syncCalendarFeed(int $feedId): bool
    {
        /** @var User|null $user */
        $user = $this->requireAuth(__('You must be logged in to sync a calendar feed.'));
        if ($user === null) {
            return false;
        }

        $feed = CalendarFeed::query()
            ->where('user_id', $user->id)
            ->find($feedId);

        if ($feed === null) {
            $this->dispatch('toast', type: 'error', message: __('Calendar feed not found.'));

            return false;
        }

        $this->authorize('update', $feed);

        try {
            $this->syncCalendarFeedAction->execute($feed);
        } catch (\Throwable $e) {
            Log::error('Failed to sync calendar feed.', [
                'user_id' => $user->id,
                'feed_id' => $feedId,
                'exception' => $e,
            ]);

            $this->dispatch('toast', type: 'error', message: __('Couldn’t sync the calendar feed. Try again.'));

            return false;
        }

        $this->dispatch('toast', type: 'success', message: __('Synced Brightspace calendar.'));

        return true;
    }

    public function disconnectCalendarFeed(int $feedId): bool
    {
        /** @var User|null $user */
        $user = $this->requireAuth(__('You must be logged in to disconnect a calendar feed.'));
        if ($user === null) {
            return false;
        }

        $feed = CalendarFeed::query()
            ->where('user_id', $user->id)
            ->find($feedId);

        if ($feed === null) {
            $this->dispatch('toast', type: 'error', message: __('Calendar feed not found.'));

            return false;
        }

        $this->authorize('delete', $feed);

        try {
            $deleted = $this->disconnectCalendarFeedAction->execute($feed, $user);
        } catch (\Throwable $e) {
            Log::error('Failed to disconnect calendar feed.', [
                'user_id' => $user->id,
                'feed_id' => $feedId,
                'exception' => $e,
            ]);

            $this->dispatch('toast', type: 'error', message: __('Couldn’t disconnect the calendar feed. Try again.'));

            return false;
        }

        if (! $deleted) {
            $this->dispatch('toast', type: 'error', message: __('Couldn’t disconnect the calendar feed. Try again.'));

            return false;
        }

        $this->dispatch('toast', type: 'success', message: __('Disconnected Brightspace calendar.'));

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function loadCalendarFeeds(): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return [];
        }

        return CalendarFeed::query()
            ->where('user_id', $user->id)
            ->orderByDesc('last_synced_at')
            ->orderByDesc('created_at')
            ->get([
                'id',
                'name',
                'source',
                'sync_enabled',
                'last_synced_at',
                'created_at',
            ])
            ->map(fn (CalendarFeed $feed): array => [
                'id' => $feed->id,
                'name' => $feed->name,
                'source' => $feed->source,
                'sync_enabled' => $feed->sync_enabled,
                'last_synced_at' => $feed->last_synced_at,
                'created_at' => $feed->created_at,
            ])
            ->all();
    }
}
