<?php

namespace App\Notifications;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ProjectDeadlineRiskNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $projectId,
        public readonly string $projectName,
        public readonly ?string $projectEndAt,
        public readonly int $openTasksCount,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'project_deadline_risk',
            'title' => __('Project deadline risk'),
            'message' => __(':name has :count open tasks near deadline.', [
                'name' => $this->projectName !== '' ? $this->projectName : __('Project'),
                'count' => $this->openTasksCount,
            ]),
            'entity' => [
                'kind' => 'project',
                'id' => $this->projectId,
                'model' => Project::class,
            ],
            'route' => 'workspace',
            'params' => [
                'view' => 'list',
                'type' => 'projects',
                'project' => $this->projectId,
            ],
            'meta' => [
                'project_end_at' => $this->projectEndAt,
                'open_tasks_count' => $this->openTasksCount,
            ],
        ];
    }
}
