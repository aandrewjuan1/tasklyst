<?php

namespace App\Enums;

enum CalendarFeedSyncStatus: string
{
    case SyncDisabled = 'sync_disabled';
    case Queued = 'queued';
    case HttpFailed = 'http_failed';
    case EmptyBody = 'empty_body';
    case Exception = 'exception';
    case Completed = 'completed';

    public function isCompleted(): bool
    {
        return $this === self::Completed;
    }
}
