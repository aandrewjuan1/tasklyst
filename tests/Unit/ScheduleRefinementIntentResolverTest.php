<?php

namespace Tests\Unit;

use App\Services\LLM\Scheduling\ScheduleRefinementIntentResolver;
use Tests\TestCase;

class ScheduleRefinementIntentResolverTest extends TestCase
{
    public function test_heuristic_detects_shift_later_for_first_row(): void
    {
        $resolver = app(ScheduleRefinementIntentResolver::class);
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
        $resolver = app(ScheduleRefinementIntentResolver::class);
        $proposals = [
            ['title' => 'A', 'start_datetime' => '2026-01-01T10:00:00+00:00'],
            ['title' => 'B', 'start_datetime' => '2026-01-01T11:00:00+00:00'],
        ];

        $ops = $resolver->resolve('Move the second one to 2026-01-05', $proposals, 'UTC');

        $this->assertSame('set_local_date_ymd', $ops[0]['op'] ?? null);
        $this->assertSame(1, $ops[0]['proposal_index'] ?? null);
        $this->assertSame('2026-01-05', $ops[0]['local_date_ymd'] ?? null);
    }

    public function test_resolver_handles_natural_language_time_without_at_keyword(): void
    {
        $resolver = app(ScheduleRefinementIntentResolver::class);
        $proposals = [
            ['title' => 'Quiz block', 'start_datetime' => '2026-03-31T19:00:00+08:00'],
            ['title' => 'Essay draft', 'start_datetime' => '2026-03-31T20:00:00+08:00'],
            ['title' => 'Lecture notes', 'start_datetime' => '2026-03-31T21:00:00+08:00'],
        ];

        $ops = $resolver->resolve('move the third one later 8 pm instead', $proposals, 'UTC');

        $this->assertSame('set_local_time_hhmm', $ops[0]['op'] ?? null);
        $this->assertSame(2, $ops[0]['proposal_index'] ?? null);
        $this->assertSame('20:00', $ops[0]['local_time_hhmm'] ?? null);
    }

    public function test_resolver_requires_clarification_for_ambiguous_pronoun_target(): void
    {
        $resolver = app(ScheduleRefinementIntentResolver::class);
        $proposals = [
            ['title' => 'Quiz block', 'start_datetime' => '2026-03-31T19:00:00+08:00'],
            ['title' => 'Essay draft', 'start_datetime' => '2026-03-31T20:00:00+08:00'],
        ];

        $resolved = $resolver->resolveDetailed('edit it i wanna do it later 8 pm', $proposals, 'Asia/Singapore');

        $this->assertTrue($resolved['clarification_required']);
        $this->assertSame([], $resolved['operations']);
    }

    public function test_resolver_supports_reorder_with_optional_the_and_one_tokens(): void
    {
        $resolver = app(ScheduleRefinementIntentResolver::class);
        $proposals = [
            ['title' => 'Quiz block', 'start_datetime' => '2026-03-31T19:00:00+08:00'],
            ['title' => 'Essay draft', 'start_datetime' => '2026-03-31T20:00:00+08:00'],
            ['title' => 'Lecture notes', 'start_datetime' => '2026-03-31T21:00:00+08:00'],
        ];

        $ops = $resolver->resolve('move the first one to last', $proposals, 'Asia/Singapore');

        $this->assertSame('move_to_position', $ops[0]['op'] ?? null);
        $this->assertSame(0, $ops[0]['proposal_index'] ?? null);
        $this->assertSame(2, $ops[0]['target_index'] ?? null);
    }
}
