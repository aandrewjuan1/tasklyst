<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsAndrewJuanFocusHistory;
use Illuminate\Database\Seeder;

/**
 * Opt-in demo: replace focus_sessions for andrew.juan.cvt@eac.edu.ph with a morning-heavy pattern.
 *
 * Preconditions: AndrewJuanExactDatasetSeeder must have created the user and completed history-* tasks.
 */
final class AndrewJuanFocusHistoryMorningSeeder extends Seeder
{
    use SeedsAndrewJuanFocusHistory;

    public function run(): void
    {
        /** @var list<array{days_ago:int, hour:int, minute:int, duration_minutes:int}> $morningDominantSpecs */
        $morningDominantSpecs = [
            ['days_ago' => 29, 'hour' => 9, 'minute' => 5, 'duration_minutes' => 52],
            ['days_ago' => 26, 'hour' => 10, 'minute' => 10, 'duration_minutes' => 50],
            ['days_ago' => 24, 'hour' => 11, 'minute' => 20, 'duration_minutes' => 54],
            ['days_ago' => 21, 'hour' => 12, 'minute' => 15, 'duration_minutes' => 48],
            ['days_ago' => 18, 'hour' => 9, 'minute' => 40, 'duration_minutes' => 55],
            ['days_ago' => 16, 'hour' => 10, 'minute' => 45, 'duration_minutes' => 50],
            ['days_ago' => 13, 'hour' => 11, 'minute' => 30, 'duration_minutes' => 53],
            ['days_ago' => 10, 'hour' => 12, 'minute' => 0, 'duration_minutes' => 52],
            ['days_ago' => 7, 'hour' => 10, 'minute' => 0, 'duration_minutes' => 51],
            ['days_ago' => 5, 'hour' => 19, 'minute' => 30, 'duration_minutes' => 45],
        ];

        foreach ($morningDominantSpecs as $i => &$row) {
            $row['sequence_number'] = $i + 1;
        }

        unset($row);

        $this->replaceAndrewJuanFocusWorkSessions(
            $this->withCycledTaskSourceIds($morningDominantSpecs)
        );
    }
}
