<?php

namespace App\DataTransferObjects\CalendarFeed;

use App\Enums\CalendarFeedSyncStatus;

final readonly class CalendarFeedSyncResult
{
    public function __construct(
        public CalendarFeedSyncStatus $status,
        public int $itemsApplied = 0,
        public int $eventsInWindow = 0,
        public int $eventsInRawFeed = 0,
        public int $eventsSkippedNoUid = 0,
        public int $tasksCreated = 0,
        public int $tasksUpdated = 0,
        public ?int $httpStatus = null,
    ) {}

    public function toastType(bool $forConnect): string
    {
        if ($this->status === CalendarFeedSyncStatus::Completed) {
            return 'success';
        }

        if ($this->status === CalendarFeedSyncStatus::Queued) {
            return 'info';
        }

        if ($this->status === CalendarFeedSyncStatus::SyncDisabled) {
            return 'warning';
        }

        if ($forConnect && in_array($this->status, [
            CalendarFeedSyncStatus::HttpFailed,
            CalendarFeedSyncStatus::EmptyBody,
            CalendarFeedSyncStatus::Exception,
        ], true)) {
            return 'warning';
        }

        return 'error';
    }

    public function toastMessage(bool $forConnect): string
    {
        return match ($this->status) {
            CalendarFeedSyncStatus::SyncDisabled => __('Sync is turned off for this calendar feed. Turn sync on and try again.'),
            CalendarFeedSyncStatus::Queued => __('Your calendar sync is running in the background. You’ll get an inbox update when it finishes.'),
            CalendarFeedSyncStatus::HttpFailed => $this->messageForHttpFailure($forConnect),
            CalendarFeedSyncStatus::EmptyBody => $this->messageForEmptyBody($forConnect),
            CalendarFeedSyncStatus::Exception => $forConnect
                ? __('Calendar saved, but the first sync failed while reading the feed. Try Sync again.')
                : __('Could not read the calendar feed. Try again in a moment.'),
            CalendarFeedSyncStatus::Completed => $this->messageForCompleted($forConnect),
        };
    }

    private function messageForHttpFailure(bool $forConnect): string
    {
        $code = $this->httpStatus ?? 0;

        if ($forConnect) {
            return __('Calendar saved, but Brightspace did not return the feed (HTTP :code). Try Sync again.', ['code' => $code]);
        }

        return __('Could not load your Brightspace calendar (HTTP :code). Check the link or try again.', ['code' => $code]);
    }

    private function messageForEmptyBody(bool $forConnect): string
    {
        if ($forConnect) {
            return __('Calendar saved, but the feed response was empty. Try Sync again.');
        }

        return __('The calendar feed was empty. Try Sync again or check your Brightspace subscribe URL.');
    }

    private function messageForCompleted(bool $forConnect): string
    {
        if ($forConnect) {
            if ($this->itemsApplied > 0) {
                return trans_choice(
                    '{1} Connected. :count item synced. Reload the page to reflect changes.|[2,*] Connected. :count items synced. Reload the page to reflect changes.',
                    $this->itemsApplied,
                    ['count' => $this->itemsApplied]
                );
            }

            return __('Connected. Sync complete. Reload the page to reflect changes.');
        }

        if ($this->itemsApplied > 0) {
            return trans_choice(
                '{1} Sync complete. :count item. Reload the page to reflect changes.|[2,*] Sync complete. :count items. Reload the page to reflect changes.',
                $this->itemsApplied,
                ['count' => $this->itemsApplied]
            );
        }

        return __('Sync complete. Reload the page to reflect changes.');
    }
}
