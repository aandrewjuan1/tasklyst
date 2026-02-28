<?php

namespace App\Services\Llm;

class QueryRelevanceService
{
    /**
     * Exact-match greetings (always allow, nudge toward tasks).
     */
    private const GREETINGS = [
        'hi', 'hello', 'hey', 'help',
        'sure', 'ok', 'okay',
    ];

    /**
     * Greeting prefixes — allow if message STARTS with these (punctuation allowed after).
     */
    private const GREETING_PREFIXES = [
        'hi',
        'hello',
        'hey',
        'help',
        'can you help',
        'could you help',
        'i need help',
        'i want help',
        'i need to',
        'i want to',
        'help with',
    ];

    /**
     * Assistance request phrases — allow if message CONTAINS these (catches follow-ups like "okay help me").
     */
    private const ASSISTANCE_REQUESTS = [
        'help me',
        'please help',
        'okay help',
        'yes help',
        'yeah help',
        'help please',
        'sure help',
        'alright help',
        'go ahead',
        'continue',
        'what next',
        "what's next",
        'next step',
        'get started',
        'show me how',
        'help with',
    ];

    /**
     * Domain keywords using word-boundary-safe patterns.
     * Use full words or clear prefixes to avoid substring collisions.
     */
    private const DOMAIN_KEYWORDS = [
        // Core entities
        'task', 'todo', 'to-do',
        'assignment', 'homework',
        'project', 'deadline',
        'schedule', 'calendar',
        'exam', 'quiz', 'thesis',
        'lecture', 'submission', 'submit',
        'report', 'presentation', 'defense',
        'group work', 'group mate',

        // Actions
        'prioritize', 'prioritization', 'priority',
        'organize', 'remind', 'reminder',
        'plan my', 'study plan',
        'time block', 'time blocking',

        // Status
        'overdue', 'due date', 'due tomorrow',
        'due today', 'upcoming', 'backlog', 'workload',

        // Productivity
        'pomodoro', 'focus session', 'focus time',
        'break time', 'study session',
        'procrastinat', 'productivity',

        // Time references (only task-relevant combos)
        'this week', 'plan my day', 'plan my week',
        'what should i', 'what do i', 'help me finish',
        'help me start', 'when should i',
        'how do i', 'how can i', 'what can i',
        'focus on', 'next step', 'get started',
    ];

    /**
     * Polite/social closings — always allow and respond with a friendly message.
     * These bypass the relevance guardrail entirely.
     */
    private const SOCIAL_CLOSINGS = [
        'thank you', 'thanks', 'thank you!', 'thanks!', 'thx',
        'bye', 'goodbye', 'good bye', 'see you', 'see ya',
        'catch you later', 'talk later', 'talk to you later',
        'okay thank you', 'ok thank you', 'okay thanks', 'ok thanks',
        'haha', 'hahaha', 'lol', 'hehe',
        'got it', 'sounds good', 'perfect', 'awesome', 'cool',
        'have a good one', 'take care', 'cheers',
        'that works', 'works for me', 'all good',
    ];

    /**
     * General-knowledge prefixes that signal off-topic queries.
     * No trailing spaces — the check appends its own space.
     */
    private const OFF_TOPIC_PREFIXES = [
        'who is', 'who was', 'who were',
        'what is', 'what was', 'what are',
        'where is', 'where was',
        'when is', 'when was', 'when did',
        'how many', 'how much', 'how does',
        'capital of', 'population of',
        'distance between', 'meaning of',
        'define', 'definition of',
        'tell me about', 'explain to me',
        'write me a', 'write a poem',
        'give me a recipe', 'translate',
    ];

    /**
     * Phrases that are unambiguously off-topic regardless of position.
     */
    private const OFF_TOPIC_PHRASES = [
        'president of', 'prime minister of', 'capital of',
        'recipe for', 'poem about', 'story about',
        'how to cook', 'movie recommendation',
        'sports score', 'weather in',
    ];

    public function isSocialClosing(string $userMessage): bool
    {
        $normalized = $this->normalize($userMessage);

        if ($normalized === '') {
            return false;
        }

        foreach (self::SOCIAL_CLOSINGS as $phrase) {
            if ($normalized === $phrase || str_starts_with($normalized, $phrase.' ')) {
                return true;
            }
        }

        return false;
    }

    public function isRelevant(string $userMessage): bool
    {
        $normalized = $this->normalize($userMessage);

        if ($normalized === '') {
            return true;
        }

        // 1. Hard deny — off-topic phrases take priority
        if ($this->containsOffTopicPhrase($normalized)) {
            return false;
        }

        // 2. Always allow greetings
        if ($this->matchesGreeting($normalized)) {
            return true;
        }

        // 3. Allow if domain keyword found (with word boundary check)
        if ($this->containsDomainKeyword($normalized)) {
            return true;
        }

        // 4. Deny known general-knowledge question patterns
        if ($this->looksLikeGeneralKnowledgeQuestion($normalized)) {
            return false;
        }

        // 4.5. Allow assistance requests (short follow-ups like "okay help me", "help me")
        if ($this->containsAssistanceRequest($normalized)) {
            return true;
        }

        // 5. Short vague messages with no domain signal → off-topic
        $wordCount = str_word_count($normalized);

        if ($wordCount <= 3) {
            return false;
        }

        // 6. Longer ambiguous messages → allow; let downstream prompts and guardrails steer
        return true;
    }

    private function normalize(string $message): string
    {
        $lower = mb_strtolower(trim($message));

        return preg_replace('/\s+/', ' ', $lower) ?? $lower;
    }

    private function matchesGreeting(string $normalized): bool
    {
        if (in_array($normalized, self::GREETINGS, true)) {
            return true;
        }

        foreach (self::GREETING_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function containsDomainKeyword(string $normalized): bool
    {
        foreach (self::DOMAIN_KEYWORDS as $keyword) {
            if ($this->wordBoundaryMatch($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function containsOffTopicPhrase(string $normalized): bool
    {
        foreach (self::OFF_TOPIC_PHRASES as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeGeneralKnowledgeQuestion(string $normalized): bool
    {
        foreach (self::OFF_TOPIC_PREFIXES as $prefix) {
            // Check as prefix with trailing space, or exact match
            if (
                str_starts_with($normalized, $prefix.' ') ||
                $normalized === $prefix
            ) {
                return true;
            }
        }

        return false;
    }

    private function containsAssistanceRequest(string $normalized): bool
    {
        foreach (self::ASSISTANCE_REQUESTS as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Word-boundary-safe substring match.
     * Prevents 'lab' from matching 'elaborate', 'class' from 'classical', etc.
     * Multi-word keywords bypass this (they use str_contains directly).
     */
    private function wordBoundaryMatch(string $haystack, string $keyword): bool
    {
        // Multi-word keywords: plain substring is fine
        if (str_contains($keyword, ' ')) {
            return str_contains($haystack, $keyword);
        }

        // Single-word: use regex word boundary
        $pattern = '/\b'.preg_quote($keyword, '/').'/i';

        return (bool) preg_match($pattern, $haystack);
    }
}
