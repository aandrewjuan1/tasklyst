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

    public function test_prioritize_orders_framing_then_items_then_next_actions(): void
    {
        $ack = 'Got it.';
        $framing = 'Not everything deserves your attention—this focuses on what actually moves your goal.';
        $nextAction = 'Start with A and complete one small step.';
        $out = $this->formatter->format('prioritize', [
            'acknowledgment' => $ack,
            'framing' => $framing,
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
            'suggested_next_actions' => [$nextAction],
        ]);

        $this->assertStringContainsString($ack, $out);
        $this->assertStringContainsString($framing, $out);
        $posItems = strpos($out, '1. A —');
        $posNext = strpos($out, 'Next actions:');
        $posFraming = strpos($out, $framing);
        $this->assertNotFalse($posItems);
        $this->assertNotFalse($posNext);
        $this->assertNotFalse($posFraming);

        $this->assertStringContainsString('due today (Mar 22, 2026)', $out);
        $this->assertStringContainsString('Complexity: Simple', $out);
        $this->assertStringContainsString($nextAction, $out);
    }

    public function test_browse_item_lines_always_show_priority_date_and_complexity_defaults(): void
    {
        $out = $this->formatter->format('prioritize', [
            'framing' => 'Why.',
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
            'suggested_next_actions' => ['Start with Untimed and complete one small step.'],
        ]);

        $this->assertStringContainsString('Medium priority', $out);
        $this->assertStringContainsString(TaskAssistantListingDefaults::noDueDateLabel(), $out);
        $this->assertStringContainsString('Complexity: '.TaskAssistantListingDefaults::complexityNotSetLabel(), $out);
        $this->assertStringContainsString('Next actions:', $out);
    }

    public function test_browse_uses_default_framing_when_payload_omits_it(): void
    {
        $out = $this->formatter->format('prioritize', [
            'suggested_next_actions' => ['Start with one item and complete one small step.'],
            'limit_used' => 0,
            'items' => [],
        ]);

        $this->assertStringContainsString(TaskAssistantListingDefaults::reasoningWhenEmpty(), $out);
    }

    public function test_prioritize_ignores_placement_blurb_fields(): void
    {
        $blurb = 'Ranked here for urgency and your current filter.';
        $nextAction = 'Start with Alpha and complete one small step.';
        $out = $this->formatter->format('prioritize', [
            'framing' => 'Your ranked slice.',
            'limit_used' => 1,
            'items' => [
                [
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'title' => 'Alpha',
                    'priority' => 'high',
                    'due_phrase' => 'due today',
                    'due_on' => 'Mar 22, 2026',
                    'complexity_label' => 'Simple',
                    'placement_blurb' => $blurb,
                ],
            ],
            'suggested_next_actions' => [$nextAction],
        ]);

        $this->assertStringContainsString('1. Alpha —', $out);
        $this->assertStringNotContainsString($blurb, $out);
    }

    public function test_prioritize_event_row_shows_kind(): void
    {
        $blurb = 'Upcoming commitment in your calendar window.';
        $nextAction = 'Start with Team sync and complete one small step.';
        $out = $this->formatter->format('prioritize', [
            'framing' => 'Mix of work.',
            'limit_used' => 1,
            'items' => [
                [
                    'entity_type' => 'event',
                    'entity_id' => 9,
                    'title' => 'Team sync',
                    'placement_blurb' => $blurb,
                ],
            ],
            'suggested_next_actions' => [$nextAction],
        ]);

        $this->assertStringContainsString('1. Team sync —', $out);
        $this->assertStringNotContainsString($blurb, $out);
    }

    public function test_prioritize_does_not_use_prioritize_specific_sections(): void
    {
        $out = $this->formatter->format('prioritize', [
            'framing' => 'Deadlines matter.',
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
            'suggested_next_actions' => ['Start with A and complete one small step.'],
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
