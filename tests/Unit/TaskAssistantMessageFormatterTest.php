<?php

namespace Tests\Unit;

use App\Services\LLM\TaskAssistant\TaskAssistantMessageFormatter;
use App\Support\LLM\TaskAssistantPrioritizeOutputDefaults;
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

    public function test_prioritize_orders_framing_items_reasoning_then_next_options_last(): void
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
        $this->assertLessThan($posNextOptions, $posReasoning);
        $this->assertStringContainsString('due today (Mar 22, 2026)', $out);
    }

    public function test_prioritize_places_filter_interpretation_after_numbered_items_then_reasoning_then_next_options(): void
    {
        $framing = 'Here is your slice.';
        $filter = 'Filtered to tasks due today.';
        $reasoning = 'Coach paragraph after filter before scheduling options.';
        $nextOptions = 'If you want, I can schedule this for later.';
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
            'next_options' => $nextOptions,
        ]);

        $posFraming = strpos($out, $framing);
        $posFilter = strpos($out, $filter);
        $posItems = strpos($out, '1. A —');
        $posNext = strpos($out, $nextOptions);
        $posReasoning = strpos($out, $reasoning);
        $this->assertNotFalse($posFraming);
        $this->assertNotFalse($posFilter);
        $this->assertNotFalse($posItems);
        $this->assertNotFalse($posNext);
        $this->assertNotFalse($posReasoning);
        $this->assertLessThan($posItems, $posFraming);
        $this->assertLessThan($posFilter, $posItems);
        $this->assertLessThan($posReasoning, $posFilter);
        $this->assertLessThan($posNext, $posReasoning);
    }

    public function test_prioritize_places_count_mismatch_explanation_after_items_before_filter_and_reasoning(): void
    {
        $framing = 'Here is your slice.';
        $countMismatch = 'You asked for 2, and I found 1 strong match for this focus.';
        $filter = 'Filtered to tasks due today.';
        $reasoning = 'Coach paragraph after mismatch note.';
        $nextOptions = 'If you want, I can schedule this for later.';
        $out = $this->formatter->format('prioritize', [
            'framing' => $framing,
            'count_mismatch_explanation' => $countMismatch,
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
            'next_options' => $nextOptions,
        ]);

        $posItems = strpos($out, '1. A —');
        $posMismatch = strpos($out, $countMismatch);
        $posFilter = strpos($out, $filter);
        $posReasoning = strpos($out, $reasoning);
        $this->assertNotFalse($posItems);
        $this->assertNotFalse($posMismatch);
        $this->assertNotFalse($posFilter);
        $this->assertNotFalse($posReasoning);
        $this->assertLessThan($posMismatch, $posItems);
        $this->assertLessThan($posFilter, $posMismatch);
        $this->assertLessThan($posReasoning, $posFilter);
    }

    public function test_prioritize_places_doing_coach_before_in_progress_titles_then_ranked_then_filter_then_reasoning_then_next_options(): void
    {
        $framing = 'Here is your slice.';
        $filter = 'Filtered to tasks due today.';
        $coach = 'Wrap up active work on Doing task one first—less switching usually beats juggling.';
        $reasoning = 'Why row one is first and a concrete tip.';
        $nextOptions = 'If you want, I can schedule this for later.';
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
            'next_options' => $nextOptions,
        ]);

        $bridgeBefore = TaskAssistantPrioritizeOutputDefaults::prioritizeFormatterBridgeBeforeDoingCoach();
        $bridgeAfter = TaskAssistantPrioritizeOutputDefaults::prioritizeFormatterBridgeAfterDoingCoach(1);

        $posFraming = strpos($out, $framing);
        $posFilter = strpos($out, $filter);
        $posCoach = strpos($out, $coach);
        $posBridgeAfter = strpos($out, $bridgeAfter);
        $posItems = strpos($out, '1. A —');
        $posNext = strpos($out, $nextOptions);
        $posReasoning = strpos($out, $reasoning);
        $this->assertFalse($posFraming);
        $this->assertNotFalse($posFilter);
        $this->assertStringNotContainsString($bridgeBefore, $out);
        $this->assertNotFalse($posCoach);
        $this->assertNotFalse($posBridgeAfter);
        $this->assertNotFalse($posItems);
        $this->assertNotFalse($posNext);
        $this->assertNotFalse($posReasoning);
        $this->assertLessThan($posBridgeAfter, $posCoach); // coach before bridge
        $this->assertLessThan($posItems, $posBridgeAfter); // bridge before ranked list
        $this->assertLessThan($posFilter, $posItems); // ranked list before filter
        $this->assertLessThan($posReasoning, $posFilter); // filter before reasoning
        $this->assertLessThan($posNext, $posReasoning); // reasoning before next_options
    }

    public function test_prioritize_skips_bridge_before_doing_coach_when_coach_already_signals_in_progress(): void
    {
        $framing = 'Here is your slice.';
        $coach = 'You already have one task in progress: X.';
        $out = $this->formatter->format('prioritize', [
            'framing' => $framing,
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
            'reasoning' => 'Row one fits your ask.',
            'next_options' => 'Next.',
        ]);

        $bridgeBefore = TaskAssistantPrioritizeOutputDefaults::prioritizeFormatterBridgeBeforeDoingCoach();
        $this->assertFalse(TaskAssistantPrioritizeOutputDefaults::shouldEmitPrioritizeFormatterBridgeBeforeDoingCoach($coach));
        $this->assertStringNotContainsString($framing, $out);
        $this->assertStringContainsString($coach, $out);
        $this->assertStringNotContainsString($bridgeBefore, $out);
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

    public function test_prioritize_item_lines_always_show_priority_date_and_complexity_defaults(): void
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
        $this->assertStringContainsString(TaskAssistantPrioritizeOutputDefaults::noDueDateLabel(), $out);
        $this->assertStringContainsString('Complexity: '.TaskAssistantPrioritizeOutputDefaults::complexityNotSetLabel(), $out);
        $this->assertStringContainsString('If you want, I can schedule this for later.', $out);
    }

    public function test_prioritize_uses_default_framing_when_payload_omits_it(): void
    {
        $out = $this->formatter->format('prioritize', [
            'limit_used' => 0,
            'items' => [],
        ]);

        $this->assertStringContainsString(TaskAssistantPrioritizeOutputDefaults::reasoningWhenEmpty(), $out);
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
            'framing' => 'Here is a focused plan for this window.',
            'reasoning' => 'During your requested window, you stay focused.',
            'confirmation' => 'Does this evening block work, or should we nudge it earlier?',
            'blocks' => [[
                'start_time' => '18:00',
                'end_time' => '19:30',
                'label' => 'Practice coding interview problems',
                'task_id' => 29,
                'reason' => 'Planned by strict scheduler.',
            ]],
            'items' => [[
                'title' => 'Practice coding interview problems',
                'entity_type' => 'task',
                'entity_id' => 29,
                'start_datetime' => '2026-03-22T18:00:00+00:00',
                'end_datetime' => '2026-03-22T19:30:00+00:00',
                'duration_minutes' => 90,
            ]],
            'proposals' => [[
                'proposal_id' => 'p1',
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => 29,
                'title' => 'Practice coding interview problems',
                'start_datetime' => '2026-03-22T18:00:00+00:00',
                'end_datetime' => '2026-03-22T19:30:00+00:00',
                'duration_minutes' => 90,
            ]],
        ]);

        $this->assertStringNotContainsString('Why this schedule', $out);
        $this->assertStringNotContainsString('(task', $out);
        $this->assertStringContainsString('Here is a focused plan for this window.', $out);
        $this->assertStringContainsString('Practice coding interview problems', $out);
        $this->assertStringContainsString('Mar 22, 2026 · 6:00 PM–7:30 PM', $out);
        $this->assertStringContainsString('(~1 hr 30 min)', $out);
        $this->assertStringNotContainsString('Accept all', $out);
        $this->assertStringContainsString('Does this evening block work', $out);
    }

    public function test_daily_schedule_message_formats_long_duration_using_hours(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'framing' => 'Here is your schedule.',
            'reasoning' => 'This block helps you focus first.',
            'confirmation' => 'Does this feel workable?',
            'blocks' => [[
                'start_time' => '13:00',
                'end_time' => '18:00',
                'label' => 'Impossible 5h study block before quiz',
                'task_id' => 31,
                'reason' => 'Planned by strict scheduler.',
            ]],
            'items' => [[
                'title' => 'Impossible 5h study block before quiz',
                'entity_type' => 'task',
                'entity_id' => 31,
                'start_datetime' => '2026-03-31T13:00:00+08:00',
                'end_datetime' => '2026-03-31T18:00:00+08:00',
                'duration_minutes' => 300,
            ]],
        ]);

        $this->assertStringContainsString('(~5 hrs)', $out);
        $this->assertStringNotContainsString('(~300 min)', $out);
    }

    public function test_daily_schedule_message_preserves_ranked_row_order_from_payload(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'framing' => 'Here is your schedule.',
            'reasoning' => 'This order follows your available windows.',
            'confirmation' => 'Does this feel workable?',
            'blocks' => [
                [
                    'start_time' => '16:00',
                    'end_time' => '21:00',
                    'label' => 'Impossible 5h study block before quiz',
                    'task_id' => 31,
                ],
                [
                    'start_time' => '08:00',
                    'end_time' => '08:25',
                    'label' => 'ITEL 210 – Online Quiz: Semantic HTML',
                    'task_id' => 19,
                ],
                [
                    'start_time' => '08:40',
                    'end_time' => '09:10',
                    'label' => 'Review today’s lecture notes',
                    'task_id' => 23,
                ],
            ],
            'items' => [
                [
                    'title' => 'Impossible 5h study block before quiz',
                    'entity_type' => 'task',
                    'entity_id' => 31,
                    'start_datetime' => '2026-04-02T16:00:00+08:00',
                    'end_datetime' => '2026-04-02T21:00:00+08:00',
                    'duration_minutes' => 300,
                ],
                [
                    'title' => 'ITEL 210 – Online Quiz: Semantic HTML',
                    'entity_type' => 'task',
                    'entity_id' => 19,
                    'start_datetime' => '2026-04-02T08:00:00+08:00',
                    'end_datetime' => '2026-04-02T08:25:00+08:00',
                    'duration_minutes' => 25,
                ],
                [
                    'title' => 'Review today’s lecture notes',
                    'entity_type' => 'task',
                    'entity_id' => 23,
                    'start_datetime' => '2026-04-02T08:40:00+08:00',
                    'end_datetime' => '2026-04-02T09:10:00+08:00',
                    'duration_minutes' => 30,
                ],
            ],
        ]);

        $posEight = strpos($out, '8:00 AM–8:25 AM');
        $posEightForty = strpos($out, '8:40 AM–9:10 AM');
        $posFourPm = strpos($out, '4:00 PM–9:00 PM');

        $this->assertNotFalse($posEight);
        $this->assertNotFalse($posEightForty);
        $this->assertNotFalse($posFourPm);
        $this->assertTrue($posFourPm < $posEight);
        $this->assertTrue($posEight < $posEightForty);
    }

    public function test_daily_schedule_digest_note_mentions_count_limit_reason_instead_of_planning_horizon(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'framing' => 'Here is what I scheduled.',
            'reasoning' => 'Because these were the best-fitting blocks.',
            'confirmation' => 'Does this look okay to you?',
            'blocks' => [],
            'items' => [],
            'proposals' => [
                [
                    'proposal_id' => 'p1',
                    'status' => 'pending',
                    'entity_type' => 'task',
                    'entity_id' => 29,
                    'title' => 'Some task',
                    'start_datetime' => '2026-03-22T18:00:00+00:00',
                    'end_datetime' => '2026-03-22T19:00:00+00:00',
                    'duration_minutes' => 60,
                ],
            ],
            'schedule_variant' => 'daily',
            'schedule_empty_placement' => false,
            'placement_digest' => [
                'placement_dates' => ['2026-03-22'],
                'days_used' => ['2026-03-22'],
                'skipped_targets' => [],
                'unplaced_units' => [
                    [
                        'entity_type' => 'task',
                        'entity_id' => 99,
                        'title' => 'Unplaced',
                        'minutes' => 30,
                        'reason' => 'count_limit',
                    ],
                ],
                'summary' => 'placed_proposals=1 days_used=1 unplaced_units=1',
            ],
        ]);

        $this->assertStringContainsString(
            'I scheduled only up to the maximum number of items for this step',
            $out
        );
        $this->assertStringNotContainsString('planning horizon or row limit', $out);
        $this->assertStringNotContainsString('planning horizon', $out);
    }

    public function test_general_guidance_uses_next_options_as_closing_paragraph_when_present(): void
    {
        $out = $this->formatter->format('general_guidance', [
            'acknowledgement' => "I didn't quite catch that yet.",
            'message' => 'Please rephrase it in one short sentence.',
            'suggested_next_actions' => [
                'Prioritize my tasks.',
                'Schedule time blocks for my tasks.',
            ],
            'next_options' => 'If you want, I can help you decide what to tackle first or block time for what matters most.',
        ]);

        $this->assertStringContainsString(
            'If you want, I can help you decide what to tackle first or block time for what matters most.',
            $out
        );
        $this->assertStringNotContainsString('Next, you can prioritize your tasks or schedule time blocks', $out);
    }

    public function test_daily_schedule_partial_digest_note_is_singular_and_avoids_chunking_terms(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'framing' => 'Here is your updated schedule.',
            'reasoning' => 'This fits your available window for now.',
            'confirmation' => 'Would you like any changes before saving?',
            'blocks' => [],
            'items' => [],
            'proposals' => [],
            'schedule_variant' => 'daily',
            'schedule_empty_placement' => false,
            'placement_digest' => [
                'placement_dates' => ['2026-03-22'],
                'days_used' => ['2026-03-22'],
                'skipped_targets' => [],
                'unplaced_units' => [],
                'partial_units' => [[
                    'entity_type' => 'task',
                    'entity_id' => 31,
                    'title' => 'Impossible 5h study block before quiz',
                    'requested_minutes' => 300,
                    'placed_minutes' => 255,
                    'reason' => 'partial_fit',
                ]],
                'summary' => 'placed_proposals=1 days_used=1 unplaced_units=0',
            ],
        ]);

        $this->assertStringContainsString('I scheduled Impossible 5h study block before quiz', $out);
        $this->assertStringNotContainsString('One or more tasks did not fully fit', $out);
        $this->assertStringNotContainsString('Pomodoro', $out);
        $this->assertStringNotContainsString('chunk', mb_strtolower($out));
    }

    public function test_daily_schedule_skipped_targets_sentence_is_not_rendered(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'framing' => 'Here is your schedule.',
            'reasoning' => 'This fits your available window for now.',
            'confirmation' => 'Would you like any changes before saving?',
            'blocks' => [],
            'items' => [],
            'proposals' => [],
            'schedule_variant' => 'daily',
            'schedule_empty_placement' => false,
            'placement_digest' => [
                'placement_dates' => ['2026-03-22'],
                'days_used' => ['2026-03-22'],
                'skipped_targets' => [
                    [
                        'entity_type' => 'event',
                        'entity_id' => 2,
                        'title' => 'Some event',
                        'reason' => 'event_already_timed',
                    ],
                ],
                'unplaced_units' => [],
                'partial_units' => [],
                'summary' => 'placed_proposals=0 days_used=1 unplaced_units=0',
            ],
        ]);

        $this->assertStringNotContainsString('Some targeted tasks could not be scheduled', $out);
    }
}
