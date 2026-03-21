<?php

namespace App\Services\LLM\TaskAssistant;

use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

class TaskAssistantContextAnalyzer
{
    private const ALLOWED_PRIORITIES = ['urgent', 'high', 'medium', 'low'];

    private const ALLOWED_TIME_CONSTRAINTS = ['today', 'this_week', 'none'];

    /**
     * Analyze user message to extract context and filtering criteria.
     */
    public function analyzeUserContext(string $userMessage, array $snapshot): array
    {
        $maxRetries = max(0, (int) config('task-assistant.retry.max_retries', 2));

        try {
            $response = $this->attemptContextAnalysis($userMessage, $snapshot, $maxRetries);

            $analysis = $response->structured ?? [];
            $analysis = is_array($analysis) ? $analysis : [];
            $analysis = $this->normalizeAnalysis($analysis);

            Log::info('task-assistant.context_analysis', [
                'user_message_length' => mb_strlen($userMessage),
                'analysis' => $analysis,
            ]);

            return $analysis;

        } catch (\Throwable $e) {
            Log::warning('task-assistant.context_analysis_failed', [
                'user_message_length' => mb_strlen($userMessage),
                'error' => $e->getMessage(),
            ]);

            return $this->getFallbackAnalysis($userMessage);
        }
    }

    /**
     * Build prompt for context analysis.
     */
    private function buildContextPrompt(string $userMessage, array $snapshot): string
    {
        $taskList = $this->formatTasksForPrompt($snapshot['tasks'] ?? []);

        return "Analyze the user's request for scheduling context.

USER MESSAGE: \"{$userMessage}\"

AVAILABLE TASKS:
{$taskList}

Extract scheduling criteria. Focus on:
1. Priority level mentions (urgent, high, medium, low)
2. Task type mentions (coding, math, study, etc.)
3. Time constraints (today, this week, etc.)
4. Specific task comparisons or choices

Rules for output:
- `priority_filters` must only contain values from: urgent, high, medium, low
- `time_constraint` must be one of: today, this_week, none
- `task_keywords` must be practical domain keywords (e.g. math, coding, reading), not generic words like schedule/day/tasks
- keep output concise and machine-friendly.";
    }

    /**
     * Schema for context analysis.
     */
    private function contextSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'context_analysis',
            description: 'User context analysis for task prioritization',
            properties: [
                new StringSchema(
                    name: 'intent_type',
                    description: 'Type of scheduling request (general, urgent_focus, time_constrained, specific_comparison).'
                ),
                new ArraySchema(
                    name: 'priority_filters',
                    description: 'Priority levels to consider',
                    items: new StringSchema(name: 'priority', description: 'Priority level')
                ),
                new ArraySchema(
                    name: 'task_keywords',
                    description: 'Keywords to match in task titles',
                    items: new StringSchema(name: 'keyword', description: 'Task keyword')
                ),
                new StringSchema(
                    name: 'time_constraint',
                    description: 'Time constraint: today, this_week, or none'
                ),
                new StringSchema(
                    name: 'comparison_focus',
                    description: 'Specific comparison the user is making'
                ),
            ],
            requiredFields: ['intent_type']
        );
    }

    /**
     * Format tasks for context analysis prompt.
     */
    private function formatTasksForPrompt(array $tasks): string
    {
        $lines = [];
        foreach ($tasks as $task) {
            $title = $task['title'] ?? 'Unknown task';
            $priority = $task['priority'] ?? 'medium';
            $deadline = $task['ends_at'] ?? 'No deadline';
            $lines[] = "- ID {$task['id']}: {$title} (Priority: {$priority}, Due: {$deadline})";
        }

        return implode("\n", $lines);
    }

    /**
     * Fallback analysis when LLM fails.
     */
    private function getFallbackAnalysis(string $userMessage): array
    {
        $message = strtolower($userMessage);

        $analysis = [
            'intent_type' => 'general',
            'priority_filters' => [],
            'task_keywords' => [],
            'time_constraint' => null,
            'comparison_focus' => null,
        ];

        // Simple rule-based fallback
        if (str_contains($message, 'urgent')) {
            $analysis['priority_filters'] = ['urgent'];
            $analysis['intent_type'] = 'urgent_focus';
        }

        if (str_contains($message, 'coding')) {
            $analysis['task_keywords'][] = 'coding';
        }

        if (str_contains($message, 'math')) {
            $analysis['task_keywords'][] = 'math';
        }

        if (str_contains($message, 'today')) {
            $analysis['time_constraint'] = 'today';
        }

        if (str_contains($message, 'between') && str_contains($message, 'and')) {
            $analysis['intent_type'] = 'specific_comparison';
            $analysis['comparison_focus'] = 'user_specified_choice';
        }

        return $this->normalizeAnalysis($analysis);
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    private function normalizeAnalysis(array $analysis): array
    {
        $intentType = is_string($analysis['intent_type'] ?? null)
            ? trim((string) $analysis['intent_type'])
            : 'general';

        $rawPriorities = $analysis['priority_filters'] ?? [];
        $priorities = [];
        if (is_array($rawPriorities)) {
            foreach ($rawPriorities as $priority) {
                $candidate = strtolower(trim((string) $priority));
                foreach (self::ALLOWED_PRIORITIES as $allowed) {
                    if (preg_match('/\b'.preg_quote($allowed, '/').'\b/i', $candidate) === 1) {
                        $priorities[] = $allowed;
                    }
                }
            }
        }
        $priorities = array_values(array_unique($priorities));

        $rawKeywords = $analysis['task_keywords'] ?? [];
        $keywords = [];
        $genericKeywords = ['schedule', 'day', 'today', 'tasks', 'task', 'plan'];
        if (is_array($rawKeywords)) {
            foreach ($rawKeywords as $keyword) {
                $candidate = strtolower(trim((string) $keyword));
                if ($candidate === '' || in_array($candidate, $genericKeywords, true)) {
                    continue;
                }
                $keywords[] = $candidate;
            }
        }
        $keywords = array_values(array_unique($keywords));

        $timeConstraintRaw = strtolower(trim((string) ($analysis['time_constraint'] ?? 'none')));
        $timeConstraint = 'none';
        if (str_contains($timeConstraintRaw, 'today')) {
            $timeConstraint = 'today';
        } elseif (str_contains($timeConstraintRaw, 'week')) {
            $timeConstraint = 'this_week';
        }
        if (! in_array($timeConstraint, self::ALLOWED_TIME_CONSTRAINTS, true)) {
            $timeConstraint = 'none';
        }

        $comparisonFocus = is_string($analysis['comparison_focus'] ?? null)
            ? trim((string) $analysis['comparison_focus'])
            : null;

        return [
            'intent_type' => $intentType === '' ? 'general' : $intentType,
            'priority_filters' => $priorities,
            'task_keywords' => $keywords,
            'time_constraint' => $timeConstraint,
            'comparison_focus' => $comparisonFocus !== '' ? $comparisonFocus : null,
        ];
    }

    private function resolveProvider(): Provider
    {
        $provider = strtolower((string) config('task-assistant.provider', 'ollama'));

        return match ($provider) {
            'ollama' => Provider::Ollama,
            default => Provider::Ollama,
        };
    }

    private function resolveModel(): string
    {
        return (string) config('task-assistant.model', 'hermes3:3b');
    }

    /**
     * @return array<string, int|float>
     */
    private function resolveClientOptionsForRoute(string $route): array
    {
        $temperature = config('task-assistant.generation.'.$route.'.temperature');
        $maxTokens = config('task-assistant.generation.'.$route.'.max_tokens');
        $topP = config('task-assistant.generation.'.$route.'.top_p');

        return [
            'timeout' => (int) config('prism.request_timeout', 30),
            'temperature' => is_numeric($temperature) ? (float) $temperature : (float) config('task-assistant.generation.temperature', 0.3),
            'max_tokens' => is_numeric($maxTokens) ? (int) $maxTokens : (int) config('task-assistant.generation.max_tokens', 1200),
            'top_p' => is_numeric($topP) ? (float) $topP : (float) config('task-assistant.generation.top_p', 0.9),
        ];
    }

    private function attemptContextAnalysis(string $userMessage, array $snapshot, int $maxRetries): mixed
    {
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                return Prism::structured()
                    ->using($this->resolveProvider(), $this->resolveModel())
                    ->withPrompt($this->buildContextPrompt($userMessage, $snapshot))
                    ->withSchema($this->contextSchema())
                    ->withClientOptions($this->resolveClientOptionsForRoute('context'))
                    ->asStructured();
            } catch (\Throwable $exception) {
                if ($attempt === $maxRetries) {
                    throw $exception;
                }
            }
        }

        throw new \RuntimeException('Unreachable context analysis retry state.');
    }
}
