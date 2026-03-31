<?php

namespace Tests\Unit;

use App\Services\LLM\Scheduling\TaskAssistantScheduleContextBuilder;
use Tests\TestCase;

class TaskAssistantScheduleContextBuilderTest extends TestCase
{
    public function test_it_normalizes_deterministic_priority_and_time_keywords(): void
    {
        $builder = app(TaskAssistantScheduleContextBuilder::class);

        $analysis = $builder->build('Show urgent high priority tasks due today', [
            'tasks' => [],
        ]);

        $this->assertContains('urgent', $analysis['priority_filters']);
        $this->assertContains('high', $analysis['priority_filters']);
        $this->assertSame('today', $analysis['time_constraint']);
    }

    public function test_it_detects_domain_keywords_and_comparison_focus(): void
    {
        $builder = app(TaskAssistantScheduleContextBuilder::class);

        $analysis = $builder->build('Schedule coding between math and reading this week', [
            'tasks' => [],
        ]);

        $this->assertContains('coding', $analysis['task_keywords']);
        $this->assertContains('math', $analysis['task_keywords']);
        $this->assertContains('reading', $analysis['task_keywords']);
        $this->assertSame('this_week', $analysis['time_constraint']);
        $this->assertSame('specific_comparison', $analysis['intent_type']);
    }

    public function test_it_derives_time_window_for_later_evening(): void
    {
        $builder = app(TaskAssistantScheduleContextBuilder::class);

        $analysis = $builder->build('Schedule those for later evening', [
            'tasks' => [],
            'timezone' => 'UTC',
            'today' => '2026-03-31',
            'now' => '2026-03-31T10:05:00+00:00',
        ]);

        $this->assertIsArray($analysis['time_window'] ?? null);
        $this->assertSame('18:00', $analysis['time_window']['start'] ?? null);
        $this->assertSame('22:00', $analysis['time_window']['end'] ?? null);
    }

    public function test_it_derives_time_window_for_afternoon_onwards_until_evening_cutoff(): void
    {
        $builder = app(TaskAssistantScheduleContextBuilder::class);

        $analysis = $builder->build('Schedule these for later afternoon onwards', [
            'tasks' => [],
            'timezone' => 'UTC',
            'today' => '2026-03-31',
            'now' => '2026-03-31T10:05:00+00:00',
        ]);

        $this->assertSame('15:00', $analysis['time_window']['start'] ?? null);
        $this->assertSame('22:00', $analysis['time_window']['end'] ?? null);
    }

    public function test_it_marks_window_strict_when_only_is_present(): void
    {
        $builder = app(TaskAssistantScheduleContextBuilder::class);

        $analysis = $builder->build('Schedule these in the morning only', [
            'tasks' => [],
            'timezone' => 'UTC',
            'today' => '2026-03-31',
            'now' => '2026-03-31T10:05:00+00:00',
        ]);

        $this->assertTrue((bool) ($analysis['time_window_strict'] ?? false));
        $this->assertSame('08:00', $analysis['time_window']['start'] ?? null);
        $this->assertSame('12:00', $analysis['time_window']['end'] ?? null);
    }

    public function test_it_derives_after_lunch_as_afternoon_onwards_by_default(): void
    {
        $builder = app(TaskAssistantScheduleContextBuilder::class);

        $analysis = $builder->build('Schedule after lunch', [
            'tasks' => [],
            'timezone' => 'UTC',
            'today' => '2026-03-31',
            'now' => '2026-03-31T10:05:00+00:00',
        ]);

        $this->assertSame('13:00', $analysis['time_window']['start'] ?? null);
        $this->assertSame('22:00', $analysis['time_window']['end'] ?? null);
    }

    public function test_it_derives_after_lunch_to_afternoon_as_bounded_window(): void
    {
        $builder = app(TaskAssistantScheduleContextBuilder::class);

        $analysis = $builder->build('Schedule after lunch to afternoon', [
            'tasks' => [],
            'timezone' => 'UTC',
            'today' => '2026-03-31',
            'now' => '2026-03-31T10:05:00+00:00',
        ]);

        $this->assertSame('13:00', $analysis['time_window']['start'] ?? null);
        $this->assertSame('18:00', $analysis['time_window']['end'] ?? null);
    }

    public function test_it_derives_after_dinner_to_default_end(): void
    {
        $builder = app(TaskAssistantScheduleContextBuilder::class);

        $analysis = $builder->build('Schedule after dinner', [
            'tasks' => [],
            'timezone' => 'UTC',
            'today' => '2026-03-31',
            'now' => '2026-03-31T10:05:00+00:00',
        ]);

        $this->assertSame('19:00', $analysis['time_window']['start'] ?? null);
        $this->assertSame('22:00', $analysis['time_window']['end'] ?? null);
    }

    public function test_it_derives_explicit_3pm_onwards_window(): void
    {
        $builder = app(TaskAssistantScheduleContextBuilder::class);

        $analysis = $builder->build('Schedule 3pm onwards', [
            'tasks' => [],
            'timezone' => 'UTC',
            'today' => '2026-03-31',
            'now' => '2026-03-31T10:05:00+00:00',
        ]);

        $this->assertSame('15:00', $analysis['time_window']['start'] ?? null);
        $this->assertSame('22:00', $analysis['time_window']['end'] ?? null);
    }

    public function test_it_derives_later_5pm_phrase_as_explicit_anchor(): void
    {
        $builder = app(TaskAssistantScheduleContextBuilder::class);

        $analysis = $builder->build('Schedule after I got home later 5 pm', [
            'tasks' => [],
            'timezone' => 'UTC',
            'today' => '2026-03-31',
            'now' => '2026-03-31T10:05:00+00:00',
        ]);

        $this->assertSame('17:00', $analysis['time_window']['start'] ?? null);
        $this->assertSame('22:00', $analysis['time_window']['end'] ?? null);
    }

    public function test_it_derives_after_class_as_anchor_based_window(): void
    {
        $builder = app(TaskAssistantScheduleContextBuilder::class);

        $analysis = $builder->build('Schedule these after class', [
            'tasks' => [],
            'timezone' => 'UTC',
            'today' => '2026-03-31',
            'now' => '2026-03-31T10:05:00+00:00',
        ]);

        $this->assertSame('15:00', $analysis['time_window']['start'] ?? null);
        $this->assertSame('22:00', $analysis['time_window']['end'] ?? null);
    }

    public function test_it_derives_after_work_as_anchor_based_window(): void
    {
        $builder = app(TaskAssistantScheduleContextBuilder::class);

        $analysis = $builder->build('Can you schedule this after work?', [
            'tasks' => [],
            'timezone' => 'UTC',
            'today' => '2026-03-31',
            'now' => '2026-03-31T10:05:00+00:00',
        ]);

        $this->assertSame('17:00', $analysis['time_window']['start'] ?? null);
        $this->assertSame('22:00', $analysis['time_window']['end'] ?? null);
    }

    public function test_it_derives_afternoon_and_evening_as_combined_window(): void
    {
        $builder = app(TaskAssistantScheduleContextBuilder::class);

        $analysis = $builder->build('Schedule those three for later afternoon and evening', [
            'tasks' => [],
            'timezone' => 'UTC',
            'today' => '2026-03-31',
            'now' => '2026-03-31T10:05:00+00:00',
        ]);

        $this->assertSame('15:00', $analysis['time_window']['start'] ?? null);
        $this->assertSame('22:00', $analysis['time_window']['end'] ?? null);
        $this->assertContains('intent_time_window_combined_named', $analysis['schedule_intent_reason_codes'] ?? []);
    }

    public function test_it_derives_morning_and_afternoon_as_combined_window(): void
    {
        $builder = app(TaskAssistantScheduleContextBuilder::class);

        $analysis = $builder->build('Schedule in the morning and afternoon', [
            'tasks' => [],
            'timezone' => 'UTC',
            'today' => '2026-03-31',
            'now' => '2026-03-31T10:05:00+00:00',
        ]);

        $this->assertSame('08:00', $analysis['time_window']['start'] ?? null);
        $this->assertSame('18:00', $analysis['time_window']['end'] ?? null);
    }

    public function test_it_derives_time_window_for_later_after_now_rounded_and_avoids_lunch(): void
    {
        $builder = app(TaskAssistantScheduleContextBuilder::class);

        $analysis = $builder->build('Schedule this for later', [
            'tasks' => [],
            'timezone' => 'UTC',
            'today' => '2026-03-31',
            'now' => '2026-03-31T12:07:00+00:00',
        ]);

        $this->assertIsArray($analysis['time_window'] ?? null);
        $this->assertSame('13:00', $analysis['time_window']['start'] ?? null);
        $this->assertSame('22:00', $analysis['time_window']['end'] ?? null);
    }
}
