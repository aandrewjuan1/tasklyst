<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsAndrewJuanFocusHistory;
use Illuminate\Database\Seeder;

/**
 * Opt-in demo: replace focus_sessions for andrew.juan.cvt@eac.edu.ph with balanced morning/evening volume.
 *
 * Preconditions: AndrewJuanExactDatasetSeeder must have created the user and completed history-* tasks.
 */
final class AndrewJuanFocusHistoryBalancedSeeder extends Seeder
{
    use SeedsAndrewJuanFocusHistory;

    public function run(): void
    {
        /** @var list<array{days_ago:int, hour:int, minute:int, duration_minutes:int}> $balancedSpecs */
        // Five morning (08–12 start hours) and five evening (18–21) so neither clears three-way dominance.
        $balancedSpecs = [
            ['days_ago' => 29, 'hour' => 9, 'minute' => 0, 'duration_minutes' => 50],
            ['days_ago' => 26, 'hour' => 19, 'minute' => 30, 'duration_minutes' => 52],
            ['days_ago' => 23, 'hour' => 10, 'minute' => 10, 'duration_minutes' => 54],
            ['days_ago' => 20, 'hour' => 18, 'minute' => 45, 'duration_minutes' => 50],
            ['days_ago' => 17, 'hour' => 11, 'minute' => 30, 'duration_minutes' => 53],
            ['days_ago' => 14, 'hour' => 21, 'minute' => 0, 'duration_minutes' => 51],
            ['days_ago' => 11, 'hour' => 9, 'minute' => 15, 'duration_minutes' => 52],
            ['days_ago' => 8, 'hour' => 20, 'minute' => 15, 'duration_minutes' => 53],
            ['days_ago' => 5, 'hour' => 10, 'minute' => 40, 'duration_minutes' => 54],
            ['days_ago' => 2, 'hour' => 19, 'minute' => 45, 'duration_minutes' => 52],
        ];

        foreach ($balancedSpecs as $i => &$row) {
            $row['sequence_number'] = $i + 1;
        }

        unset($row);

        $this->replaceAndrewJuanFocusWorkSessions(
            $this->withCycledTaskSourceIds($balancedSpecs)
        );
    }
}
