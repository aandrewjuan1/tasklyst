<?php

namespace Tests\Unit;

use App\Services\LLM\Scheduling\ScheduleRefinementIntentResolver;
use Tests\TestCase;

class ScheduleRefinementIntentResolverTest extends TestCase
{
    public function test_heuristic_detects_shift_later_for_first_row(): void
    {
        config(['task-assistant.schedule_refinement.use_llm' => false]);

        $resolver = new ScheduleRefinementIntentResolver;
        $proposals = [
            ['title' => 'A', 'start_datetime' => '2026-01-01T10:00:00+00:00'],
            ['title' => 'B', 'start_datetime' => '2026-01-01T11:00:00+00:00'],
        ];

        $ops = $resolver->resolve('Please push the first one 45 minutes later', $proposals, 'UTC');

        $this->assertSame('shift_minutes', $ops[0]['op'] ?? null);
        $this->assertSame(0, $ops[0]['proposal_index'] ?? null);
        $this->assertSame(45, $ops[0]['delta_minutes'] ?? null);
    }

    public function test_heuristic_detects_set_local_date_for_second_row(): void
    {
        config(['task-assistant.schedule_refinement.use_llm' => false]);

        $resolver = new ScheduleRefinementIntentResolver;
        $proposals = [
            ['title' => 'A', 'start_datetime' => '2026-01-01T10:00:00+00:00'],
            ['title' => 'B', 'start_datetime' => '2026-01-01T11:00:00+00:00'],
        ];

        $ops = $resolver->resolve('Move the second one to 2026-01-05', $proposals, 'UTC');

        $this->assertSame('set_local_date_ymd', $ops[0]['op'] ?? null);
        $this->assertSame(1, $ops[0]['proposal_index'] ?? null);
        $this->assertSame('2026-01-05', $ops[0]['local_date_ymd'] ?? null);
    }

    public function test_heuristic_infers_pm_when_am_pm_omitted_for_row_time_context(): void
    {
        config(['task-assistant.schedule_refinement.use_llm' => false]);

        $resolver = new ScheduleRefinementIntentResolver;
        $proposals = [
            ['title' => 'A', 'start_datetime' => '2026-03-31T19:00:00+08:00'],
            ['title' => 'B', 'start_datetime' => '2026-03-31T20:00:00+08:00'],
            ['title' => 'C', 'start_datetime' => '2026-03-31T21:00:00+08:00'],
        ];

        $ops = $resolver->resolve('move the third one at 9:30 instead', $proposals, 'UTC');

        $this->assertSame('set_local_time_hhmm', $ops[0]['op'] ?? null);
        $this->assertSame(2, $ops[0]['proposal_index'] ?? null);
        $this->assertSame('21:30', $ops[0]['local_time_hhmm'] ?? null);
    }
}
