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

    public function test_prioritize_clamps_framing_to_single_non_redundant_sentence_when_items_exist(): void
    {
        $out = $this->formatter->format('prioritize', [
            'framing' => 'Here are 3 tasks ordered by urgency. Start with the list below and keep your first pass short.',
            'limit_used' => 3,
            'items' => [
                ['entity_type' => 'task', 'entity_id' => 1, 'title' => 'A', 'priority' => 'high', 'due_phrase' => 'due today', 'due_on' => 'Mar 22, 2026', 'complexity_label' => 'Simple'],
                ['entity_type' => 'task', 'entity_id' => 2, 'title' => 'B', 'priority' => 'medium', 'due_phrase' => 'due tomorrow', 'due_on' => 'Mar 23, 2026', 'complexity_label' => 'Moderate'],
                ['entity_type' => 'task', 'entity_id' => 3, 'title' => 'C', 'priority' => 'low', 'due_phrase' => 'due this week', 'due_on' => 'Mar 25, 2026', 'complexity_label' => 'Complex'],
            ],
            'reasoning' => 'Start with A first because it has the nearest due date.',
            'next_options' => 'If you want, I can schedule these tasks for later.',
        ]);

        $this->assertStringNotContainsString('Here are 3 tasks ordered by urgency.', $out);
        $this->assertStringContainsString('Here is your focused next-step slice', $out);
        $this->assertStringNotContainsString('Start with the list below and keep your first pass short.', $out);
    }

    public function test_prioritize_framing_cleanup_does_not_leave_dangling_comma_after_ranked_by_phrase_strip(): void
    {
        $out = $this->formatter->format('prioritize', [
            'framing' => 'Here are the steps I would line up first right now, ranked by urgency and deadlines.',
            'limit_used' => 3,
            'items' => [
                ['entity_type' => 'task', 'entity_id' => 1, 'title' => 'A', 'priority' => 'high', 'due_phrase' => 'due today', 'due_on' => 'Mar 22, 2026', 'complexity_label' => 'Simple'],
                ['entity_type' => 'task', 'entity_id' => 2, 'title' => 'B', 'priority' => 'medium', 'due_phrase' => 'due tomorrow', 'due_on' => 'Mar 23, 2026', 'complexity_label' => 'Moderate'],
                ['entity_type' => 'task', 'entity_id' => 3, 'title' => 'C', 'priority' => 'low', 'due_phrase' => 'due this week', 'due_on' => 'Mar 25, 2026', 'complexity_label' => 'Complex'],
            ],
            'reasoning' => 'Start with A first because it has the nearest due date.',
            'next_options' => 'If you want, I can schedule these tasks for later.',
        ]);

        $this->assertStringNotContainsString('right now,.', $out);
        $this->assertStringContainsString('Here are the steps I would line up first right now.', $out);
    }

    public function test_prioritize_renders_assumptions_block_when_present(): void
    {
        $out = $this->formatter->format('prioritize', [
            'framing' => 'Here is your slice.',
            'limit_used' => 1,
            'items' => [[
                'entity_type' => 'task',
                'entity_id' => 1,
                'title' => 'A',
                'priority' => 'high',
                'due_phrase' => 'due today',
                'due_on' => 'Mar 22, 2026',
                'complexity_label' => 'Simple',
            ]],
            'assumptions' => ['Interpreting "today" as your local calendar day.'],
            'reasoning' => 'This ordering matches what you asked for.',
            'next_options' => 'If you want, I can schedule this for later.',
        ]);

        $this->assertStringContainsString('For context:', $out);
        $this->assertStringContainsString('Interpreting "today" as your local calendar day.', $out);
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

    public function test_prioritize_compacts_ordering_rationale_when_it_duplicates_reasoning(): void
    {
        $duplicate = 'This task stands out because it is high priority and due today.';
        $out = $this->formatter->format('prioritize', [
            'framing' => 'Here is your prioritized list.',
            'limit_used' => 3,
            'items' => [
                ['entity_type' => 'task', 'entity_id' => 1, 'title' => 'A', 'priority' => 'high', 'due_phrase' => 'due today', 'due_on' => 'Mar 22, 2026', 'complexity_label' => 'Simple'],
                ['entity_type' => 'task', 'entity_id' => 2, 'title' => 'B', 'priority' => 'high', 'due_phrase' => 'due tomorrow', 'due_on' => 'Mar 23, 2026', 'complexity_label' => 'Simple'],
                ['entity_type' => 'task', 'entity_id' => 3, 'title' => 'C', 'priority' => 'medium', 'due_phrase' => 'due this week', 'due_on' => 'Mar 25, 2026', 'complexity_label' => 'Moderate'],
            ],
            'ordering_rationale' => [
                '#1 A: '.$duplicate,
                '#2 B: '.$duplicate,
                '#3 C: '.$duplicate,
            ],
            'reasoning' => '#1 A: '.$duplicate.' #2 B: '.$duplicate.' #3 C: '.$duplicate,
            'next_options' => 'If you want, I can schedule these tasks for later.',
        ]);

        $this->assertStringNotContainsString('Why this order:', $out);
        $this->assertStringContainsString('• #1 A:', $out);
        $this->assertStringContainsString('If you want, I can schedule these tasks for later.', $out);
        $this->assertLessThanOrEqual(6, substr_count($out, $duplicate));
    }

    public function test_prioritize_uses_ordering_rationale_as_single_ranked_list_without_duplicate_numbered_block(): void
    {
        $summary = 'I put urgent work first, then priority and effort, so your next move is both important and realistic.';
        $out = $this->formatter->format('prioritize', [
            'acknowledgment' => 'Here are the next steps I would take.',
            'framing' => 'I prioritized urgency first, then effort and priority.',
            'limit_used' => 3,
            'items' => [
                ['entity_type' => 'task', 'entity_id' => 1, 'title' => 'Task A', 'priority' => 'high', 'due_phrase' => 'due tomorrow', 'due_on' => 'May 1, 2026', 'complexity_label' => 'Moderate'],
                ['entity_type' => 'task', 'entity_id' => 2, 'title' => 'Task B', 'priority' => 'medium', 'due_phrase' => 'due this week', 'due_on' => 'May 2, 2026', 'complexity_label' => 'Moderate'],
                ['entity_type' => 'task', 'entity_id' => 3, 'title' => 'Task C', 'priority' => 'medium', 'due_phrase' => 'due this week', 'due_on' => 'May 3, 2026', 'complexity_label' => 'Moderate'],
            ],
            'ordering_rationale' => [
                '#1 Task A: Nearest due date and manageable effort.',
                '#2 Task B: Important and still time-sensitive this week.',
                '#3 Task C: Still relevant this week, but after the top two.',
            ],
            'ranking_method_summary' => $summary,
            'reasoning' => 'Start with Task A first, then move down the list.',
            'next_options' => 'If you want, I can place these ranked tasks later today, tomorrow, or later this week.',
        ]);

        $this->assertStringContainsString('• #1 Task A:', $out);
        $this->assertStringContainsString('• #2 Task B:', $out);
        $this->assertStringContainsString('• #3 Task C:', $out);
        $this->assertStringNotContainsString('Why this order:', $out);
        $this->assertStringNotContainsString('1. Task A —', $out);
        $this->assertStringNotContainsString('2. Task B —', $out);
        $this->assertStringNotContainsString('3. Task C —', $out);
        $this->assertLessThan(strpos($out, '• #1 Task A:'), strpos($out, $summary));
    }

    public function test_prioritize_normalizes_awkward_complexity_wording_in_reasoning(): void
    {
        $out = $this->formatter->format('prioritize', [
            'framing' => 'Here is your focused next-step slice.',
            'limit_used' => 1,
            'items' => [
                ['entity_type' => 'task', 'entity_id' => 1, 'title' => 'A', 'priority' => 'high', 'due_phrase' => 'due today', 'due_on' => 'Mar 22, 2026', 'complexity_label' => 'Complex'],
            ],
            'reasoning' => 'Start with A first because it is high priority and has Complex complexity.',
            'next_options' => 'If you want, I can schedule this for later.',
        ]);

        $this->assertStringNotContainsString('Complex complexity', $out);
        $this->assertStringContainsString('higher effort', $out);
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
        $this->assertMatchesRegularExpression('/schedule[\s\S]{0,400}later today|later today[\s\S]{0,400}schedule/iu', $out);
    }

    public function test_prioritize_normalizes_display_only_title_spacing_in_ranked_rows(): void
    {
        $out = $this->formatter->format('prioritize', [
            'framing' => 'Here is your focused next-step slice.',
            'limit_used' => 1,
            'items' => [[
                'entity_type' => 'task',
                'entity_id' => 1,
                'title' => 'STATIC AND DYNAMIC RESUME WEBSITE-  FINAL EXAM PROJECT',
                'priority' => 'high',
                'due_phrase' => 'due tomorrow',
                'due_on' => 'May 1, 2026',
                'complexity_label' => 'Moderate',
            ]],
            'reasoning' => 'Start with this first because it is time-sensitive.',
            'next_options' => 'If you want, I can schedule this for tomorrow.',
        ]);

        $this->assertStringContainsString('STATIC AND DYNAMIC RESUME WEBSITE- FINAL EXAM PROJECT', $out);
        $this->assertStringNotContainsString('WEBSITE-  FINAL', $out);
    }

    public function test_prioritize_uses_default_framing_when_payload_omits_it(): void
    {
        $out = $this->formatter->format('prioritize', [
            'limit_used' => 0,
            'items' => [],
        ]);

        $this->assertStringContainsString('student-first', mb_strtolower($out));
    }

    public function test_prioritize_omits_ranking_method_summary_when_no_ranked_items_exist(): void
    {
        $rankingSummary = 'I put urgent work first, then priority and effort, so your next move is both important and realistic.';

        $out = $this->formatter->format('prioritize', [
            'doing_progress_coach' => 'You already have one task in progress: DOING.',
            'items' => [],
            'ranking_method_summary' => $rankingSummary,
            'reasoning' => 'Finish what you started first, then ask me again for the next priority.',
            'next_options' => 'If you want, I can place this top task later today, tomorrow, or later this week.',
        ]);

        $this->assertStringContainsString('You already have one task in progress: DOING.', $out);
        $this->assertStringNotContainsString($rankingSummary, $out);
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

    public function test_daily_schedule_time_label_falls_back_to_item_datetimes_when_block_times_missing(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'framing' => 'Here is your schedule.',
            'reasoning' => 'This keeps your plan coherent.',
            'confirmation' => 'Does this feel workable?',
            'blocks' => [[
                'start_time' => '',
                'end_time' => '',
                'label' => 'Focus block',
                'task_id' => 31,
            ]],
            'items' => [[
                'title' => 'Focus block',
                'entity_type' => 'task',
                'entity_id' => 31,
                'start_datetime' => '2026-04-19T18:00:00+08:00',
                'end_datetime' => '2026-04-19T19:30:00+08:00',
                'duration_minutes' => 90,
            ]],
        ]);

        $this->assertStringContainsString('6:00 PM–7:30 PM', $out);
    }

    public function test_daily_schedule_targeted_copy_preserves_warm_time_aware_coaching(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'schedule_source' => 'targeted_schedule',
            'framing' => 'I scheduled your 10KM RUN at 5:30 PM and kept it inside today.',
            'reasoning' => 'That slot gives you a focused hour for 10KM RUN. Start gently for the first few minutes so this block feels sustainable. Set a clear stopping point so you finish with energy left for tomorrow.',
            'confirmation' => 'Do you want to keep 10KM RUN at 5:30 PM, or shift it earlier/later?',
            'blocks' => [[
                'start_time' => '17:30',
                'end_time' => '18:30',
                'label' => '10KM RUN',
                'task_id' => 1,
            ]],
            'items' => [[
                'title' => '10KM RUN',
                'entity_type' => 'task',
                'entity_id' => 1,
                'start_datetime' => '2026-04-25T17:30:00+08:00',
                'end_datetime' => '2026-04-25T18:30:00+08:00',
                'duration_minutes' => 60,
            ]],
        ]);

        $this->assertStringContainsString('10KM RUN', $out);
        $this->assertStringContainsString('5:30 PM', $out);
        $this->assertStringContainsString('Start gently', $out);
        $this->assertStringContainsString('stopping point', $out);
    }

    public function test_daily_schedule_normalizes_display_only_title_spacing_in_rendered_rows(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'framing' => 'Here is your schedule.',
            'reasoning' => 'This keeps your plan coherent.',
            'confirmation' => 'Does this feel workable?',
            'blocks' => [[
                'start_time' => '15:43',
                'end_time' => '16:53',
                'task_id' => 31,
            ]],
            'items' => [[
                'title' => 'STATIC AND DYNAMIC RESUME WEBSITE-  FINAL EXAM PROJECT',
                'entity_type' => 'task',
                'entity_id' => 31,
                'start_datetime' => '2026-05-01T15:43:00+08:00',
                'end_datetime' => '2026-05-01T16:53:00+08:00',
                'duration_minutes' => 70,
            ]],
        ]);

        $this->assertStringContainsString('STATIC AND DYNAMIC RESUME WEBSITE- FINAL EXAM PROJECT', $out);
        $this->assertStringNotContainsString('WEBSITE-  FINAL', $out);
    }

    public function test_daily_schedule_message_sorts_rows_chronologically_for_student_clarity(): void
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
        $this->assertTrue($posEight < $posEightForty);
        $this->assertTrue($posEightForty < $posFourPm);
    }

    public function test_daily_schedule_narrative_uses_tomorrow_when_narrative_facts_mark_tomorrow(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'framing' => 'Here is your open window today slot for Focus block.',
            'reasoning' => 'This keeps your momentum today.',
            'confirmation' => 'Do these times work today?',
            'narrative_facts' => [
                'requested_horizon_label' => 'tomorrow',
            ],
            'blocks' => [[
                'start_time' => '08:00',
                'end_time' => '09:00',
                'task_id' => 31,
            ]],
            'items' => [[
                'title' => 'Focus block',
                'entity_type' => 'task',
                'entity_id' => 31,
                'start_datetime' => '2026-04-25T08:00:00+08:00',
                'end_datetime' => '2026-04-25T09:00:00+08:00',
                'duration_minutes' => 60,
            ]],
        ]);

        $lower = mb_strtolower($out);
        $this->assertStringContainsString('tomorrow', $lower);
        $this->assertStringNotContainsString('open window today', $lower);
    }

    public function test_daily_schedule_polishes_known_grammar_and_template_phrases(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'framing' => 'I fit 2 tasks into in your open window today; every row underneath is a 2-block run.',
            'reasoning' => 'I prioritized the earliest realistic windows so your biggest work starts first and the follow-up blocks stay lighter.',
            'confirmation' => 'Do these times work before you save?',
            'blocks' => [[
                'start_time' => '18:00',
                'end_time' => '19:00',
            ]],
            'items' => [[
                'title' => 'Task A',
                'entity_type' => 'task',
                'entity_id' => 1,
                'start_datetime' => '2026-04-19T18:00:00+08:00',
                'end_datetime' => '2026-04-19T19:00:00+08:00',
                'duration_minutes' => 60,
            ]],
        ]);

        $this->assertStringNotContainsString('into in', $out);
        $this->assertStringNotContainsString('2-block run', $out);
        $this->assertStringNotContainsString('before you save', $out);
        $this->assertStringNotContainsString('earliest realistic windows', $out);
        $this->assertStringNotContainsString('biggest work starts first', $out);
        $this->assertStringNotContainsString('follow-up blocks stay lighter', $out);
        $this->assertStringContainsString('This keeps the plan specific and realistic for the time you have today.', $out);
    }

    public function test_daily_schedule_suppresses_bulk_unplaced_note_when_digest_flag_set(): void
    {
        $horizonUnplaced = [];
        for ($i = 0; $i < 5; $i++) {
            $horizonUnplaced[] = [
                'entity_type' => 'task',
                'entity_id' => 100 + $i,
                'title' => 'Other task '.$i,
                'minutes' => 60,
                'reason' => 'horizon_exhausted',
            ];
        }

        $out = $this->formatter->format('daily_schedule', [
            'framing' => 'Here is what I scheduled.',
            'reasoning' => 'Evening focus.',
            'confirmation' => 'Does this work?',
            'blocks' => [
                ['start_time' => '18:00', 'end_time' => '22:00', 'task_id' => 31, 'event_id' => null, 'label' => 'Study block', 'note' => null],
            ],
            'items' => [[
                'title' => 'Impossible 5h study block before quiz',
                'entity_type' => 'task',
                'entity_id' => 31,
                'start_datetime' => '2026-04-04T18:00:00+08:00',
                'end_datetime' => '2026-04-04T22:00:00+08:00',
                'duration_minutes' => 240,
            ]],
            'proposals' => [
                [
                    'proposal_id' => 'p1',
                    'status' => 'pending',
                    'entity_type' => 'task',
                    'entity_id' => 31,
                    'title' => 'Impossible 5h study block before quiz',
                    'start_datetime' => '2026-04-04T18:00:00+08:00',
                    'end_datetime' => '2026-04-04T22:00:00+08:00',
                    'duration_minutes' => 240,
                ],
            ],
            'schedule_variant' => 'daily',
            'schedule_empty_placement' => false,
            'placement_digest' => [
                'placement_dates' => ['2026-04-04'],
                'days_used' => ['2026-04-04'],
                'skipped_targets' => [],
                'unplaced_units' => $horizonUnplaced,
                'partial_units' => [[
                    'entity_type' => 'task',
                    'entity_id' => 31,
                    'title' => 'Impossible 5h study block before quiz',
                    'requested_minutes' => 240,
                    'placed_minutes' => 240,
                    'reason' => 'partial_fit',
                ]],
                'summary' => 'test',
                'suppress_bulk_unplaced_narrative' => true,
            ],
        ]);

        $this->assertStringNotContainsString('planning horizon', $out);
        $this->assertStringContainsString('I scheduled Impossible 5h study block before quiz', $out);
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

    public function test_daily_schedule_confirmation_message_uses_payload_narrative_without_static_robotic_prefix(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'confirmation_required' => true,
            'framing' => 'I drafted a plan for today and paused so you can choose next.',
            'reasoning' => 'Only one task fit in this window, so I need your decision before finalizing.',
            'confirmation' => 'Should I keep this draft or try a wider window?',
            'confirmation_context' => [
                'reason_code' => 'top_n_shortfall',
                'requested_count' => 3,
                'placed_count' => 1,
                'reason_message' => 'Only one task fit in your current window.',
                'prompt' => 'Should I keep this draft or try a wider window?',
                'options' => [
                    'Continue with that plan',
                    'Try another time window',
                ],
            ],
            'fallback_preview' => [
                'proposals_count' => 1,
            ],
            'items' => [[
                'title' => 'Wash dishes after dinner',
                'entity_type' => 'task',
                'entity_id' => 21,
                'start_datetime' => '2026-04-18T20:50:56+08:00',
                'end_datetime' => '2026-04-18T21:10:56+08:00',
            ]],
            'blocks' => [[
                'start_time' => '20:50',
                'end_time' => '21:10',
            ]],
        ]);

        $this->assertStringContainsString('I drafted a plan for today and paused so you can choose next.', $out);
        $this->assertStringContainsString('Here is what I can schedule now (1 of 3):', $out);
        $this->assertStringNotContainsString('Options:', $out);
        $this->assertStringNotContainsString('1) Continue with that plan', $out);
        $this->assertStringNotContainsString('2) Try another time window', $out);
        $this->assertStringNotContainsString('Decision needed before finalizing:', $out);
        $this->assertIsInt(strpos($out, 'Here is what I can schedule now (1 of 3):'));
        $this->assertIsInt(strpos($out, 'Wash dishes after dinner'));
        $this->assertIsInt(strpos($out, 'Only one task fit in your current window.'));
        $this->assertGreaterThan(
            strpos($out, 'Only one task fit in your current window.'),
            strpos($out, 'Here is what I can schedule now (1 of 3):')
        );
    }

    public function test_daily_schedule_confirmation_message_renders_reason_details_and_sanitizes_robotic_phrases(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'confirmation_required' => true,
            'framing' => 'I checked your request and paused before finalizing.',
            'confirmation_context' => [
                'reason_message' => 'Confidence: 0 of 1 open time slots were available.',
                'prompt' => 'Should I try tomorrow morning instead?',
                'options' => [
                    'Use this draft',
                    'Pick another time window',
                ],
                'reason_details' => [
                    'It is already late, and the remaining free blocks are too short for this task duration.',
                ],
            ],
            'fallback_preview' => [
                'proposals_count' => 0,
            ],
            'items' => [],
            'blocks' => [],
        ]);

        $this->assertStringNotContainsString('Confidence:', $out);
        $this->assertStringContainsString('What got in the way:', $out);
        $this->assertStringContainsString('• It is already late, and the remaining free blocks are too short for this task duration.', $out);
    }

    public function test_daily_schedule_confirmation_message_strips_pending_schedule_prefix_from_blocking_rows(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'confirmation_required' => true,
            'framing' => 'I drafted what can fit now.',
            'confirmation_context' => [
                'reason_message' => 'Only one task fits in this window.',
                'prompt' => 'Do you want to keep this draft?',
            ],
            'blocking_reasons' => [
                [
                    'title' => 'pending_schedule: Task A',
                    'blocked_window' => '5:45 PM-6:45 PM',
                    'reason' => 'This event overlaps your requested time window.',
                ],
            ],
            'fallback_preview' => [
                'proposals_count' => 0,
            ],
            'items' => [],
            'blocks' => [],
        ]);

        $this->assertStringNotContainsString('pending_schedule:', $out);
        $this->assertStringContainsString('• Task A (5:45 PM-6:45 PM)', $out);
    }

    public function test_daily_schedule_narrative_date_mentions_align_to_actual_item_date(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'framing' => 'I scheduled these for April 20th.',
            'reasoning' => 'This plan for April 20 should help you stay consistent.',
            'confirmation' => 'Do these April 20 times work for you?',
            'blocks' => [[
                'start_time' => '08:00',
                'end_time' => '09:00',
                'task_id' => 1,
                'event_id' => null,
                'label' => 'Focus block',
                'note' => 'Planned by strict scheduler.',
            ]],
            'items' => [[
                'title' => 'Focus block',
                'entity_type' => 'task',
                'entity_id' => 1,
                'start_datetime' => '2026-04-19T08:00:00+08:00',
                'end_datetime' => '2026-04-19T09:00:00+08:00',
                'duration_minutes' => 60,
            ]],
        ]);

        $this->assertStringContainsString('Apr 19, 2026', $out);
        $this->assertStringContainsString('April 20', $out);
    }

    public function test_daily_schedule_does_not_rewrite_explicit_date_without_relative_date_phrasing(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'framing' => 'I scheduled this on April 20, 2026 based on your request.',
            'reasoning' => 'This date gives you room before your exam.',
            'confirmation' => 'Do you want to keep April 20, 2026?',
            'blocks' => [[
                'start_time' => '08:00',
                'end_time' => '09:00',
                'task_id' => 1,
            ]],
            'items' => [[
                'title' => 'Focus block',
                'entity_type' => 'task',
                'entity_id' => 1,
                'start_datetime' => '2026-04-19T08:00:00+08:00',
                'end_datetime' => '2026-04-19T09:00:00+08:00',
                'duration_minutes' => 60,
            ]],
        ]);

        $this->assertStringContainsString('April 20, 2026', $out);
    }

    public function test_daily_schedule_soft_correction_rewrites_today_claim_for_multi_day_plan(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'framing' => 'Here is how 3 pieces land in your open window today.',
            'reasoning' => 'This keeps you steady today.',
            'confirmation' => 'Do these times work today?',
            'blocks' => [
                ['start_time' => '08:00', 'end_time' => '08:30'],
                ['start_time' => '15:00', 'end_time' => '17:00'],
            ],
            'items' => [
                [
                    'title' => 'Task A',
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'start_datetime' => '2026-04-22T08:00:00+08:00',
                    'end_datetime' => '2026-04-22T08:30:00+08:00',
                    'duration_minutes' => 30,
                ],
                [
                    'title' => 'Task B',
                    'entity_type' => 'task',
                    'entity_id' => 2,
                    'start_datetime' => '2026-04-24T15:00:00+08:00',
                    'end_datetime' => '2026-04-24T17:00:00+08:00',
                    'duration_minutes' => 120,
                ],
            ],
        ]);

        $this->assertStringNotContainsString('open window today', $out);
        $this->assertStringContainsString('across Apr 22, 2026 to Apr 24, 2026', $out);
        $this->assertStringNotContainsString('work today?', $out);
    }

    public function test_daily_schedule_soft_correction_aligns_evening_claim_to_dominant_daypart(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'framing' => 'I planned your evening blocks.',
            'reasoning' => 'Evening focus should keep this manageable.',
            'confirmation' => 'Do these evening times work for you?',
            'blocks' => [[
                'start_time' => '08:00',
                'end_time' => '09:00',
            ]],
            'items' => [[
                'title' => 'Task A',
                'entity_type' => 'task',
                'entity_id' => 1,
                'start_datetime' => '2026-04-22T08:00:00+08:00',
                'end_datetime' => '2026-04-22T09:00:00+08:00',
                'duration_minutes' => 60,
            ]],
        ]);

        $this->assertStringNotContainsString('evening', mb_strtolower($out));
        $this->assertStringContainsString('morning', mb_strtolower($out));
    }

    public function test_daily_schedule_start_daypart_claim_aligns_to_first_slot_instead_of_dominant_distribution(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'framing' => 'I proposed a morning start because it is the earliest clean opening in your schedule.',
            'reasoning' => 'I proposed this at 10:20 AM because it is the closest open slot that fits your requested scope.',
            'confirmation' => 'Do these times feel workable, or should I move them earlier/later before you confirm?',
            'blocks' => [
                ['start_time' => '10:20', 'end_time' => '11:30'],
                ['start_time' => '13:05', 'end_time' => '14:15'],
                ['start_time' => '14:33', 'end_time' => '15:43'],
            ],
            'items' => [
                [
                    'title' => 'Task A',
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'start_datetime' => '2026-05-01T10:20:00+08:00',
                    'end_datetime' => '2026-05-01T11:30:00+08:00',
                    'duration_minutes' => 70,
                ],
                [
                    'title' => 'Task B',
                    'entity_type' => 'task',
                    'entity_id' => 2,
                    'start_datetime' => '2026-05-01T13:05:00+08:00',
                    'end_datetime' => '2026-05-01T14:15:00+08:00',
                    'duration_minutes' => 70,
                ],
                [
                    'title' => 'Task C',
                    'entity_type' => 'task',
                    'entity_id' => 3,
                    'start_datetime' => '2026-05-01T14:33:00+08:00',
                    'end_datetime' => '2026-05-01T15:43:00+08:00',
                    'duration_minutes' => 70,
                ],
            ],
        ]);

        $this->assertStringContainsString('morning start', mb_strtolower($out));
        $this->assertStringNotContainsString('afternoon start', mb_strtolower($out));
    }

    public function test_daily_schedule_renders_prose_explainability_without_why_heading_or_bullets(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'proposals' => [[
                'proposal_id' => 'p1',
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => 1,
                'title' => 'Math worksheet',
                'start_datetime' => '2026-04-04T09:00:00+00:00',
                'end_datetime' => '2026-04-04T09:45:00+00:00',
                'duration_minutes' => 45,
            ]],
            'items' => [[
                'title' => 'Math worksheet',
                'entity_type' => 'task',
                'entity_id' => 1,
                'start_datetime' => '2026-04-04T09:00:00+00:00',
                'end_datetime' => '2026-04-04T09:45:00+00:00',
                'duration_minutes' => 45,
            ]],
            'blocks' => [[
                'start_time' => '09:00',
                'end_time' => '09:45',
                'label' => 'Math worksheet',
            ]],
            'framing' => 'I mapped a plan for your morning.',
            'reasoning' => 'This keeps your highest-impact work early.',
            'confirmation' => 'Do these times work for you?',
            'window_selection_explanation' => 'I used your morning window first.',
            'ordering_rationale' => ['#1 Math worksheet: early slot keeps momentum.'],
            'requested_horizon_label' => 'tomorrow',
            'requested_window_display_label' => 'tomorrow',
            'blocking_reasons' => [[
                'title' => 'Chemistry lab',
                'blocked_window' => '9:30 AM-11:00 AM',
                'reason' => 'This overlaps your requested slot.',
            ]],
            'fallback_choice_explanation' => 'I kept this as the closest feasible fit.',
        ]);

        $this->assertStringNotContainsString('Why this plan:', $out);
        $this->assertStringNotContainsString('I used your morning window first.', $out);
        $this->assertStringContainsString('I kept this as the closest feasible fit.', $out);
        $this->assertStringNotContainsString('• I used your morning window first.', $out);
        $this->assertStringNotContainsString('• I kept this as the closest feasible fit.', $out);
        $this->assertStringNotContainsString('These items are already scheduled for tomorrow:', $out);
        $this->assertStringNotContainsString('Chemistry lab (9:30 AM-11:00 AM)', $out);
        $this->assertStringNotContainsString('#1 Math worksheet: early slot keeps momentum.', $out);
    }

    public function test_daily_schedule_uses_structured_explainability_as_sentence_chain_when_legacy_text_fields_are_empty(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'proposals' => [[
                'proposal_id' => 'p1',
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => 1,
                'title' => 'Math worksheet',
                'start_datetime' => '2026-04-04T09:00:00+00:00',
                'end_datetime' => '2026-04-04T09:45:00+00:00',
                'duration_minutes' => 45,
            ]],
            'items' => [[
                'title' => 'Math worksheet',
                'entity_type' => 'task',
                'entity_id' => 1,
                'start_datetime' => '2026-04-04T09:00:00+00:00',
                'end_datetime' => '2026-04-04T09:45:00+00:00',
                'duration_minutes' => 45,
            ]],
            'blocks' => [[
                'start_time' => '09:00',
                'end_time' => '09:45',
                'label' => 'Math worksheet',
            ]],
            'framing' => 'I mapped a plan for your morning.',
            'reasoning' => 'This keeps your highest-impact work early.',
            'confirmation' => 'Do these times work for you?',
            'window_selection_explanation' => '',
            'window_selection_struct' => [
                'window_mode' => 'requested_window',
                'reason_code_primary' => 'window_matched_request',
            ],
            'ordering_rationale' => [],
            'ordering_rationale_struct' => [[
                'rank' => 1,
                'title' => 'Math worksheet',
                'fit_reason_code' => 'strongest_fit_window',
            ]],
            'blocking_reasons' => [],
            'blocking_reasons_struct' => [[
                'title' => 'Chemistry lab',
                'blocked_window' => '9:30 AM-11:00 AM',
                'block_reason_code' => 'window_conflict',
            ]],
        ]);

        $this->assertStringNotContainsString('Why this plan:', $out);
        $this->assertStringNotContainsString('I kept this plan aligned with the availability window you asked for.', $out);
        $this->assertStringNotContainsString('These items are already scheduled in your requested window:', $out);
        $this->assertStringNotContainsString('Chemistry lab (9:30 AM-11:00 AM)', $out);
        $this->assertStringNotContainsString('placed in the strongest fit window', $out);
    }

    public function test_listing_followup_normalizes_display_only_title_spacing_in_bullet_rows(): void
    {
        $out = $this->formatter->format('listing_followup', [
            'verdict' => 'partial',
            'framing' => 'Some items match, but not all.',
            'rationale' => 'A few other tasks are currently more urgent.',
            'next_options' => 'If you want, I can show your latest ranked list.',
            'compared_items' => [[
                'entity_type' => 'task',
                'entity_id' => 1,
                'title' => 'STATIC AND DYNAMIC RESUME WEBSITE-  FINAL EXAM PROJECT',
            ]],
            'more_urgent_alternatives' => [[
                'entity_type' => 'task',
                'entity_id' => 2,
                'title' => 'IS THE DIFFERENCE REALLY SIGNIFICANT? -  FINAL EXAM -',
                'reason_short' => 'Due sooner.',
            ]],
        ]);

        $this->assertStringContainsString('WEBSITE- FINAL', $out);
        $this->assertStringContainsString('SIGNIFICANT? - FINAL EXAM -', $out);
        $this->assertStringNotContainsString('WEBSITE-  FINAL', $out);
        $this->assertStringNotContainsString('-  FINAL', $out);
    }

    public function test_daily_schedule_prioritize_schedule_success_adds_prioritized_lead_line(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'schedule_source' => 'prioritize_schedule',
            'proposals' => [[
                'proposal_id' => 'p1',
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => 1,
                'title' => 'Task A',
                'start_datetime' => '2026-04-23T08:00:00+08:00',
                'end_datetime' => '2026-04-23T09:00:00+08:00',
                'duration_minutes' => 60,
            ]],
            'items' => [[
                'title' => 'Task A',
                'entity_type' => 'task',
                'entity_id' => 1,
                'start_datetime' => '2026-04-23T08:00:00+08:00',
                'end_datetime' => '2026-04-23T09:00:00+08:00',
                'duration_minutes' => 60,
            ]],
            'blocks' => [[
                'start_time' => '08:00',
                'end_time' => '09:00',
                'label' => 'Task A',
            ]],
            'framing' => 'Here is your plan.',
            'reasoning' => 'I spread placements across 2026-04-22 to 2026-04-24 when needed.',
            'confirmation' => 'Do these times work?',
            'window_selection_explanation' => 'I used your requested range first.',
            'ordering_rationale' => ['#1 Task A: placed at Apr 23 8:00 AM as one of the strongest fit windows.'],
            'blocking_reasons' => [[
                'title' => 'Class',
                'blocked_window' => '6:45 AM-10:15 AM',
            ]],
            'prioritize_selection_explanation' => [
                'enabled' => true,
                'target_mode' => 'implicit_ranked',
                'selected_count' => 1,
                'summary' => 'I picked this task first because it stood out most clearly in your current priorities before I placed it into a time block.',
                'selection_basis' => 'Urgency leads, then explicit priority and earlier deadlines. When tasks are otherwise close, shorter blocks can help break the tie.',
                'ordering_rationale' => ['#1 Task A: due today and marked high priority.'],
            ],
        ]);

        $this->assertStringNotContainsString('Here are your prioritized items, placed into schedule blocks:', $out);
        $this->assertStringNotContainsString('Apr 22 to Apr 24', $out);
        $this->assertStringNotContainsString('#1 Task A', $out);
        $this->assertStringNotContainsString('These items are already scheduled', $out);
    }

    public function test_daily_schedule_prioritize_schedule_renders_selection_explanation_before_scheduled_items(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'schedule_source' => 'prioritize_schedule',
            'proposals' => [[
                'proposal_id' => 'p1',
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => 1,
                'title' => 'Task A',
                'start_datetime' => '2026-04-23T08:00:00+08:00',
                'end_datetime' => '2026-04-23T09:00:00+08:00',
                'duration_minutes' => 60,
            ]],
            'items' => [[
                'title' => 'Task A',
                'entity_type' => 'task',
                'entity_id' => 1,
                'start_datetime' => '2026-04-23T08:00:00+08:00',
                'end_datetime' => '2026-04-23T09:00:00+08:00',
                'duration_minutes' => 60,
            ]],
            'blocks' => [[
                'start_time' => '08:00',
                'end_time' => '09:00',
                'label' => 'Task A',
            ]],
            'framing' => 'Here is your plan.',
            'reasoning' => 'I placed this task into a conflict-free window that fits the rest of your schedule.',
            'confirmation' => 'Do these times work?',
            'window_selection_explanation' => 'I used your requested range first.',
            'ordering_rationale' => ['#1 Task A: placed at Apr 23 8:00 AM as one of the strongest fit windows.'],
            'blocking_reasons' => [],
            'prioritize_selection_explanation' => [
                'enabled' => true,
                'target_mode' => 'implicit_ranked',
                'selected_count' => 1,
                'summary' => 'I picked this task first because it stood out most clearly in your current priorities before I placed it into a time block.',
                'selection_basis' => 'Urgency leads, then explicit priority and earlier deadlines. When tasks are otherwise close, shorter blocks can help break the tie.',
                'ordering_rationale' => ['#1 Task A: due today and marked high priority.'],
            ],
        ]);

        $posSelection = strpos($out, 'I picked this task first because it stood out most clearly in your current priorities before I placed it into a time block.');
        $posScheduledRow = strpos($out, '• Task A —');
        $this->assertNotFalse($posSelection);
        $this->assertNotFalse($posScheduledRow);
        $this->assertLessThan($posScheduledRow, $posSelection);
        $this->assertStringNotContainsString('Here are your prioritized items, placed into schedule blocks:', $out);
        $this->assertStringNotContainsString('• #1 Task A: due today and marked high priority.', $out);
    }

    public function test_daily_schedule_prioritize_schedule_does_not_render_selection_explanation_when_absent(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'schedule_source' => 'prioritize_schedule',
            'proposals' => [[
                'proposal_id' => 'p1',
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => 1,
                'title' => 'Task A',
                'start_datetime' => '2026-04-23T08:00:00+08:00',
                'end_datetime' => '2026-04-23T09:00:00+08:00',
                'duration_minutes' => 60,
            ]],
            'items' => [[
                'title' => 'Task A',
                'entity_type' => 'task',
                'entity_id' => 1,
                'start_datetime' => '2026-04-23T08:00:00+08:00',
                'end_datetime' => '2026-04-23T09:00:00+08:00',
                'duration_minutes' => 60,
            ]],
            'blocks' => [[
                'start_time' => '08:00',
                'end_time' => '09:00',
                'label' => 'Task A',
            ]],
            'framing' => 'Here is your plan.',
            'reasoning' => 'I placed this task into a conflict-free window that fits the rest of your schedule.',
            'confirmation' => 'Do these times work?',
            'window_selection_explanation' => 'I used your requested range first.',
            'ordering_rationale' => ['#1 Task A: placed at Apr 23 8:00 AM as one of the strongest fit windows.'],
            'blocking_reasons' => [],
        ]);

        $this->assertStringNotContainsString('I picked this task first because it stood out most clearly in your current priorities before I placed it into a time block.', $out);
        $this->assertStringContainsString('Here are your prioritized items, placed into schedule blocks:', $out);
    }

    public function test_daily_schedule_multi_item_reasoning_uses_set_level_language(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'schedule_source' => 'schedule',
            'proposals' => [
                [
                    'proposal_id' => 'p1',
                    'status' => 'pending',
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'title' => 'Task A',
                    'start_datetime' => '2026-04-29T10:15:00+08:00',
                    'end_datetime' => '2026-04-29T11:15:00+08:00',
                    'duration_minutes' => 60,
                ],
                [
                    'proposal_id' => 'p2',
                    'status' => 'pending',
                    'entity_type' => 'task',
                    'entity_id' => 2,
                    'title' => 'Task B',
                    'start_datetime' => '2026-04-29T13:00:00+08:00',
                    'end_datetime' => '2026-04-29T14:00:00+08:00',
                    'duration_minutes' => 60,
                ],
            ],
            'items' => [
                [
                    'title' => 'Task A',
                    'entity_type' => 'task',
                    'entity_id' => 1,
                    'start_datetime' => '2026-04-29T10:15:00+08:00',
                    'end_datetime' => '2026-04-29T11:15:00+08:00',
                    'duration_minutes' => 60,
                ],
                [
                    'title' => 'Task B',
                    'entity_type' => 'task',
                    'entity_id' => 2,
                    'start_datetime' => '2026-04-29T13:00:00+08:00',
                    'end_datetime' => '2026-04-29T14:00:00+08:00',
                    'duration_minutes' => 60,
                ],
            ],
            'blocks' => [
                ['start_time' => '10:15', 'end_time' => '11:15', 'label' => 'Task A'],
                ['start_time' => '13:00', 'end_time' => '14:00', 'label' => 'Task B'],
            ],
            'framing' => 'I suggested moving this to the next conflict-free slot.',
            'reasoning' => 'I proposed this at 10:15 AM.',
            'confirmation' => 'Do these times look workable, or should I shift earlier/later before you confirm?',
            'window_selection_explanation' => 'I kept this plan aligned with the availability window you asked for.',
        ]);

        $this->assertStringNotContainsString('moving this to the next conflict-free slot', $out);
        $this->assertStringNotContainsString('I proposed this at 10:15 AM.', $out);
        $this->assertStringContainsString('I suggested the next conflict-free slots that fit this plan', $out);
        $this->assertStringContainsString('I proposed this plan starting at 10:15 AM.', $out);
        $this->assertStringContainsString('shift some of these blocks earlier or later before you confirm', $out);
    }

    public function test_daily_schedule_targeted_copy_prefers_requested_horizon_over_inferred_daypart(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'schedule_source' => 'targeted_schedule',
            'proposals' => [[
                'proposal_id' => 'p1',
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => 1,
                'title' => 'Task A',
                'start_datetime' => '2026-04-28T18:15:00+08:00',
                'end_datetime' => '2026-04-28T19:15:00+08:00',
                'duration_minutes' => 60,
            ]],
            'items' => [[
                'title' => 'Task A',
                'entity_type' => 'task',
                'entity_id' => 1,
                'start_datetime' => '2026-04-28T18:15:00+08:00',
                'end_datetime' => '2026-04-28T19:15:00+08:00',
                'duration_minutes' => 60,
            ]],
            'blocks' => [[
                'start_time' => '18:15',
                'end_time' => '19:15',
                'label' => 'Task A',
            ]],
            'framing' => 'I scheduled Task A for today at 6:15 PM.',
            'reasoning' => 'This slot at 6:15 PM gives Task A a focused block that is realistic for the rest of your day.',
            'confirmation' => 'Do you want to keep Task A at 6:15 PM, or shift it earlier/later?',
            'window_selection_explanation' => 'I kept this plan aligned with the availability window you asked for.',
            'requested_horizon_label' => 'today',
            'requested_window_display_label' => 'today',
        ]);

        $this->assertStringContainsString('I proposed Task A for today at 6:15 PM.', $out);
        $this->assertStringNotContainsString('in your evening window', $out);
    }

    public function test_daily_schedule_focus_history_explanation_renders_as_separate_paragraph(): void
    {
        $firstParagraph = 'This slot at 4:30 PM gives TIME SERIES, KPI CARDS, HISTOGRAMS - ACTIVITY - a focused block that is realistic for the rest of your day. Use a short reset before this block so you can re-focus quickly. Keep one small next step ready right after this slot to maintain momentum. An afternoon block is a practical restart window after earlier commitments.';
        $focusHistoryParagraph = 'Based on your recent focus-session history, I leaned toward this timing because your recent focus window clusters around 10:20-20:25 (from 12 recent focus sessions).';

        $out = $this->formatter->format('daily_schedule', [
            'schedule_source' => 'targeted_schedule',
            'proposals' => [[
                'proposal_id' => 'p1',
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => 1,
                'title' => 'TIME SERIES, KPI CARDS, HISTOGRAMS - ACTIVITY -',
                'start_datetime' => '2026-04-30T16:30:00+08:00',
                'end_datetime' => '2026-04-30T17:40:00+08:00',
                'duration_minutes' => 70,
            ]],
            'items' => [[
                'title' => 'TIME SERIES, KPI CARDS, HISTOGRAMS - ACTIVITY -',
                'entity_type' => 'task',
                'entity_id' => 1,
                'start_datetime' => '2026-04-30T16:30:00+08:00',
                'end_datetime' => '2026-04-30T17:40:00+08:00',
                'duration_minutes' => 70,
            ]],
            'blocks' => [[
                'start_time' => '16:30',
                'end_time' => '17:40',
                'label' => 'TIME SERIES, KPI CARDS, HISTOGRAMS - ACTIVITY -',
                'task_id' => 1,
            ]],
            'framing' => 'I proposed TIME SERIES, KPI CARDS, HISTOGRAMS - ACTIVITY - for this week at 4:30 PM.',
            'reasoning' => $firstParagraph,
            'focus_history_window_explanation' => $focusHistoryParagraph,
            'confirmation' => 'Do you want to keep this time or shift it earlier/later?',
        ]);

        $this->assertStringContainsString($firstParagraph, $out);
        $this->assertStringContainsString($focusHistoryParagraph, $out);
        $this->assertStringContainsString($firstParagraph."\n\n".$focusHistoryParagraph, $out);
    }

    public function test_prioritize_framing_normalization_does_not_leave_dangling_it_is_fragment(): void
    {
        $out = $this->formatter->format('prioritize', [
            'items' => [[
                'entity_type' => 'task',
                'entity_id' => 1,
                'title' => 'Task A',
                'priority' => 'high',
                'due_phrase' => 'due today',
                'due_on' => 'Apr 28, 2026',
                'complexity_label' => 'Complex',
            ]],
            'limit_used' => 1,
            'focus' => [
                'main_task' => 'Task A',
                'secondary_tasks' => [],
            ],
            'framing' => 'For what to do first, I’d look at the item below—it’s ordered by urgency and your deadlines.',
            'reasoning' => 'Start with Task A first.',
            'next_options' => 'If you want, I can schedule this next step.',
            'next_options_chip_texts' => [
                'Schedule this next step',
            ],
        ]);

        $this->assertStringNotContainsString('it’s .', $out);
        $this->assertStringNotContainsString('it’s.', $out);
        $this->assertStringNotContainsString('it’s —', $out);
        $this->assertStringNotContainsString('it’s –', $out);
    }

    public function test_daily_schedule_implicit_shortfall_uses_light_digest_note(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'schedule_source' => 'prioritize_schedule',
            'proposals' => [[
                'proposal_id' => 'p1',
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => 1,
                'title' => 'Task A',
                'start_datetime' => '2026-04-23T18:30:00+00:00',
                'end_datetime' => '2026-04-23T19:30:00+00:00',
            ]],
            'items' => [[
                'title' => 'Task A',
                'entity_type' => 'task',
                'entity_id' => 1,
                'start_datetime' => '2026-04-23T18:30:00+00:00',
                'end_datetime' => '2026-04-23T19:30:00+00:00',
                'duration_minutes' => 60,
            ]],
            'blocks' => [[
                'start_time' => '18:30',
                'end_time' => '19:30',
                'label' => 'Task A',
            ]],
            'framing' => 'I queued what fit for later today.',
            'reasoning' => 'I prioritized realistic windows.',
            'confirmation' => 'Does this work?',
            'placement_digest' => [
                'requested_count_source' => 'system_default',
                'unplaced_units' => [[
                    'entity_type' => 'task',
                    'entity_id' => 2,
                    'title' => 'Task B',
                    'reason' => 'horizon_exhausted',
                ]],
                'partial_units' => [],
                'days_used' => ['2026-04-23'],
            ],
        ]);

        $this->assertStringContainsString('I scheduled what fit in your requested window.', $out);
        $this->assertStringNotContainsString('One or more segments did not fit in the selected schedule window', $out);
    }

    public function test_daily_schedule_explicit_shortfall_keeps_standard_shortfall_note(): void
    {
        $out = $this->formatter->format('daily_schedule', [
            'schedule_source' => 'prioritize_schedule',
            'proposals' => [[
                'proposal_id' => 'p1',
                'status' => 'pending',
                'entity_type' => 'task',
                'entity_id' => 1,
                'title' => 'Task A',
                'start_datetime' => '2026-04-23T18:30:00+00:00',
                'end_datetime' => '2026-04-23T19:30:00+00:00',
            ]],
            'items' => [[
                'title' => 'Task A',
                'entity_type' => 'task',
                'entity_id' => 1,
                'start_datetime' => '2026-04-23T18:30:00+00:00',
                'end_datetime' => '2026-04-23T19:30:00+00:00',
                'duration_minutes' => 60,
            ]],
            'blocks' => [[
                'start_time' => '18:30',
                'end_time' => '19:30',
                'label' => 'Task A',
            ]],
            'framing' => 'I queued what fit for later today.',
            'reasoning' => 'I prioritized realistic windows.',
            'confirmation' => 'Does this work?',
            'placement_digest' => [
                'requested_count_source' => 'explicit_user',
                'unplaced_units' => [[
                    'entity_type' => 'task',
                    'entity_id' => 2,
                    'title' => 'Task B',
                    'reason' => 'horizon_exhausted',
                ]],
                'partial_units' => [],
                'days_used' => ['2026-04-23'],
            ],
        ]);

        $this->assertStringContainsString('One or more segments did not fit in the selected schedule window', $out);
    }
}
