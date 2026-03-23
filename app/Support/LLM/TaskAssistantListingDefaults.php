<?php

namespace App\Support\LLM;

/**
 * User-visible defaults for listing when data or the LLM omits optional fields.
 */
final class TaskAssistantListingDefaults
{
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

    public static function clampBrowseSuggestedGuidance(string $text): string
    {
        $max = self::maxSuggestedGuidanceChars();
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
