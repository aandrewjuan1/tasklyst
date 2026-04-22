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

    public function test_it_uses_multiday_later_window_for_later_this_week(): void
    {
        $builder = app(TaskAssistantScheduleContextBuilder::class);

        $analysis = $builder->build('Schedule them later this week', [
            'tasks' => [],
            'timezone' => 'UTC',
            'today' => '2026-04-02',
            'now' => '2026-04-02T22:01:00+00:00',
        ]);

        $this->assertSame('range', $analysis['schedule_horizon']['mode'] ?? null);
        $this->assertSame('2026-04-02', $analysis['schedule_horizon']['start_date'] ?? null);
        $this->assertSame('13:00', $analysis['time_window']['start'] ?? null);
        $this->assertSame('22:00', $analysis['time_window']['end'] ?? null);
        $this->assertContains('intent_time_window_later_multiday_default', $analysis['schedule_intent_reason_codes'] ?? []);
    }

    public function test_it_uses_default_asap_mode_for_vague_schedule_message_without_explicit_time_or_day(): void
    {
        config([
            'task-assistant.schedule.smart_default_spread_days' => 7,
            'task-assistant.schedule.max_horizon_days' => 14,
        ]);

        $builder = app(TaskAssistantScheduleContextBuilder::class);

        $analysis = $builder->build('schedule my top tasks', [
            'tasks' => [],
            'timezone' => 'UTC',
            'today' => '2026-04-04',
            'now' => '2026-04-04T14:00:00+00:00',
        ]);

        $this->assertTrue((bool) ($analysis['default_asap_mode'] ?? false));
        $this->assertContains('intent_default_asap_mode', $analysis['schedule_intent_reason_codes'] ?? []);
        $this->assertContains('intent_default_asap_horizon_spread', $analysis['schedule_intent_reason_codes'] ?? []);
        $this->assertSame('range', $analysis['schedule_horizon']['mode'] ?? null);
        $this->assertSame('2026-04-04', $analysis['schedule_horizon']['start_date'] ?? null);
        $this->assertSame('2026-04-10', $analysis['schedule_horizon']['end_date'] ?? null);
        $this->assertSame('default_asap_spread', $analysis['schedule_horizon']['label'] ?? null);
    }

    public function test_it_does_not_widen_when_user_names_tomorrow(): void
    {
        config([
            'task-assistant.schedule.smart_default_spread_days' => 3,
        ]);

        $builder = app(TaskAssistantScheduleContextBuilder::class);

        $analysis = $builder->build('schedule my tasks tomorrow', [
            'tasks' => [],
            'timezone' => 'UTC',
            'today' => '2026-04-04',
            'now' => '2026-04-04T14:00:00+00:00',
        ]);

        $this->assertSame('single_day', $analysis['schedule_horizon']['mode'] ?? null);
        $this->assertSame('tomorrow', $analysis['schedule_horizon']['label'] ?? null);
        $this->assertSame('2026-04-05', $analysis['schedule_horizon']['start_date'] ?? null);
    }

    public function test_it_does_not_widen_when_explicit_clock_window_is_present(): void
    {
        config([
            'task-assistant.schedule.smart_default_spread_days' => 3,
        ]);

        $builder = app(TaskAssistantScheduleContextBuilder::class);

        $analysis = $builder->build('Schedule 3pm onwards', [
            'tasks' => [],
            'timezone' => 'UTC',
            'today' => '2026-03-31',
            'now' => '2026-03-31T10:05:00+00:00',
        ]);

        $this->assertSame('single_day', $analysis['schedule_horizon']['mode'] ?? null);
        $this->assertSame('default_today', $analysis['schedule_horizon']['label'] ?? null);
        $this->assertContains('intent_time_window_explicit_onwards_time', $analysis['schedule_intent_reason_codes'] ?? []);
    }

    public function test_it_does_not_widen_when_after_lunch_anchor_applies(): void
    {
        config([
            'task-assistant.schedule.smart_default_spread_days' => 3,
        ]);

        $builder = app(TaskAssistantScheduleContextBuilder::class);

        $analysis = $builder->build('Schedule after lunch', [
            'tasks' => [],
            'timezone' => 'UTC',
            'today' => '2026-03-31',
            'now' => '2026-03-31T10:05:00+00:00',
        ]);

        $this->assertSame('single_day', $analysis['schedule_horizon']['mode'] ?? null);
        $this->assertSame('default_today', $analysis['schedule_horizon']['label'] ?? null);
        $this->assertContains('intent_time_window_after_anchor_lunch', $analysis['schedule_intent_reason_codes'] ?? []);
    }

    public function test_it_does_not_widen_when_time_constraint_is_today(): void
    {
        config([
            'task-assistant.schedule.smart_default_spread_days' => 3,
        ]);

        $builder = app(TaskAssistantScheduleContextBuilder::class);

        $analysis = $builder->build('Show urgent high priority tasks due today', [
            'tasks' => [],
            'timezone' => 'UTC',
            'today' => '2026-04-04',
            'now' => '2026-04-04T14:00:00+00:00',
        ]);

        $this->assertSame('today', $analysis['time_constraint'] ?? null);
        $this->assertSame('single_day', $analysis['schedule_horizon']['mode'] ?? null);
        $this->assertSame('today', $analysis['schedule_horizon']['label'] ?? null);
    }

    public function test_it_does_not_widen_when_user_names_explicit_calendar_date(): void
    {
        config([
            'task-assistant.schedule.smart_default_spread_days' => 3,
        ]);

        $builder = app(TaskAssistantScheduleContextBuilder::class);

        $analysis = $builder->build('actually schedule them for april 20', [
            'tasks' => [],
            'timezone' => 'UTC',
            'today' => '2026-04-18',
            'now' => '2026-04-18T12:00:00+00:00',
        ]);

        $this->assertSame('single_day', $analysis['schedule_horizon']['mode'] ?? null);
        $this->assertSame('2026-04-20', $analysis['schedule_horizon']['start_date'] ?? null);
        $this->assertSame('2026-04-20', $analysis['schedule_horizon']['end_date'] ?? null);
        $this->assertSame('explicit_date_month_day', $analysis['schedule_horizon']['label'] ?? null);
    }
}
