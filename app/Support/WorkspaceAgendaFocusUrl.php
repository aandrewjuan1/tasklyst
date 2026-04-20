<?php

namespace App\Support;

/**
 * Shared workspace URLs for agenda-style navigation: list view, date, {@see self::AGENDA_FOCUS_QUERY_PARAM}, optional entity id.
 * Matches dashboard calendar agenda links and in-app calendar focus (no "Show" type filter).
 */
final class WorkspaceAgendaFocusUrl
{
    public const AGENDA_FOCUS_QUERY_PARAM = 'agenda_focus';

    /**
     * @param  'task'|'event'|'project'|'school_class'  $entityType
     */
    public static function workspaceRouteForAgendaStyleFocus(string $date, string $entityType, int $entityId): string
    {
        $base = [
            'date' => $date,
            'view' => 'list',
            self::AGENDA_FOCUS_QUERY_PARAM => '1',
        ];

        if ($entityId < 1) {
            return route('workspace', $base);
        }

        $normalized = match ($entityType) {
            'event' => 'event',
            'project' => 'project',
            'school_class' => 'school_class',
            default => 'task',
        };

        return match ($normalized) {
            'event' => route('workspace', array_merge($base, ['event' => $entityId])),
            'project' => route('workspace', array_merge($base, ['project' => $entityId])),
            'school_class' => route('workspace', array_merge($base, ['school_class' => $entityId])),
            default => route('workspace', array_merge($base, ['task' => $entityId])),
        };
    }
}
