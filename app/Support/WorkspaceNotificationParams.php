<?php

namespace App\Support;

use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Model;

final class WorkspaceNotificationParams
{
    /**
     * Query parameters for {@see route('workspace', $params)} aligned with task/event reminder and dashboard links.
     *
     * @return array<string, mixed>
     */
    public static function forLoggable(Model $item): array
    {
        if ($item instanceof Task) {
            $date = $item->end_datetime?->toDateString()
                ?? $item->start_datetime?->toDateString()
                ?? now()->toDateString();

            return array_filter([
                'date' => $date,
                'type' => 'tasks',
                'q' => (string) $item->title,
            ], fn ($v) => $v !== null && $v !== '');
        }

        if ($item instanceof Event) {
            $date = $item->start_datetime?->toDateString()
                ?? $item->end_datetime?->toDateString()
                ?? now()->toDateString();

            return array_filter([
                'date' => $date,
                'type' => 'events',
                'q' => (string) $item->title,
            ], fn ($v) => $v !== null && $v !== '');
        }

        if ($item instanceof Project) {
            $date = $item->end_datetime?->toDateString()
                ?? $item->start_datetime?->toDateString()
                ?? now()->toDateString();

            return array_filter([
                'date' => $date,
                'type' => 'projects',
                'q' => (string) $item->name,
            ], fn ($v) => $v !== null && $v !== '');
        }

        return [];
    }

    public static function itemKindLabel(Model $item): string
    {
        if ($item instanceof Task) {
            return 'task';
        }
        if ($item instanceof Event) {
            return 'event';
        }
        if ($item instanceof Project) {
            return 'project';
        }

        return 'item';
    }

    public static function itemTitle(Model $item): string
    {
        if ($item instanceof Task || $item instanceof Event) {
            return (string) $item->title;
        }
        if ($item instanceof Project) {
            return (string) $item->name;
        }

        return '';
    }
}
