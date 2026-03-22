<?php

namespace Tests\Unit;

use App\Services\LLM\TaskAssistant\TaskAssistantMessageFormatter;
use App\Support\LLM\TaskAssistantBrowseDefaults;
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

    public function test_browse_orders_reasoning_then_items_then_guidance_paragraph(): void
    {
        $guidance = 'I suggest opening one task first so you can manage your time without feeling overwhelmed.';
        $out = $this->formatter->format('browse', [
            'reasoning' => 'You asked to see tasks.',
            'suggested_guidance' => $guidance,
            'limit_used' => 1,
            'items' => [
                [
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'title' => 'A',
                    'priority' => 'high',
                    'due_phrase' => 'due today',
                    'due_on' => 'Mar 22, 2026',
                    'complexity_label' => 'Simple',
                ],
            ],
        ]);

        $this->assertStringNotContainsString('Why this list:', $out);
        $this->assertStringNotContainsString('Why these priorities:', $out);
        $this->assertStringNotContainsString('Looking at:', $out);
        $this->assertStringNotContainsString('[task]', $out);
        $posReasoning = strpos($out, 'You asked to see tasks.');
        $posItems = strpos($out, '1. A —');
        $posGuidance = strpos($out, $guidance);
        $this->assertNotFalse($posReasoning);
        $this->assertNotFalse($posItems);
        $this->assertNotFalse($posGuidance);
        $this->assertLessThan($posItems, $posReasoning);
        $this->assertLessThan($posGuidance, $posItems);
        $this->assertStringNotContainsString('• ', $out);
        $this->assertStringContainsString('due today (Mar 22, 2026)', $out);
        $this->assertStringContainsString('Complexity: Simple', $out);
    }

    public function test_browse_item_lines_always_show_priority_date_and_complexity_defaults(): void
    {
        $out = $this->formatter->format('browse', [
            'reasoning' => 'Why.',
            'suggested_guidance' => 'I recommend starting with one small task to avoid feeling overwhelmed.',
            'limit_used' => 1,
            'items' => [
                [
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'title' => 'Untimed',
                    'priority' => '',
                    'due_phrase' => '',
                    'due_on' => '—',
                    'complexity_label' => '',
                ],
            ],
        ]);

        $this->assertStringContainsString('Medium priority', $out);
        $this->assertStringContainsString(TaskAssistantBrowseDefaults::noDueDateLabel(), $out);
        $this->assertStringContainsString('Complexity: '.TaskAssistantBrowseDefaults::complexityNotSetLabel(), $out);
        $this->assertStringContainsString('I recommend starting', $out);
    }

    public function test_browse_uses_default_reasoning_when_payload_omits_it(): void
    {
        $out = $this->formatter->format('browse', [
            'suggested_guidance' => TaskAssistantBrowseDefaults::defaultSuggestedGuidance(),
            'limit_used' => 0,
            'items' => [],
        ]);

        $this->assertStringContainsString(TaskAssistantBrowseDefaults::reasoningWhenEmpty(), $out);
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
