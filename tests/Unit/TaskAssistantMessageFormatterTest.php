<?php

namespace Tests\Unit;

use App\Services\LLM\TaskAssistant\TaskAssistantMessageFormatter;
use App\Support\LLM\TaskAssistantListingDefaults;
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

    public function test_prioritize_orders_reasoning_then_items_then_guidance_paragraph(): void
    {
        $guidance = 'I suggest opening one task first so you can manage your time without feeling overwhelmed.';
        $out = $this->formatter->format('prioritize', [
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
        $out = $this->formatter->format('prioritize', [
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
        $this->assertStringContainsString(TaskAssistantListingDefaults::noDueDateLabel(), $out);
        $this->assertStringContainsString('Complexity: '.TaskAssistantListingDefaults::complexityNotSetLabel(), $out);
        $this->assertStringContainsString('I recommend starting', $out);
    }

    public function test_browse_uses_default_reasoning_when_payload_omits_it(): void
    {
        $out = $this->formatter->format('prioritize', [
            'suggested_guidance' => TaskAssistantListingDefaults::defaultSuggestedGuidance(),
            'limit_used' => 0,
            'items' => [],
        ]);

        $this->assertStringContainsString(TaskAssistantListingDefaults::reasoningWhenEmpty(), $out);
    }

    public function test_prioritize_does_not_use_prioritize_specific_sections(): void
    {
        $out = $this->formatter->format('prioritize', [
            'reasoning' => 'Deadlines matter.',
            'suggested_guidance' => 'I recommend picking one task to start with so you can focus.',
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

        $this->assertStringContainsString('Deadlines matter.', $out);
        $this->assertStringNotContainsString('Why these priorities:', $out);
    }

    public function test_format_assumptions_plain_uses_bullets_for_multiple_lines(): void
    {
        $this->assertStringContainsString(
            '•',
            (string) $this->formatter->formatAssumptionsPlain(['First line.', 'Second line.'])
        );
    }

    public function test_daily_schedule_message_is_time_consistent_and_free_of_nominal_headings(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'summary' => 'A focused schedule.',
            'reasoning' => 'During your requested window, you stay focused.',
            'assistant_note' => 'When you’re ready.',
            'blocks' => [[
                'start_time' => '18:00',
                'end_time' => '19:30',
                'label' => 'Practice coding interview problems',
                'task_id' => 29,
                'reason' => 'Planned by strict scheduler.',
            ]],
            'strategy_points' => ['Set a timer and reduce distractions.'],
            'suggested_next_steps' => ['Open your resources and start the first problem.'],
            'assumptions' => [],
            'proposals' => [],
        ]);

        $this->assertStringNotContainsString('Why this schedule', $out);
        $this->assertStringNotContainsString('(task', $out);
        $this->assertStringContainsString("From 6:00 PM–7:30 PM you'll work on Practice coding interview problems", $out);
        $this->assertStringNotContainsString('Scheduling strategy:', $out);
        $this->assertStringNotContainsString('Suggested next steps:', $out);
        $this->assertStringContainsString('To make this schedule work', $out);
        $this->assertStringContainsString('Next,', $out);
        $this->assertStringNotContainsString('Next steps:', $out);
    }
}
