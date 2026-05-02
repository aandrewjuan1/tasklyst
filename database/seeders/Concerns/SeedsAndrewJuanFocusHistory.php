<?php

namespace Database\Seeders\Concerns;

use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

trait SeedsAndrewJuanFocusHistory
{
    private function andrewJuanFocusHistoryTargetEmail(): string
    {
        return 'andrew.juan.cvt@eac.edu.ph';
    }

    /**
     * @param  array<int, array{
     *   days_ago:int,
     *   hour:int,
     *   minute?:int,
     *   duration_minutes:int,
     *   source_id:string,
     *   sequence_number?:int,
     *   paused_seconds?:int
     * }>  $workSessions specs relative to timezone "now"; only work rows are inserted
     */
    protected function replaceAndrewJuanFocusWorkSessions(array $workSessions): void
    {
        $userId = (int) DB::table('users')
            ->where('email', $this->andrewJuanFocusHistoryTargetEmail())
            ->value('id');

        if ($userId <= 0) {
            if (isset($this->command)) {
                $this->command->warn('Skipping focus history seed: user '.$this->andrewJuanFocusHistoryTargetEmail().' does not exist. Run AndrewJuanExactDatasetSeeder first.');
            }

            return;
        }

        $timezoneName = trim((string) config('app.timezone', 'Asia/Manila'));
        $now = CarbonImmutable::now($timezoneName);
        $taskMorphClass = (new Task)->getMorphClass();

        $tasksBySource = DB::table('tasks')
            ->where('user_id', $userId)
            ->whereNotNull('source_id')
            ->pluck('id', 'source_id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        DB::table('focus_sessions')->where('user_id', $userId)->delete();

        foreach ($workSessions as $session) {
            $sourceId = (string) ($session['source_id'] ?? '');
            $taskId = $tasksBySource[$sourceId] ?? null;

            if (! is_int($taskId) || $taskId <= 0) {
                if (isset($this->command)) {
                    $this->command->warn('Skipping focus row: missing task for source_id '.$sourceId);
                }

                continue;
            }

            $daysAgo = max(0, (int) ($session['days_ago'] ?? 0));
            $hour = max(0, min(23, (int) ($session['hour'] ?? 9)));
            $minute = max(0, min(59, (int) ($session['minute'] ?? 0)));
            $durationMinutes = max(1, (int) ($session['duration_minutes'] ?? 60));
            $pausedSeconds = max(0, (int) ($session['paused_seconds'] ?? 180));
            $sequenceNumber = max(1, (int) ($session['sequence_number'] ?? 1));

            $day = $now->subDays($daysAgo)->setTime($hour, $minute, 0);
            $startedAt = $day->toDateTimeString();
            $endedAt = $day->addMinutes($durationMinutes)->toDateTimeString();
            $elapsedSeconds = $durationMinutes * 60;

            DB::table('focus_sessions')->insert([
                'user_id' => $userId,
                'focusable_type' => $taskMorphClass,
                'focusable_id' => $taskId,
                'type' => 'work',
                'focus_mode_type' => 'single',
                'sequence_number' => $sequenceNumber,
                'duration_seconds' => $elapsedSeconds,
                'completed' => true,
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
                'paused_seconds' => $pausedSeconds,
                'paused_at' => null,
                'payload' => null,
                'created_at' => $endedAt,
                'updated_at' => $endedAt,
            ]);
        }
    }

    /**
     * @return list<string>
     */
    protected function andrewCompletedTaskSourceIdsForFocus(): array
    {
        return [
            'history-completed-thesis-sweep',
            'history-completed-sql-practice-a',
            'history-completed-data-viz-reviewer',
            'history-completed-api-refactor',
            'history-completed-statistics-drill',
            'history-completed-thesis-slides',
        ];
    }

    /**
     * Cycle source IDs so sessions attach to alternating completed demo tasks.
     *
     * @param  array<int, array<string, mixed>>  $sessions
     * @return array<int, array<string, mixed>>
     */
    protected function withCycledTaskSourceIds(array $sessions): array
    {
        $ids = $this->andrewCompletedTaskSourceIdsForFocus();

        foreach ($sessions as $index => $session) {
            $sessions[$index]['source_id'] = $ids[$index % max(1, count($ids))];
        }

        return $sessions;
    }
}
