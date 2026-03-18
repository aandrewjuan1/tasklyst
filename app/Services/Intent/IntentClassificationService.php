<?php

namespace App\Services\Intent;

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
     * @return TaskAssistantIntent  The detected intent
     */
    public function classify(string $content): TaskAssistantIntent
    {
        $normalized = $this->normalizeContent($content);
        
        // Handle empty content immediately
        if (empty($normalized)) {
            Log::debug('intent.classified.empty', [
                'content_preview' => substr($content, 0, 100),
            ]);
            
            return TaskAssistantIntent::ProductivityCoaching;
        }
        
        // Fast path: High-confidence rule-based classification
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
        
        // Fallback: LLM classification for ambiguous cases
        return $this->classifyWithLLM($content);
    }
    
    /**
     * Get the corresponding flow for the detected intent (1:1 mapping).
     *
     * @param  TaskAssistantIntent  $intent
     * @return string  The flow identifier
     */
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
     * Classify intent and return both intent and flow.
     *
     * @param  string  $content
     * @return array{intent: TaskAssistantIntent, flow: string}
     */
    public function classifyWithFlow(string $content): array
    {
        $intent = $this->classify($content);
        $flow = $this->getFlowForIntent($intent);
        
        return [
            'intent' => $intent,
            'flow' => $flow,
        ];
    }
    
    /**
     * Normalize content for consistent processing.
     *
     * @param  string  $content
     * @return string
     */
    private function normalizeContent(string $content): string
    {
        return mb_strtolower(trim($content));
    }
    
    /**
     * Detect intent using prioritized pattern matching.
     *
     * @param  string  $normalizedContent
     * @return TaskAssistantIntent
     */
    private function detectIntentWithRules(string $normalizedContent): TaskAssistantIntent
    {
        // Handle empty content first
        if (empty($normalizedContent)) {
            return TaskAssistantIntent::ProductivityCoaching;
        }
        
        // Priority 1: Task Management (most specific patterns)
        if ($this->matchesPattern($normalizedContent, $this->getTaskManagementPatterns())) {
            return TaskAssistantIntent::TaskManagement;
        }
        
        // Priority 2: Study Planning (before TimeManagement to catch revision/academic patterns)
        if ($this->matchesPattern($normalizedContent, $this->getStudyPlanningPatterns())) {
            return TaskAssistantIntent::StudyPlanning;
        }
        
        // Priority 3: Time Management
        if ($this->matchesPattern($normalizedContent, $this->getTimeManagementPatterns())) {
            return TaskAssistantIntent::TimeManagement;
        }
        
        // Priority 4: Progress Review
        if ($this->matchesPattern($normalizedContent, $this->getProgressReviewPatterns())) {
            return TaskAssistantIntent::ProgressReview;
        }
        
        // Priority 5: Task Prioritization
        if ($this->matchesPattern($normalizedContent, $this->getTaskPrioritizationPatterns())) {
            return TaskAssistantIntent::TaskPrioritization;
        }
        
        // Priority 6: Productivity Coaching (fallback for coaching patterns)
        if ($this->matchesPattern($normalizedContent, $this->getProductivityCoachingPatterns())) {
            return TaskAssistantIntent::ProductivityCoaching;
        }
        
        // Default fallback
        return TaskAssistantIntent::ProductivityCoaching;
    }
    
    /**
     * Calculate confidence score for rule-based classification.
     *
     * @param  TaskAssistantIntent  $intent
     * @param  string  $content
     * @return float  Confidence score between 0.0 and 1.0
     */
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
    
    /**
     * Classify intent using LLM fallback for ambiguous cases.
     *
     * @param  string  $content
     * @return TaskAssistantIntent
     */
    private function classifyWithLLM(string $content): TaskAssistantIntent
    {
        try {
            $response = Prism::structured()
                ->using(Provider::Ollama, 'hermes3:3b')
                ->withPrompt($this->buildIntentPrompt($content))
                ->withSchema($this->intentSchema())
                ->withClientOptions(['timeout' => $this->llmTimeout])
                ->asStructured();
                
            $payload = $response->structured ?? [];
            $intentValue = $payload['intent'] ?? 'productivity_coaching';
            
            // Clean LLM response to handle malformed output
            $intentValue = $this->cleanIntentValue($intentValue);
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
            
            return TaskAssistantIntent::ProductivityCoaching; // Safe fallback
        }
    }
    
    /**
     * Build focused prompt for LLM intent classification.
     *
     * @param  string  $content
     * @return string
     */
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
    
    /**
     * Schema for structured LLM intent classification.
     *
     * @return ObjectSchema
     */
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
     * Check if content matches any of the provided patterns.
     *
     * @param  string  $content
     * @param  array<string>  $patterns
     * @return bool
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
     * Get task prioritization patterns.
     *
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
     * Get time management patterns.
     *
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
     * Get study planning patterns.
     *
     * @return array<string>
     */
    private function getStudyPlanningPatterns(): array
    {
        return [
            '/\b(study plan|revision plan|study schedule|revise)\b/',
            '/\b(exam.*prep|study.*for|revision.*schedule|study.*session)\b/',
            '/\b(academic.*plan|school.*work|course.*plan)\b/',
        ];
    }
    
    /**
     * Get progress review patterns.
     *
     * @return array<string>
     */
    private function getProgressReviewPatterns(): array
    {
        return [
            '/\b(review.*done|what have i done|summary of work|progress summary)\b/',
            '/\b(check.*progress|review.*work|completed.*task|finished)\b/',
            '/\b(how.*far|what.*accomplished|progress.*report)\b/',
        ];
    }
    
    /**
     * Get task management patterns.
     *
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
     * Get productivity coaching patterns.
     *
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
    
    /**
     * Check for explicit task management words.
     */
    private function hasExplicitTaskManagementWords(string $content): bool
    {
        return preg_match('/\b(create|update|delete|complete|list|add|edit|remove)\b/', $content) === 1;
    }
    
    /**
     * Check for explicit time management words.
     */
    private function hasExplicitTimeManagementWords(string $content): bool
    {
        return preg_match('/\b(schedule|time.*block|daily.*plan|calendar|when.*should.*i.*work|what.*time.*work)/', $content) === 1;
    }
    
    /**
     * Check for explicit study planning words.
     */
    private function hasExplicitStudyPlanningWords(string $content): bool
    {
        return preg_match('/\b(study|revision|exam|academic|course)\b/', $content) === 1;
    }
    
    /**
     * Check for explicit progress review words.
     */
    private function hasExplicitProgressReviewWords(string $content): bool
    {
        return preg_match('/\b(review|progress|summary|completed|finished|accomplished)\b/', $content) === 1;
    }
    
    /**
     * Check for explicit task prioritization words.
     */
    private function hasExplicitTaskPrioritizationWords(string $content): bool
    {
        return preg_match('/\b(prioritize|priority|next|first|important|choose|select)\b/', $content) === 1;
    }
    
    /**
     * Clean LLM response to handle malformed output.
     *
     * @param  string  $intentValue
     * @return string
     */
    private function cleanIntentValue(string $intentValue): string
    {
        // Remove quotes and extra whitespace
        $cleaned = trim($intentValue, '"\' ');
        
        // Handle multiple intents (take first one)
        $parts = preg_split('/[,\s]+/', $cleaned);
        $cleaned = $parts[0] ?? 'productivity_coaching';
        
        // Ensure it's a valid intent
        $validIntents = [
            'task_prioritization',
            'time_management', 
            'study_planning',
            'progress_review',
            'task_management',
            'productivity_coaching'
        ];
        
        return in_array($cleaned, $validIntents) ? $cleaned : 'productivity_coaching';
    }
    
    /**
     * Check for explicit productivity coaching words.
     */
    private function hasExplicitProductivityCoachingWords(string $content): bool
    {
        return preg_match('/\b(overwhelmed|stuck|procrastinating|motivation|focus|productive|struggling)\b/', $content) === 1;
    }
}
