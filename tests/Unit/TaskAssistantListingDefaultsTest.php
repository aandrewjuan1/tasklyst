<?php

namespace Tests\Unit;

use App\Support\LLM\TaskAssistantListingDefaults;
use Tests\TestCase;

class TaskAssistantListingDefaultsTest extends TestCase
{
    public function test_clamp_browse_reasoning_truncates_with_ellipsis(): void
    {
        config(['task-assistant.listing.max_reasoning_chars' => 10]);

        $out = TaskAssistantListingDefaults::clampBrowseReasoning('123456789012345');

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

        $out = TaskAssistantListingDefaults::clampFraming(str_repeat('a', 120));

        $this->assertSame(80, mb_strlen($out));
        $this->assertSame(str_repeat('a', 79).'…', $out);
    }

    public function test_clamp_next_field_respects_next_field_max(): void
    {
        // maxNextFieldChars = min(260, maxReasoningChars()).
        config(['task-assistant.listing.max_reasoning_chars' => 10]);

        $out = TaskAssistantListingDefaults::clampNextField(str_repeat('b', 50));

        $this->assertSame(10, mb_strlen($out));
        $this->assertSame(str_repeat('b', 9).'…', $out);
    }

    public function test_clamp_suggested_next_action_truncates_with_ellipsis(): void
    {
        $out = TaskAssistantListingDefaults::clampSuggestedNextAction(str_repeat('c', 200));

        $this->assertSame(180, mb_strlen($out));
        $this->assertSame(str_repeat('c', 179).'…', $out);
    }

    public function test_clamp_next_option_chip_text_truncates_with_ellipsis(): void
    {
        $out = TaskAssistantListingDefaults::clampNextOptionChipText(str_repeat('d', 200));

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

        $out = TaskAssistantListingDefaults::normalizePrioritizeReasoningVoice($in, $items);

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
        $out = TaskAssistantListingDefaults::normalizePrioritizeReasoningVoice($in, $items);

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
        $out = TaskAssistantListingDefaults::normalizePrioritizeReasoningVoice($in, $items);

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
        $out = TaskAssistantListingDefaults::normalizePrioritizeReasoningVoice($in, $items);

        $this->assertStringStartsWith('I chose this task because', $out);
        $this->assertStringContainsString('overdue', mb_strtolower($out));
        $this->assertStringContainsString('Medium', $out);
        $this->assertStringNotContainsString('due today', mb_strtolower($out));
        $this->assertStringNotContainsString('high priority', mb_strtolower($out));
    }

    public function test_max_reasoning_chars_reads_config(): void
    {
        config(['task-assistant.listing.max_reasoning_chars' => 400]);

        $this->assertSame(400, TaskAssistantListingDefaults::maxReasoningChars());
    }

    public function test_max_framing_chars_reads_config_and_caps_to_suggested_guidance(): void
    {
        config([
            'task-assistant.listing.max_framing_chars' => 2000,
            'task-assistant.listing.max_suggested_guidance_chars' => 500,
        ]);

        $this->assertSame(500, TaskAssistantListingDefaults::maxFramingChars());
    }

    public function test_coerce_singular_prioritize_narrative_only_when_one_item(): void
    {
        $items = [[
            'entity_type' => 'task',
            'entity_id' => 31,
            'title' => 'Impossible 5h study block before quiz',
        ]];

        $in = 'I recommend focusing on these top priorities first. These high-priority tasks that are already overdue will help you get a head start, even though they have some time left.';
        $out = TaskAssistantListingDefaults::coerceSingularPrioritizeNarrative($in, 1, $items);

        $this->assertStringContainsString('this top priority', mb_strtolower($out));
        $this->assertStringContainsString('high-priority task that is', mb_strtolower($out));
        $this->assertStringContainsString('it has some time left', mb_strtolower($out));
        $this->assertStringNotContainsString('these top priorities', mb_strtolower($out));

        $unchanged = TaskAssistantListingDefaults::coerceSingularPrioritizeNarrative($in, 2, $items);
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
        $out = TaskAssistantListingDefaults::coerceSingularPrioritizeNarrative($in, 1, $items);

        $this->assertSame('This event is the next focus.', $out);
    }

    public function test_strip_robotic_prioritize_reasoning_tail_removes_legacy_anchor_paragraph(): void
    {
        $body = 'You should tackle the urgent work first.';
        $tail = "\n\nStart with Impossible 5h study block before quiz when you're ready—it's first on this ordered list.";
        $out = TaskAssistantListingDefaults::stripRoboticPrioritizeReasoningTail($body.$tail);

        $this->assertSame($body, $out);
        $this->assertStringNotContainsString('ordered list', $out);
    }
}
