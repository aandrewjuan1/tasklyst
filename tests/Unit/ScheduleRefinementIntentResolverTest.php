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
}
