<?php

namespace Tests\Unit;

use App\Services\LLM\TaskAssistant\TaskAssistantMessageFormatter;
use Tests\TestCase;

class TaskAssistantMessageFormatterTest extends TestCase
{
    private TaskAssistantMessageFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = app(TaskAssistantMessageFormatter::class);
    }

    public function test_humanize_filter_replaces_this_week_and_keywords(): void
    {
        $this->assertStringContainsString(
            'this week',
            $this->formatter->humanizeFilterDescription('time: this_week')
        );
        $this->assertStringContainsString(
            'math',
            $this->formatter->humanizeFilterDescription('keywords/tags/title: math')
        );
        $this->assertStringContainsString(
            'highest-ranked',
            $this->formatter->humanizeFilterDescription('no strong filters; showing top-ranked tasks for now')
        );
    }

    public function test_browse_uses_why_this_list_not_why_these_priorities(): void
    {
        $out = $this->formatter->format('browse', [
            'summary' => 'Here is your list.',
            'reasoning' => 'You asked to see tasks.',
            'assistant_note' => null,
            'strategy_points' => [],
            'suggested_next_steps' => [],
            'assumptions' => ['One fact.'],
            'filter_description' => 'time: this_week',
            'limit_used' => 1,
            'items' => [
                [
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'title' => 'A',
                    'reason' => 'High · due today',
                ],
            ],
        ]);

        $this->assertStringContainsString('Why this list:', $out);
        $this->assertStringNotContainsString('Why these priorities:', $out);
        $this->assertStringContainsString('Looking at:', $out);
    }

    public function test_prioritize_uses_why_these_priorities(): void
    {
        $out = $this->formatter->format('prioritize', [
            'summary' => 'Top picks.',
            'reasoning' => 'Deadlines matter.',
            'assistant_note' => null,
            'strategy_points' => [],
            'suggested_next_steps' => [],
            'assumptions' => ['Assumption one.'],
            'limit_used' => 1,
            'items' => [
                [
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'title' => 'A',
                    'reason' => 'High',
                ],
            ],
        ]);

        $this->assertStringContainsString('Why these priorities:', $out);
        $this->assertStringContainsString('For context:', $out);
        $this->assertStringNotContainsString('Notes:', $out);
    }

    public function test_format_assumptions_plain_uses_bullets_for_multiple_lines(): void
    {
        $this->assertStringContainsString(
            '•',
            (string) $this->formatter->formatAssumptionsPlain(['First line.', 'Second line.'])
        );
    }
}
