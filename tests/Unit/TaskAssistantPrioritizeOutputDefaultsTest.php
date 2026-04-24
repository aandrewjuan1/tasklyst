<?php

namespace Tests\Unit;

use App\Support\LLM\TaskAssistantPrioritizeOutputDefaults;
use Tests\TestCase;

class TaskAssistantPrioritizeOutputDefaultsTest extends TestCase
{
    public function test_clamp_prioritize_reasoning_truncates_with_ellipsis(): void
    {
        config(['task-assistant.listing.max_reasoning_chars' => 10]);

        $out = TaskAssistantPrioritizeOutputDefaults::clampPrioritizeReasoning('123456789012345');

        $this->assertSame('123456789…', $out);
        $this->assertSame(10, mb_strlen($out));
    }

    public function test_clamp_framing_truncates_with_ellipsis(): void
    {
        // maxFramingChars = min(max_framing_chars, maxSuggestedGuidanceChars()), floored at 80.
        config([
            'task-assistant.listing.max_reasoning_chars' => 800,
            'task-assistant.listing.max_suggested_guidance_chars' => 50,
            'task-assistant.listing.max_framing_chars' => 80,
        ]);

        $out = TaskAssistantPrioritizeOutputDefaults::clampFraming(str_repeat('a', 120));

        $this->assertSame(80, mb_strlen($out));
        $this->assertSame(str_repeat('a', 79).'…', $out);
    }

    public function test_clamp_next_field_respects_next_field_max(): void
    {
        // maxNextFieldChars = min(320, maxReasoningChars()).
        config(['task-assistant.listing.max_reasoning_chars' => 10]);

        $out = TaskAssistantPrioritizeOutputDefaults::clampNextField(str_repeat('b', 50));

        $this->assertSame(10, mb_strlen($out));
        $this->assertSame(str_repeat('b', 9).'…', $out);
    }

    public function test_clamp_suggested_next_action_truncates_with_ellipsis(): void
    {
        $out = TaskAssistantPrioritizeOutputDefaults::clampSuggestedNextAction(str_repeat('c', 200));

        $this->assertSame(180, mb_strlen($out));
        $this->assertSame(str_repeat('c', 179).'…', $out);
    }

    public function test_clamp_next_option_chip_text_truncates_with_ellipsis(): void
    {
        $out = TaskAssistantPrioritizeOutputDefaults::clampNextOptionChipText(str_repeat('d', 200));

        $this->assertSame(120, mb_strlen($out));
        $this->assertSame(str_repeat('d', 119).'…', $out);
    }

    public function test_normalize_prioritize_reasoning_voice_rewrites_third_person_leak(): void
    {
        $items = [[
            'entity_type' => 'task',
            'title' => 'Prepare tomorrow’s school bag',
            'due_phrase' => 'overdue',
            'priority' => 'medium',
        ]];

        $in = 'These overdue tasks have a medium priority level due by today. They match the user\'s current state of feeling overwhelmed and provide actionable next steps.';

        $out = TaskAssistantPrioritizeOutputDefaults::normalizePrioritizeReasoningVoice($in, $items);

        $this->assertStringStartsWith('I chose this task because', $out);
        $this->assertStringNotContainsString('They match', $out);
        $this->assertStringNotContainsString('user\'s current state', mb_strtolower($out));
        $this->assertStringContainsString('overdue', $out);
        $this->assertStringContainsString('Medium', $out);
    }

    public function test_normalize_prioritize_reasoning_voice_keeps_first_person_when_clean(): void
    {
        $items = [[
            'entity_type' => 'task',
            'title' => 'Alpha',
            'due_phrase' => 'overdue',
            'priority' => 'medium',
        ]];

        $in = 'I chose these priorities because you have overdue tasks that help you get started with manageable next steps.';
        $out = TaskAssistantPrioritizeOutputDefaults::normalizePrioritizeReasoningVoice($in, $items);

        $this->assertSame($in, $out);
    }

    public function test_normalize_prioritize_reasoning_voice_keeps_lets_and_we_when_grounded(): void
    {
        $items = [[
            'entity_type' => 'task',
            'title' => 'Alpha',
            'due_phrase' => 'overdue',
            'priority' => 'medium',
        ]];

        $in = 'Let\'s knock out the overdue work first—you\'ll feel lighter once Alpha moves forward.';
        $out = TaskAssistantPrioritizeOutputDefaults::normalizePrioritizeReasoningVoice($in, $items);

        $this->assertSame($in, $out);
    }

    public function test_normalize_prioritize_reasoning_voice_rewrites_when_due_or_priority_drift_conflicts_items(): void
    {
        $items = [[
            'entity_type' => 'task',
            'title' => 'Prepare tomorrow’s school bag',
            'due_phrase' => 'overdue',
            'priority' => 'medium',
        ]];

        $in = 'I chose these priorities because they are due today and high priority.';
        $out = TaskAssistantPrioritizeOutputDefaults::normalizePrioritizeReasoningVoice($in, $items);

        $this->assertStringStartsWith('I chose this task because', $out);
        $this->assertStringContainsString('overdue', mb_strtolower($out));
        $this->assertStringContainsString('Medium', $out);
        $this->assertStringNotContainsString('due today', mb_strtolower($out));
        $this->assertStringNotContainsString('high priority', mb_strtolower($out));
    }

    public function test_max_reasoning_chars_reads_config(): void
    {
        config(['task-assistant.listing.max_reasoning_chars' => 400]);

        $this->assertSame(400, TaskAssistantPrioritizeOutputDefaults::maxReasoningChars());
    }

    public function test_max_framing_chars_reads_config_and_caps_to_suggested_guidance(): void
    {
        config([
            'task-assistant.listing.max_framing_chars' => 2000,
            'task-assistant.listing.max_suggested_guidance_chars' => 500,
        ]);

        $this->assertSame(500, TaskAssistantPrioritizeOutputDefaults::maxFramingChars());
    }

    public function test_coerce_singular_prioritize_narrative_only_when_one_item(): void
    {
        $items = [[
            'entity_type' => 'task',
            'entity_id' => 31,
            'title' => 'Impossible 5h study block before quiz',
        ]];

        $in = 'I recommend focusing on these top priorities first. These high-priority tasks that are already overdue will help you get a head start, even though they have some time left.';
        $out = TaskAssistantPrioritizeOutputDefaults::coerceSingularPrioritizeNarrative($in, 1, $items);

        $this->assertStringContainsString('this top priority', mb_strtolower($out));
        $this->assertStringContainsString('high-priority task that is', mb_strtolower($out));
        $this->assertStringContainsString('it has some time left', mb_strtolower($out));
        $this->assertStringNotContainsString('these top priorities', mb_strtolower($out));

        $unchanged = TaskAssistantPrioritizeOutputDefaults::coerceSingularPrioritizeNarrative($in, 2, $items);
        $this->assertSame($in, $unchanged);
    }

    public function test_coerce_singular_prioritize_narrative_uses_event_nouns(): void
    {
        $items = [[
            'entity_type' => 'event',
            'entity_id' => 5,
            'title' => 'Team sync',
        ]];

        $in = 'These events are the next focus.';
        $out = TaskAssistantPrioritizeOutputDefaults::coerceSingularPrioritizeNarrative($in, 1, $items);

        $this->assertSame('This event is the next focus.', $out);
    }

    public function test_strip_robotic_prioritize_reasoning_tail_removes_legacy_anchor_paragraph(): void
    {
        $body = 'You should tackle the urgent work first.';
        $tail = "\n\nStart with Impossible 5h study block before quiz when you're ready—it's first on this ordered list.";
        $out = TaskAssistantPrioritizeOutputDefaults::stripRoboticPrioritizeReasoningTail($body.$tail);

        $this->assertSame($body, $out);
        $this->assertStringNotContainsString('ordered list', $out);
    }

    public function test_build_doing_progress_coach_returns_null_when_no_tasks(): void
    {
        $this->assertNull(TaskAssistantPrioritizeOutputDefaults::buildDoingProgressCoach([], 0));
        $this->assertNull(TaskAssistantPrioritizeOutputDefaults::buildDoingProgressCoach(['A'], 0));
    }

    public function test_build_doing_progress_coach_single_title(): void
    {
        $out = TaskAssistantPrioritizeOutputDefaults::buildDoingProgressCoach(['Read chapter 3'], 1);

        $this->assertIsString($out);
        $this->assertStringContainsString('Read chapter 3', (string) $out);
        $this->assertLessThanOrEqual(TaskAssistantPrioritizeOutputDefaults::maxDoingProgressCoachChars(), mb_strlen((string) $out));
    }

    public function test_build_doing_progress_coach_motivation_fallback_is_title_free(): void
    {
        $one = TaskAssistantPrioritizeOutputDefaults::buildDoingProgressCoachMotivationFallback(1);
        $this->assertIsString($one);
        $this->assertNotEmpty($one);

        $many = TaskAssistantPrioritizeOutputDefaults::buildDoingProgressCoachMotivationFallback(3);
        $this->assertIsString($many);
        $this->assertNotEmpty($many);
        $this->assertNull(TaskAssistantPrioritizeOutputDefaults::buildDoingProgressCoachMotivationFallback(0));
    }

    public function test_build_doing_progress_coach_lists_sample_and_more(): void
    {
        $titles = ['One', 'Two', 'Three', 'Four', 'Five'];
        $out = TaskAssistantPrioritizeOutputDefaults::buildDoingProgressCoach($titles, 5);

        $this->assertIsString($out);
        $this->assertStringContainsString('One', (string) $out);
        $this->assertStringContainsString('Two', (string) $out);
        $this->assertStringNotContainsString('Three', (string) $out);
        $this->assertStringContainsString('3 more', (string) $out);
    }

    public function test_dedupe_prioritize_filter_versus_framing_drops_near_duplicate(): void
    {
        $framing = 'Here is a short slice of your highest-ranked tasks so you can focus.';
        $filter = 'Here is a short slice of your highest ranked tasks so you can focus today.';

        $this->assertNull(TaskAssistantPrioritizeOutputDefaults::dedupePrioritizeFilterVersusFraming($filter, $framing));
    }

    public function test_dedupe_prioritize_reasoning_drops_sentences_that_repeat_framing(): void
    {
        $framing = 'I suggest starting with what is due soonest so you feel momentum.';
        $reasoning = 'I suggest starting with what is due soonest so you feel momentum. Alpha is due today, so it makes sense to open it first.';

        $out = TaskAssistantPrioritizeOutputDefaults::dedupePrioritizeReasoningVersusPriorFields(
            $reasoning,
            null,
            $framing,
            null
        );

        $this->assertStringContainsString('Alpha', $out);
        $this->assertStringNotContainsString('I suggest starting with what is due soonest', $out);
    }

    public function test_dedupe_prioritize_reasoning_drops_sentence_that_echoes_only_a_framing_sentence(): void
    {
        $framing = 'I\'d start with this task first. It\'s overdue and quite complex.';
        $reasoning = 'It\'s overdue and quite complex. Alpha needs a clear plan before you move on.';

        $out = TaskAssistantPrioritizeOutputDefaults::dedupePrioritizeReasoningVersusPriorFields(
            $reasoning,
            null,
            $framing,
            null,
            [
                ['entity_type' => 'task', 'entity_id' => 1, 'title' => 'Alpha'],
            ],
        );

        $this->assertStringContainsString('clear plan', mb_strtolower($out));
        $this->assertStringNotContainsString('It\'s overdue and quite complex.', $out);
    }

    public function test_sanitize_prioritize_framing_meta_voice_drops_discovery_opener(): void
    {
        $in = 'I understand that you\'ve found one top priority task on your list. This first task deserves our attention first because it\'s overdue.';
        $out = TaskAssistantPrioritizeOutputDefaults::sanitizePrioritizeFramingMetaVoice($in, 1);

        $this->assertStringNotContainsString('found one top priority', mb_strtolower($out));
        $this->assertDoesNotMatchRegularExpression('/\bour attention\b/u', $out);
        $this->assertStringContainsString('your attention', mb_strtolower($out));
        $this->assertStringContainsString('overdue', mb_strtolower($out));
    }

    public function test_sanitize_prioritize_framing_meta_voice_keeps_clean_framing(): void
    {
        $in = 'I\'d start with what\'s most overdue so you feel caught up sooner.';
        $out = TaskAssistantPrioritizeOutputDefaults::sanitizePrioritizeFramingMetaVoice($in, 1);

        $this->assertSame($in, $out);
    }

    public function test_build_prioritize_narrative_coach_context_sets_dynamic_flags(): void
    {
        $items = [
            ['entity_type' => 'task', 'title' => 'Impossible 5h block'],
            ['entity_type' => 'event', 'title' => 'Meet'],
        ];
        $out = TaskAssistantPrioritizeOutputDefaults::buildPrioritizeNarrativeCoachContextBlock($items, 'rank');

        $this->assertStringContainsString('PRIORITIZE_VARIANT: rank', $out);
        $this->assertStringContainsString('MULTI_ROW: true', $out);
        $this->assertStringContainsString('SLICE_INCLUDES_EVENT: true', $out);
        $this->assertStringContainsString('TOP_TITLE_SUGGESTS_LARGE_BLOCK: true', $out);
        $this->assertStringContainsString('row 2 relates to row 1', $out);
    }

    public function test_build_prioritize_narrative_coach_context_suppresses_row_two_hint_when_requested(): void
    {
        $items = [
            ['entity_type' => 'task', 'title' => 'A'],
            ['entity_type' => 'task', 'title' => 'B'],
        ];
        $out = TaskAssistantPrioritizeOutputDefaults::buildPrioritizeNarrativeCoachContextBlock($items, 'rank', true);

        $this->assertStringNotContainsString('row 2 relates to row 1', $out);
    }

    public function test_doing_progress_coach_leaks_ranked_slice_titles_detects_substring(): void
    {
        $items = [
            ['entity_type' => 'task', 'title' => 'CS group project meetup', 'entity_id' => 1],
        ];
        $this->assertTrue(TaskAssistantPrioritizeOutputDefaults::proseContainsAnyRankedItemTitle(
            'Progress on CS group project meetup is great.',
            $items
        ));
        $this->assertTrue(TaskAssistantPrioritizeOutputDefaults::doingProgressCoachLeaksRankedSliceTitles(
            'Progress on CS group project meetup is great.',
            $items
        ));
        $this->assertFalse(TaskAssistantPrioritizeOutputDefaults::doingProgressCoachLeaksRankedSliceTitles(
            'Lean on what you already started before adding more.',
            $items
        ));
    }

    public function test_refine_framing_when_doing_coexists_strips_sentences_with_ranked_titles(): void
    {
        $items = [
            ['entity_type' => 'task', 'title' => 'Impossible 5h study block before quiz', 'entity_id' => 1],
        ];
        $in = 'First on your list today is completing the Impossible 5h study block before the quiz. I know this may feel overwhelming, and you can work through it one step at a time.';
        $out = TaskAssistantPrioritizeOutputDefaults::refineFramingWhenDoingCoexistsAvoidRankedTitles(
            $in,
            $items,
            true,
            'test-seed'
        );

        $this->assertStringNotContainsString('Impossible 5h study block', $out);
        $this->assertStringContainsString('overwhelming', mb_strtolower($out));
    }

    public function test_refine_framing_when_doing_coexists_falls_back_when_only_title_sentence(): void
    {
        $items = [
            ['entity_type' => 'task', 'title' => 'Alpha long title for testing here', 'entity_id' => 1],
        ];
        $out = TaskAssistantPrioritizeOutputDefaults::refineFramingWhenDoingCoexistsAvoidRankedTitles(
            'Tackle Alpha long title for testing here before anything else.',
            $items,
            true,
            'fb-seed'
        );

        $this->assertStringNotContainsString('Alpha long title', $out);
        $this->assertStringContainsString('in motion', mb_strtolower($out));
    }

    public function test_refine_framing_when_doing_coexists_no_op_without_doing(): void
    {
        $items = [['entity_type' => 'task', 'title' => 'Alpha', 'entity_id' => 1]];
        $in = 'Start with Alpha first.';
        $out = TaskAssistantPrioritizeOutputDefaults::refineFramingWhenDoingCoexistsAvoidRankedTitles($in, $items, false, null);

        $this->assertSame($in, $out);
    }

    public function test_refine_framing_when_doing_drops_robotic_start_with_ranked_task_opener(): void
    {
        $items = [['entity_type' => 'task', 'title' => 'Impossible 5h study block before quiz', 'entity_id' => 31]];
        $in = 'Start with completing this important task first. You already have plenty in motion—steady those before you add more.';
        $out = TaskAssistantPrioritizeOutputDefaults::refineFramingWhenDoingCoexistsAvoidRankedTitles($in, $items, true, 'seed');

        $this->assertStringNotContainsString('Start with completing this important task first', $out);
        $this->assertStringContainsString('in motion', mb_strtolower($out));
    }

    public function test_refine_framing_when_doing_drops_started_working_even_when_title_is_paraphrased(): void
    {
        $items = [['entity_type' => 'task', 'title' => 'Impossible 5h study block before quiz', 'entity_id' => 31]];
        $in = 'I see that you\'ve started working on your important task about the complex Impossible study block before the quiz. Let\'s take a closer look at what your top priority is when you have a moment to focus.';
        $out = TaskAssistantPrioritizeOutputDefaults::refineFramingWhenDoingCoexistsAvoidRankedTitles($in, $items, true, 'paraphrase-seed');

        $this->assertStringNotContainsString('started working', mb_strtolower($out));
        $this->assertStringNotContainsString('I see that you', $out);
    }

    public function test_strip_reasoning_drops_programming_exercise_when_not_in_ranked_titles(): void
    {
        $items = [['entity_type' => 'task', 'entity_id' => 31, 'title' => 'Impossible 5h study block before quiz']];
        $in = 'Impossible 5h study block before quiz is the top row. It is a complex programming exercise before your quiz.';
        $out = TaskAssistantPrioritizeOutputDefaults::stripReasoningSentencesWithInventedStudyArtifacts($in, $items);
        $this->assertStringNotContainsString('programming exercise', mb_strtolower($out));
        $this->assertStringContainsString('Impossible', $out);
    }

    public function test_strip_reasoning_drops_bleeding_doing_titles_when_single_ranked_row(): void
    {
        $items = [['entity_type' => 'task', 'entity_id' => 31, 'title' => 'Impossible 5h study block before quiz']];
        $doing = ['CS 220 – Lab 5: Linked Lists'];
        $in = 'Impossible 5h study block before quiz is first by urgency. Even though the lab 5 stream for CS 220 also needs attention, start with row one.';
        $out = TaskAssistantPrioritizeOutputDefaults::stripReasoningSentencesEchoingDoingTitlesWhenSingleRankedRow($in, $items, $doing);
        $this->assertStringNotContainsString('CS 220', $out);
        $this->assertStringNotContainsString('lab 5', mb_strtolower($out));
        $this->assertStringContainsString('Impossible', $out);
    }

    public function test_refine_framing_premature_deictic_strips_config_patterns_when_doing_and_ranked(): void
    {
        config(['task-assistant.listing.prioritize_framing_premature_deictic_sentence_patterns' => [
            '/\bI think starting with this\b/iu',
        ]]);
        $items = [['entity_type' => 'task', 'entity_id' => 31, 'title' => 'Alpha']];
        $in = 'I think starting with this will help you settle. Breathe and take one small step toward calmer focus today.';
        $out = TaskAssistantPrioritizeOutputDefaults::refineFramingPrematureDeicticBeforeRankedList($in, $items, true, 'deictic-seed');
        $this->assertStringNotContainsString('I think starting with this', $out);
        $this->assertStringContainsString('Breathe', $out);
    }

    public function test_dedupe_framing_drops_sentence_too_similar_to_acknowledgment_when_both_themed(): void
    {
        config(['task-assistant.listing.prioritize_framing_ack_dedupe_sentence_jaccard' => 0.35]);
        $items = [['entity_type' => 'task', 'entity_id' => 1, 'title' => 'Study block']];
        $ack = 'I get that feeling stressed with quiz prep and a big pile of work is completely normal.';
        $framing = 'I get that feeling stressed with a big pile of work and quiz prep is completely normal for students.';
        $out = TaskAssistantPrioritizeOutputDefaults::dedupePrioritizeFramingVersusAcknowledgment($ack, $framing, $items, true, 'ack-dedupe-seed');
        $this->assertNotSame(trim($framing), trim($out));
    }

    public function test_filter_prioritize_assumptions_drops_denylisted_meta(): void
    {
        $out = TaskAssistantPrioritizeOutputDefaults::filterPrioritizeAssumptions([
            'You have already looked at your list of tasks.',
            'Treating today as your local calendar date.',
        ]);

        $this->assertIsArray($out);
        $this->assertCount(1, $out);
        $this->assertStringContainsString('calendar', $out[0]);
    }

    public function test_dedupe_prioritize_reasoning_drops_status_overlap_with_framing(): void
    {
        config()->set('task-assistant.listing.prioritize_reasoning_framing_status_overlap_jaccard', 0.35);

        $framing = 'I\'d start with fixing the Impossible 5h study block before quiz. It\'s overdue and complex, making it your top priority.';
        $reasoning = 'The Impossible 5h study block before quiz is significantly overdue and quite complex. Tackling this first will help you move forward.';
        $items = [
            [
                'entity_type' => 'task',
                'entity_id' => 31,
                'title' => 'Impossible 5h study block before quiz',
                'due_phrase' => 'overdue',
            ],
        ];

        $out = TaskAssistantPrioritizeOutputDefaults::dedupePrioritizeReasoningVersusPriorFields(
            $reasoning,
            null,
            $framing,
            null,
            $items
        );

        $this->assertStringNotContainsString('significantly overdue', mb_strtolower($out));
    }

    public function test_prioritize_formatter_bridge_after_uses_plural_for_multiple_items(): void
    {
        $plural = TaskAssistantPrioritizeOutputDefaults::prioritizeFormatterBridgeAfterDoingCoach(2);
        $singular = TaskAssistantPrioritizeOutputDefaults::prioritizeFormatterBridgeAfterDoingCoach(1);

        $this->assertStringContainsString('list below', mb_strtolower($plural));
        $this->assertStringContainsString('in order', mb_strtolower($plural));
        $this->assertStringContainsString('what you see below', mb_strtolower($singular));
        $this->assertStringContainsString('sharpest need', mb_strtolower($singular));
        $this->assertStringNotContainsString('to do', mb_strtolower($singular));
        $this->assertStringNotContainsString('to do', mb_strtolower($plural));
    }

    public function test_dedupe_prioritize_next_versus_prior_fields_replaces_echo(): void
    {
        $framing = 'Start with Alpha first because it is due today.';
        $reasoning = 'Alpha is the top row because of its due date.';
        $next = 'Start with Alpha first because it is due today and you can schedule later.';

        $out = TaskAssistantPrioritizeOutputDefaults::dedupePrioritizeNextVersusPriorFields($next, $framing, $reasoning, 2);

        $this->assertStringContainsString('schedule these next steps', mb_strtolower($out));
        $this->assertStringNotContainsString('Start with Alpha first', $out);
    }

    public function test_strip_reasoning_drops_ungrounded_about_domain_claims(): void
    {
        $items = [
            ['entity_type' => 'task', 'title' => 'Impossible 5h study block before quiz', 'entity_id' => 31],
        ];
        $in = 'Completing this task about web design will set you up well for the upcoming quiz. It is still your top priority.';
        $out = TaskAssistantPrioritizeOutputDefaults::stripReasoningSentencesWithUngroundedAboutClaims($in, $items);

        $this->assertStringNotContainsString('web design', mb_strtolower($out));
        $this->assertStringContainsString('top priority', mb_strtolower($out));
    }

    public function test_strip_reasoning_keeps_grounded_about_claims(): void
    {
        $items = [
            ['entity_type' => 'task', 'title' => 'Impossible 5h study block before quiz', 'entity_id' => 31],
        ];
        $in = 'This is mostly about the quiz—you can break the block into smaller sessions.';
        $out = TaskAssistantPrioritizeOutputDefaults::stripReasoningSentencesWithUngroundedAboutClaims($in, $items);

        $this->assertStringContainsString('quiz', mb_strtolower($out));
        $this->assertStringContainsString('smaller sessions', mb_strtolower($out));
    }

    public function test_sanitize_doing_coach_strips_ranked_subject_bleed(): void
    {
        $ranked = [
            ['entity_type' => 'task', 'title' => 'Impossible 5h study block before quiz'],
            ['entity_type' => 'task', 'title' => 'Review today’s lecture notes'],
            ['entity_type' => 'task', 'title' => 'ENG 105 – Reading Response #3'],
        ];
        $doing = [
            'ITCS 101 – Programming Exercise: Functions',
            'CS 220 – Lab 5: Linked Lists',
            'ITEL 210 – Lab 2: Flexbox Layout',
        ];
        $coach = 'Great job getting started! Make time for overdue notes and quiz review before they slip. '
            .'Keep closing one task before opening another—it cuts switching costs.';
        $out = TaskAssistantPrioritizeOutputDefaults::sanitizeDoingProgressCoachAgainstRankedContentBleed($coach, $ranked, $doing);

        $this->assertStringNotContainsString('overdue notes', mb_strtolower($out));
        $this->assertStringNotContainsString('quiz review', mb_strtolower($out));
        $this->assertStringContainsString('switching', mb_strtolower($out));
    }

    public function test_strip_reasoning_drops_sentences_that_echo_row_two_subjects(): void
    {
        $items = [
            ['entity_type' => 'task', 'title' => 'Impossible 5h study block before quiz', 'entity_id' => 31],
            ['entity_type' => 'task', 'title' => 'Review today’s lecture notes', 'entity_id' => 23],
            ['entity_type' => 'task', 'title' => 'ENG 105 – Reading Response #3', 'entity_id' => 15],
        ];
        $in = 'Impossible 5h study block before quiz is ranked first because it is overdue and urgent. '
            .'After that, reviewing today’s lecture notes will keep you caught up in class.';
        $out = TaskAssistantPrioritizeOutputDefaults::stripReasoningSentencesBleedingSecondaryRankedRows($in, $items);

        $this->assertStringContainsString('Impossible 5h study block before quiz', $out);
        $this->assertStringNotContainsString('lecture notes', mb_strtolower($out));
    }

    public function test_strip_reasoning_secondary_bleed_returns_empty_when_only_secondary_story(): void
    {
        $items = [
            [
                'entity_type' => 'event',
                'title' => 'CS group project meetup',
            ],
            [
                'entity_type' => 'task',
                'title' => 'Library research for history essay',
            ],
        ];
        $out = TaskAssistantPrioritizeOutputDefaults::stripReasoningSentencesBleedingSecondaryRankedRows(
            'You have an important essay to research, so start there.',
            $items
        );

        $this->assertSame('', $out);
    }

    public function test_strip_reasoning_drops_invented_problem_sets(): void
    {
        $items = [
            ['entity_type' => 'task', 'title' => 'Impossible 5h study block before quiz', 'entity_id' => 31],
        ];
        $in = 'Tackle the Impossible 5h study block before quiz first—it is overdue. Work through that challenging practice problem set.';
        $out = TaskAssistantPrioritizeOutputDefaults::stripReasoningSentencesWithInventedStudyArtifacts($in, $items);

        $this->assertStringContainsString('Impossible 5h study block before quiz', $out);
        $this->assertStringNotContainsString('practice problem', mb_strtolower($out));
    }
}
