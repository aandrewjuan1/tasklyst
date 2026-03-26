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

    public function test_max_reasoning_chars_reads_config(): void
    {
        config(['task-assistant.listing.max_reasoning_chars' => 400]);

        $this->assertSame(400, TaskAssistantListingDefaults::maxReasoningChars());
    }
}
