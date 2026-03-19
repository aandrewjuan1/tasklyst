<?php

namespace App\Services\LLM\Intent;

use App\Enums\TaskAssistantIntent;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

class IntentClassificationService
{
    public function __construct(
        private readonly float $ruleConfidenceThreshold = 0.8,
        private readonly int $llmTimeout = 10,
    ) {}

    /**
     * Classify user intent using hybrid approach (rule-based + LLM fallback).
     *
     * @param  string  $content  The user message content
     */
    public function classify(string $content): TaskAssistantIntent
    {
        $normalized = $this->normalizeContent($content);

        if (empty($normalized)) {
            Log::debug('intent.classified.empty', [
                'content_preview' => substr($content, 0, 100),
            ]);

            return TaskAssistantIntent::ProductivityCoaching;
        }

        $ruleBasedIntent = $this->detectIntentWithRules($normalized);
        $confidence = $this->calculateRuleConfidence($ruleBasedIntent, $normalized);

        if ($confidence >= $this->ruleConfidenceThreshold) {
            Log::debug('intent.classified.rules', [
                'intent' => $ruleBasedIntent->value,
                'confidence' => $confidence,
                'content_preview' => substr($content, 0, 100),
            ]);

            return $ruleBasedIntent;
        }

        return $this->classifyWithLLM($content);
    }

    public function getFlowForIntent(TaskAssistantIntent $intent): string
    {
        return match ($intent) {
            TaskAssistantIntent::TaskPrioritization => 'task_choice',
            TaskAssistantIntent::TimeManagement => 'daily_schedule',
            TaskAssistantIntent::StudyPlanning => 'study_plan',
            TaskAssistantIntent::ProgressReview => 'review_summary',
            TaskAssistantIntent::TaskManagement => 'mutating',
            TaskAssistantIntent::ProductivityCoaching => 'advisory',
        };
    }

    /**
     * @return array{intent: TaskAssistantIntent, flow: string}
     */
    public function classifyWithFlow(string $content): array
    {
        $intent = $this->classify($content);

        return [
            'intent' => $intent,
            'flow' => $this->getFlowForIntent($intent),
        ];
    }

    private function normalizeContent(string $content): string
    {
        return mb_strtolower(trim($content));
    }

    private function detectIntentWithRules(string $normalizedContent): TaskAssistantIntent
    {
        if (empty($normalizedContent)) {
            return TaskAssistantIntent::ProductivityCoaching;
        }

        if ($this->matchesPattern($normalizedContent, $this->getTaskManagementPatterns())) {
            return TaskAssistantIntent::TaskManagement;
        }

        if ($this->matchesPattern($normalizedContent, $this->getStudyPlanningPatterns())) {
            return TaskAssistantIntent::StudyPlanning;
        }

        if ($this->matchesPattern($normalizedContent, $this->getTimeManagementPatterns())) {
            return TaskAssistantIntent::TimeManagement;
        }

        if ($this->matchesPattern($normalizedContent, $this->getProgressReviewPatterns())) {
            return TaskAssistantIntent::ProgressReview;
        }

        if ($this->matchesPattern($normalizedContent, $this->getTaskPrioritizationPatterns())) {
            return TaskAssistantIntent::TaskPrioritization;
        }

        if ($this->matchesPattern($normalizedContent, $this->getProductivityCoachingPatterns())) {
            return TaskAssistantIntent::ProductivityCoaching;
        }

        return TaskAssistantIntent::ProductivityCoaching;
    }

    private function calculateRuleConfidence(TaskAssistantIntent $intent, string $content): float
    {
        return match ($intent) {
            TaskAssistantIntent::TaskManagement => $this->hasExplicitTaskManagementWords($content) ? 0.95 : 0.3,
            TaskAssistantIntent::TimeManagement => $this->hasExplicitTimeManagementWords($content) ? 0.90 : 0.4,
            TaskAssistantIntent::StudyPlanning => $this->hasExplicitStudyPlanningWords($content) ? 0.90 : 0.4,
            TaskAssistantIntent::ProgressReview => $this->hasExplicitProgressReviewWords($content) ? 0.85 : 0.3,
            TaskAssistantIntent::TaskPrioritization => $this->hasExplicitTaskPrioritizationWords($content) ? 0.85 : 0.4,
            TaskAssistantIntent::ProductivityCoaching => $this->hasExplicitProductivityCoachingWords($content) ? 0.80 : 0.2,
        };
    }

    private function classifyWithLLM(string $content): TaskAssistantIntent
    {
        try {
            $response = Prism::structured()
                ->using(Provider::Ollama, (string) config('task-assistant.model', 'hermes3:3b'))
                ->withPrompt($this->buildIntentPrompt($content))
                ->withSchema($this->intentSchema())
                ->withClientOptions(['timeout' => $this->llmTimeout])
                ->asStructured();

            $payload = $response->structured ?? [];
            $intentValue = $payload['intent'] ?? 'productivity_coaching';

            $intentValue = $this->cleanIntentValue((string) $intentValue);
            $intent = TaskAssistantIntent::from($intentValue);

            Log::debug('intent.classified.llm', [
                'intent' => $intent->value,
                'content_preview' => substr($content, 0, 100),
            ]);

            return $intent;
        } catch (\Throwable $e) {
            Log::warning('intent.classification.llm_failed', [
                'error' => $e->getMessage(),
                'content_preview' => substr($content, 0, 100),
            ]);

            return TaskAssistantIntent::ProductivityCoaching;
        }
    }

    private function buildIntentPrompt(string $content): string
    {
        return "You are an intent classifier for a student task assistant.

VALID INTENTS (use EXACTLY):
- task_prioritization (examples: \"what should i do first\", \"help me choose\", \"which task is most important\")
- time_management (examples: \"schedule my day\", \"when should i work\", \"time blocking\")
- study_planning (examples: \"study plan\", \"exam schedule\", \"revision timetable\")
- progress_review (examples: \"what did i finish\", \"review my progress\", \"work summary\")
- task_management (examples: \"create task\", \"delete task\", \"update task\", \"list tasks\")
- productivity_coaching (examples: \"hello\", \"feeling overwhelmed\", \"need motivation\", \"help me focus\")

User message: \"{$content}\"

Respond with ONLY one of these exact values:
task_prioritization
time_management
study_planning
progress_review
task_management
productivity_coaching

No explanation, no quotes, no extra text.";
    }

    private function intentSchema(): ObjectSchema
    {
        return new ObjectSchema(
            name: 'intent',
            description: 'Classified intent for the user message',
            properties: [
                new StringSchema(
                    name: 'intent',
                    description: 'The classified intent'
                ),
            ],
            requiredFields: [
                'intent',
            ]
        );
    }

    /**
     * @param  array<string>  $patterns
     */
    private function matchesPattern(string $content, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string>
     */
    private function getTaskPrioritizationPatterns(): array
    {
        return [
            '/\b(what should i work on|help me choose|choose.*next task|pick.*next task)\b/',
            '/\b(prioritize|priority|what.*next|which.*first|most.*important)\b/',
            '/\b(decide.*task|select.*task|focus.*on.*next)\b/',
        ];
    }

    /**
     * @return array<string>
     */
    private function getTimeManagementPatterns(): array
    {
        return [
            '/\b(schedule|propose.*schedule|plan my day|today.*schedule)\b/',
            '/\b(time block|time blocking|daily.*plan|calendar)\b/',
            '/\b(when.*should.*i.*work|what.*time.*work|schedule.*day|time.*slot)\b/',
        ];
    }

    /**
     * @return array<string>
     */
    private function getStudyPlanningPatterns(): array
    {
        return [
            '/\b(study plan|revision plan|study schedule|revise)\b/',
            '/\b(exam.*prep|study.*for|revision.*schedule|study.*session)\b/',
            '/\b(academic.*plan|plan.*academic|academic work|school.*work|course.*plan)\b/',
        ];
    }

    /**
     * @return array<string>
     */
    private function getProgressReviewPatterns(): array
    {
        return [
            '/\b(review.*done|what have i done|summary of work|work summary|progress summary)\b/',
            '/\b(check.*progress|review.*work|completed.*task|finished)\b/',
            '/\b(how.*far|what.*accomplished|progress.*report)\b/',
        ];
    }

    /**
     * @return array<string>
     */
    private function getTaskManagementPatterns(): array
    {
        return [
            '/\b(create.*task|update.*task|delete.*task|restore.*task|complete.*task|mark.*task|archive.*task|list.*task)\b/',
            '/\b(add.*task|new.*task|edit.*task|remove.*task)\b/',
            '/\b(show.*task|get.*task|find.*task|all.*task)\b/',
        ];
    }

    /**
     * @return array<string>
     */
    private function getProductivityCoachingPatterns(): array
    {
        return [
            '/\b(feeling overwhelmed|stuck|procrastinating|can.*focus)\b/',
            '/\b(help me stay.*focused|motivation|productive|break.*down)\b/',
            '/\b(how.*be more productive|time management|prioritize)\b/',
            '/\b(need.*help|struggling|overloaded|too.*much)\b/',
            '/\b(study habits|work habits|daily routine|burnout|stress)\b/',
        ];
    }

    private function hasExplicitTaskManagementWords(string $content): bool
    {
        return preg_match('/\b(create|update|delete|complete|list|add|edit|remove)\b/', $content) === 1;
    }

    private function hasExplicitTimeManagementWords(string $content): bool
    {
        return preg_match('/\b(schedule|time.*block|daily.*plan|calendar|when.*should.*i.*work|what.*time.*work)/', $content) === 1;
    }

    private function hasExplicitStudyPlanningWords(string $content): bool
    {
        return preg_match('/\b(study|revision|exam|academic|course)\b/', $content) === 1;
    }

    private function hasExplicitProgressReviewWords(string $content): bool
    {
        return preg_match('/\b(review|progress|summary|completed|finished|accomplished)\b/', $content) === 1;
    }

    private function hasExplicitTaskPrioritizationWords(string $content): bool
    {
        return preg_match('/\b(prioritize|priority|next|first|important|choose|select)\b/', $content) === 1;
    }

    private function hasExplicitProductivityCoachingWords(string $content): bool
    {
        return preg_match('/\b(overwhelmed|stuck|procrastinating|motivation|focus|productive|struggling)\b/', $content) === 1;
    }

    private function cleanIntentValue(string $intentValue): string
    {
        $cleaned = trim($intentValue, "\"'\\ ");

        $parts = preg_split('/[,\\s]+/', $cleaned);
        $cleaned = $parts[0] ?? 'productivity_coaching';

        $validIntents = [
            'task_prioritization',
            'time_management',
            'study_planning',
            'progress_review',
            'task_management',
            'productivity_coaching',
        ];

        return in_array($cleaned, $validIntents, true) ? $cleaned : 'productivity_coaching';
    }
}
