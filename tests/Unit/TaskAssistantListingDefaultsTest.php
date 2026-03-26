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
        // maxFramingChars = min(400, maxSuggestedGuidanceChars()).
        // maxSuggestedGuidanceChars = max(80, configured value).
        config([
            'task-assistant.listing.max_reasoning_chars' => 800,
            'task-assistant.listing.max_suggested_guidance_chars' => 50,
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
}
