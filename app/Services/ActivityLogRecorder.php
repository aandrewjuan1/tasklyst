<?php

namespace App\Services;

use App\Enums\ActivityLogAction;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ActivityLogRecorder
{
    public function __construct(
        private CollaboratorActivityNotificationDispatcher $collaboratorActivityNotificationDispatcher,
    ) {}

    /**
     * Record an activity log entry for an item (Task, Project, Event).
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(Model $loggable, ?User $actor, ActivityLogAction $action, array $payload = []): ActivityLog
    {
        $log = ActivityLog::query()->create([
            'loggable_type' => $loggable->getMorphClass(),
            'loggable_id' => $loggable->getKey(),
            'user_id' => $actor?->id,
            'action' => $action,
            'payload' => $payload !== [] ? $payload : null,
        ]);

        if ($actor !== null) {
            $log->setRelation('user', $actor);
        }
        $log->setRelation('loggable', $loggable);

        $this->collaboratorActivityNotificationDispatcher->dispatchForActivityLog($log);

        return $log;
    }
}
