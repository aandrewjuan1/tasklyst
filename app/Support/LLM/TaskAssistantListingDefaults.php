<?php

namespace App\Support\LLM;

/**
 * User-visible defaults for listing when data or the LLM omits optional fields.
 */
final class TaskAssistantListingDefaults
{
    public static function maxFramingChars(): int
    {
        // Must match TaskAssistantResponseProcessor::validatePrioritizeListingData().
        // framing max = min(400, maxSuggestedGuidanceChars()).
        return min(400, self::maxSuggestedGuidanceChars());
    }

    public static function clampFraming(string $text): string
    {
        $max = self::maxFramingChars();
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1).'…';
    }

    public static function maxReasoningChars(): int
    {
        $max = (int) config('task-assistant.listing.max_reasoning_chars', 800);

        return max(1, $max);
    }

    /**
     * Keeps listing payload reasoning within max length.
     */
    public static function clampBrowseReasoning(string $text): string
    {
        $max = self::maxReasoningChars();
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1).'…';
    }

    public static function maxSuggestedGuidanceChars(): int
    {
        $max = (int) config('task-assistant.listing.max_suggested_guidance_chars', 1200);

        return max(80, $max);
    }

    public static function maxNextFieldChars(): int
    {
        // Must match TaskAssistantResponseProcessor::validatePrioritizeListingData().
        // next_actions_intro + next_options max = min(260, maxReasoningChars()).
        return max(1, min(260, self::maxReasoningChars()));
    }

    public static function clampNextField(string $text): string
    {
        $max = self::maxNextFieldChars();
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1).'…';
    }

    public static function maxSuggestedNextActionChars(): int
    {
        // Must match TaskAssistantResponseProcessor::validatePrioritizeListingData().
        return 180;
    }

    public static function clampSuggestedNextAction(string $text): string
    {
        $max = self::maxSuggestedNextActionChars();
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1).'…';
    }

    public static function maxNextOptionChipTextChars(): int
    {
        // Must match TaskAssistantResponseProcessor::validatePrioritizeListingData().
        return 120;
    }

    public static function clampNextOptionChipText(string $text): string
    {
        $max = self::maxNextOptionChipTextChars();
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1).'…';
    }

    public static function clampBrowseSuggestedGuidance(string $text): string
    {
        $max = self::maxSuggestedGuidanceChars();
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1).'…';
    }

    public static function maxItemPlacementBlurbChars(): int
    {
        $max = (int) config('task-assistant.listing.max_item_placement_blurb_chars', 200);

        return max(40, $max);
    }

    public static function clampItemPlacementBlurb(string $text): string
    {
        $max = self::maxItemPlacementBlurbChars();
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1).'…';
    }

    public static function defaultSuggestedGuidance(): string
    {
        return __('I suggest picking one task from the list to start with so you don\'t get overwhelmed. If you tell me your focus, I can help you narrow this list or plan what to tackle first.');
    }

    public static function reasoningWhenEmpty(): string
    {
        return __('This list reflects your filters and the same ranking order used elsewhere in the assistant.');
    }

    public static function complexityNotSetLabel(): string
    {
        return __('Not set');
    }

    public static function noDueDateLabel(): string
    {
        return __('No due date');
    }
}
