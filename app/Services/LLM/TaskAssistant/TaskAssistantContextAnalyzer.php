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
    /**
     * Analyze user message to extract context and filtering criteria.
     */
    public function analyzeUserContext(string $userMessage, array $snapshot): array
    {
        $timeout = (int) config('prism.request_timeout', 30); // Short timeout for quick analysis

        try {
            $response = Prism::structured()
                ->using(Provider::Ollama, (string) config('task-assistant.model', 'hermes3:3b'))
                ->withPrompt($this->buildContextPrompt($userMessage, $snapshot))
                ->withSchema($this->contextSchema())
                ->withClientOptions(['timeout' => $timeout])
                ->asStructured();

            $analysis = $response->structured ?? [];

            Log::info('task-assistant.context_analysis', [
                'user_message' => substr($userMessage, 0, 100),
                'analysis' => $analysis,
            ]);

            return $analysis;

        } catch (\Throwable $e) {
            Log::warning('task-assistant.context_analysis_failed', [
                'user_message' => substr($userMessage, 0, 100),
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

        return "Analyze the user's request for task prioritization context.

USER MESSAGE: \"{$userMessage}\"

AVAILABLE TASKS:
{$taskList}

Extract the user's intent and any specific filtering criteria. Focus on:
1. Priority level mentions (urgent, high, medium, low)
2. Task type mentions (coding, math, study, etc.)
3. Time constraints (today, this week, etc.)
4. Specific task comparisons or choices

Respond with structured analysis of what the user is specifically asking about.";
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
                    description: 'Type of prioritization request (general_urgent, specific_comparison, time_constrained, etc.)'
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
                    description: 'Time constraint mentioned (today, this_week, etc.)'
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

        return $analysis;
    }
}
