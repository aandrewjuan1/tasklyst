<?php

namespace Tests\Unit;

use App\Services\LLM\Scheduling\TaskAssistantScheduleContextBuilder;
use Tests\TestCase;

class TaskAssistantScheduleContextBuilderTest extends TestCase
{
    public function test_it_normalizes_deterministic_priority_and_time_keywords(): void
    {
        $builder = new TaskAssistantScheduleContextBuilder;

        $analysis = $builder->build('Show urgent high priority tasks due today', [
            'tasks' => [],
        ]);

        $this->assertContains('urgent', $analysis['priority_filters']);
        $this->assertContains('high', $analysis['priority_filters']);
        $this->assertSame('today', $analysis['time_constraint']);
    }

    public function test_it_detects_domain_keywords_and_comparison_focus(): void
    {
        $builder = new TaskAssistantScheduleContextBuilder;

        $analysis = $builder->build('Schedule coding between math and reading this week', [
            'tasks' => [],
        ]);

        $this->assertContains('coding', $analysis['task_keywords']);
        $this->assertContains('math', $analysis['task_keywords']);
        $this->assertContains('reading', $analysis['task_keywords']);
        $this->assertSame('this_week', $analysis['time_constraint']);
        $this->assertSame('specific_comparison', $analysis['intent_type']);
    }
}
