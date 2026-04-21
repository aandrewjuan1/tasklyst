<?php

namespace App\Services\LLM\TaskAssistant;

use App\Models\TaskAssistantThread;
use App\Support\LLM\TaskAssistantReasonCodes;

final class TaskAssistantGreetingIntentClassifier
{
    /**
     * @return array{
     *   is_greeting_only: bool,
     *   confidence: float,
     *   reason_codes: list<string>
     * }
     */
    public function classify(TaskAssistantThread $thread, string $content): array
    {
        $normalized = $this->normalize($content);
        if ($normalized === '') {
            return $this->negativeDecision();
        }

        if ($this->matchesAnyPattern($normalized, (array) config('task-assistant.greeting.actionable_guard_patterns', []))) {
            return $this->negativeDecision();
        }

        if (! $this->matchesAnyPattern($normalized, (array) config('task-assistant.greeting.patterns', []))) {
            return $this->negativeDecision();
        }

        if (! $this->allowsOptionalNameMention($normalized)) {
            return $this->negativeDecision();
        }

        return [
            'is_greeting_only' => true,
            'confidence' => 1.0,
            'reason_codes' => [
                TaskAssistantReasonCodes::GREETING_ONLY_DETECTED,
                TaskAssistantReasonCodes::GREETING_SHORTCIRCUIT_GENERAL_GUIDANCE,
            ],
        ];
    }

    private function normalize(string $content): string
    {
        $normalized = mb_strtolower(trim($content));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function allowsOptionalNameMention(string $normalized): bool
    {
        $allowNameMention = (bool) config('task-assistant.greeting.allow_name_mentions', true);
        if (! $allowNameMention) {
            return true;
        }

        $namePatterns = (array) config('task-assistant.greeting.allowed_name_patterns', []);
        if ($namePatterns === []) {
            return true;
        }

        foreach ($namePatterns as $pattern) {
            $regex = trim((string) $pattern);
            if ($regex === '') {
                continue;
            }

            if (@preg_match($regex, $normalized) === 1) {
                return true;
            }
        }

        // If no configured name pattern matches, still allow plain greeting-only text.
        return preg_match('/^[\p{L}\p{N}\s!?.,"\'-]+$/u', $normalized) === 1;
    }

    /**
     * @param  array<int, mixed>  $patterns
     */
    private function matchesAnyPattern(string $normalized, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $regex = trim((string) $pattern);
            if ($regex === '') {
                continue;
            }

            if (@preg_match($regex, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *   is_greeting_only: false,
     *   confidence: float,
     *   reason_codes: list<string>
     * }
     */
    private function negativeDecision(): array
    {
        return [
            'is_greeting_only' => false,
            'confidence' => 0.0,
            'reason_codes' => [],
        ];
    }
}
