<?php

namespace App\Services\Llm;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Deterministic fallback for task prioritization and (future) schedule suggestions.
 * Used when the LLM is unavailable or returns invalid output, and independently
 * e.g. for the Dashboard to show a quick ranked list without calling the LLM.
 *
 * Rules (per LLM flow reference):
 * - Overdue tasks → highest rank (first in list).
 * - Earlier due date → higher rank.
 * - Higher priority (urgent > high > medium > low) → tie-breaker.
 * - Higher complexity (complex > moderate > simple) → tie-breaker for scheduling.
 */
class RuleBasedPrioritizationService
{
    /**
     * Return pending tasks for the user ranked by: overdue first, then by due date ascending,
     * then by priority (urgent first), then by complexity (complex first).
     *
     * @return Collection<int, Task>
     */
    public function prioritizeTasks(User $user, int $limit = 12): Collection
    {
        $now = now()->toDateTimeString();

        return Task::query()
            ->forUser($user->id)
            ->incomplete()
            ->orderByRaw(
                'CASE WHEN end_datetime IS NOT NULL AND end_datetime < ? THEN 0 ELSE 1 END',
                [$now]
            )
            ->orderBy('end_datetime', 'asc')
            ->orderByRaw(
                "CASE COALESCE(priority, '') WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END"
            )
            ->orderByRaw(
                "CASE COALESCE(complexity, '') WHEN 'complex' THEN 1 WHEN 'moderate' THEN 2 WHEN 'simple' THEN 3 ELSE 4 END"
            )
            ->limit($limit)
            ->get();
    }
}
