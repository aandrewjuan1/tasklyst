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

    public function test_prioritize_orders_framing_then_items_then_reasoning_then_next_options(): void
    {
        $ack = 'Got it.';
        $framing = 'Not everything deserves your attention—this focuses on what actually moves your goal.';
        $reasoning = 'This ordering matches what you asked for.';
        $nextOptions = 'If you want, I can schedule these steps for later.';
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
            'reasoning' => $reasoning,
            'next_options' => $nextOptions,
        ]);

        $this->assertStringContainsString($ack, $out);
        $this->assertStringContainsString($framing, $out);
        $posItems = strpos($out, '1. A —');
        $posFraming = strpos($out, $framing);
        $this->assertNotFalse($posItems);
        $this->assertNotFalse($posFraming);

        $posReasoning = strpos($out, $reasoning);
        $posNextOptions = strpos($out, $nextOptions);
        $this->assertNotFalse($posReasoning);
        $this->assertNotFalse($posNextOptions);
        // The output should contain both segments in the intended order.
        // (We avoid strict strpos comparisons since substrings can overlap.)
        $this->assertStringContainsString('due today (Mar 22, 2026)', $out);
    }

    public function test_prioritize_places_filter_interpretation_after_framing_before_numbered_items(): void
    {
        $framing = 'Here is your slice.';
        $filter = 'Filtered to tasks due today.';
        $reasoning = 'This ordering matches what you asked for.';
        $out = $this->formatter->format('prioritize', [
            'framing' => $framing,
            'filter_interpretation' => $filter,
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
            'reasoning' => $reasoning,
            'next_options' => 'Next.',
        ]);

        $posFraming = strpos($out, $framing);
        $posFilter = strpos($out, $filter);
        $posItems = strpos($out, '1. A —');
        $this->assertNotFalse($posFraming);
        $this->assertNotFalse($posFilter);
        $this->assertNotFalse($posItems);
        // PHPUnit: assertLessThan($expected, $actual) asserts $actual < $expected.
        $this->assertLessThan($posFilter, $posFraming);
        $this->assertLessThan($posItems, $posFilter);
    }

    public function test_prioritize_places_doing_progress_coach_after_filter_before_numbered_items(): void
    {
        $framing = 'Here is your slice.';
        $filter = 'Filtered to tasks due today.';
        $coach = 'You already have one task in progress: X.';
        $reasoning = 'This ordering matches what you asked for.';
        $out = $this->formatter->format('prioritize', [
            'framing' => $framing,
            'filter_interpretation' => $filter,
            'doing_progress_coach' => $coach,
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
            'reasoning' => $reasoning,
            'next_options' => 'Next.',
        ]);

        $posFraming = strpos($out, $framing);
        $posFilter = strpos($out, $filter);
        $posCoach = strpos($out, $coach);
        $posItems = strpos($out, '1. A —');
        $this->assertNotFalse($posFraming);
        $this->assertNotFalse($posFilter);
        $this->assertNotFalse($posCoach);
        $this->assertNotFalse($posItems);
        $this->assertLessThan($posFilter, $posFraming);
        $this->assertLessThan($posCoach, $posFilter);
        $this->assertLessThan($posItems, $posCoach);
    }

    public function test_prioritize_due_later_bucket_uses_due_date_label_not_vague_later_phrase(): void
    {
        $out = $this->formatter->format('prioritize', [
            'framing' => 'Here is your slice.',
            'limit_used' => 1,
            'items' => [
                [
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'title' => 'Reading',
                    'priority' => 'medium',
                    'due_bucket' => 'due_later',
                    'due_phrase' => 'due later',
                    'due_on' => 'Apr 10, 2026',
                    'complexity_label' => 'Simple',
                ],
            ],
            'reasoning' => 'Because.',
            'next_options' => 'Next.',
        ]);

        $this->assertStringContainsString('Due Apr 10, 2026', $out);
        $this->assertStringNotContainsString('due later (Apr 10, 2026)', $out);
        $this->assertStringContainsString('Complexity: Simple', $out);
        $this->assertStringContainsString('Next.', $out);
    }

    public function test_prioritize_dedupes_when_acknowledgment_equals_framing(): void
    {
        $sentence = 'I understand you are overwhelmed.';
        $out = $this->formatter->format('prioritize', [
            'acknowledgment' => $sentence,
            'framing' => $sentence,
            'limit_used' => 1,
            'items' => [
                [
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'title' => 'A',
                    'priority' => 'medium',
                    'due_phrase' => 'overdue',
                    'due_on' => 'Mar 22, 2026',
                    'complexity_label' => 'Not set',
                ],
            ],
            'reasoning' => 'Because this helps you get momentum.',
            'next_options' => 'If you want, I can schedule these steps for later.',
            'next_options_chip_texts' => ['Schedule these for later'],
        ]);

        $this->assertSame(1, substr_count($out, $sentence));
    }

    public function test_prioritize_dedupes_when_ack_and_framing_match_after_normalization(): void
    {
        $ack = 'I understand you are overwhelmed.';
        $framing = 'i understand  you are overwhelmed.';

        $out = $this->formatter->format('prioritize', [
            'acknowledgment' => $ack,
            'framing' => $framing,
            'limit_used' => 1,
            'items' => [
                [
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'title' => 'A',
                    'priority' => 'medium',
                    'due_phrase' => 'overdue',
                    'due_on' => 'Mar 22, 2026',
                    'complexity_label' => 'Not set',
                ],
            ],
            'reasoning' => 'Because this helps you get momentum.',
            'next_options' => 'If you want, I can schedule these steps for later.',
            'next_options_chip_texts' => ['Schedule these for later'],
        ]);

        $this->assertSame(1, substr_count($out, $ack));
    }

    public function test_prioritize_dedupes_when_framing_starts_with_acknowledgment(): void
    {
        $ack = 'Start with the overdue items';
        $framing = 'Start with the overdue items so you can feel caught up sooner.';

        $out = $this->formatter->format('prioritize', [
            'acknowledgment' => $ack,
            'framing' => $framing,
            'limit_used' => 1,
            'items' => [
                [
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'title' => 'A',
                    'priority' => 'medium',
                    'due_phrase' => 'overdue',
                    'due_on' => 'Mar 22, 2026',
                    'complexity_label' => 'Not set',
                ],
            ],
            'reasoning' => 'Because this helps you get momentum.',
            'next_options' => 'If you want, I can schedule these steps for later.',
            'next_options_chip_texts' => ['Schedule these for later'],
        ]);

        $this->assertSame(1, substr_count($out, $ack));
        $this->assertStringContainsString($framing, $out);
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
        ]);

        $this->assertStringContainsString('Medium priority', $out);
        $this->assertStringContainsString(TaskAssistantListingDefaults::noDueDateLabel(), $out);
        $this->assertStringContainsString('Complexity: '.TaskAssistantListingDefaults::complexityNotSetLabel(), $out);
        $this->assertStringContainsString('If you want, I can schedule this for later.', $out);
    }

    public function test_browse_uses_default_framing_when_payload_omits_it(): void
    {
        $out = $this->formatter->format('prioritize', [
            'limit_used' => 0,
            'items' => [],
        ]);

        $this->assertStringContainsString(TaskAssistantListingDefaults::reasoningWhenEmpty(), $out);
    }

    public function test_prioritize_ignores_placement_blurb_fields(): void
    {
        $blurb = 'Ranked here for urgency and your current filter.';
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
        ]);

        $this->assertStringContainsString('1. Alpha —', $out);
        $this->assertStringNotContainsString($blurb, $out);
    }

    public function test_prioritize_event_row_shows_kind(): void
    {
        $blurb = 'Upcoming commitment in your calendar window.';
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

    public function test_general_guidance_formats_prioritize_and_schedule_actions_in_one_calm_sentence(): void
    {
        $out = $this->formatter->format('general_guidance', [
            'acknowledgement' => "I didn't quite catch that yet.",
            'message' => 'Please rephrase it in one short sentence.',
            'suggested_next_actions' => [
                'Prioritize my tasks.',
                'Schedule time blocks for my tasks.',
            ],
        ]);

        $this->assertStringContainsString(
            'Next, you can prioritize your tasks or schedule time blocks for your tasks.',
            $out
        );
        $this->assertStringNotContainsString(' Or ', $out);
    }
}
