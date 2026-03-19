<?php

namespace Tests\Unit;

use App\Enums\TaskAssistantIntent;
use App\Services\LLM\Intent\IntentClassificationService;
use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

class IntentClassificationServiceTest extends TestCase
{
    private IntentClassificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new IntentClassificationService;
    }

    #[DataProvider('taskPrioritizationIntentProvider')]
    public function test_it_classifies_task_prioritization_intents(string $content, TaskAssistantIntent $expected): void
    {
        $result = $this->service->classify($content);

        $this->assertSame($expected, $result);
    }

    #[DataProvider('timeManagementIntentProvider')]
    public function test_it_classifies_time_management_intents(string $content, TaskAssistantIntent $expected): void
    {
        $result = $this->service->classify($content);

        $this->assertSame($expected, $result);
    }

    #[DataProvider('studyPlanningIntentProvider')]
    public function test_it_classifies_study_planning_intents(string $content, TaskAssistantIntent $expected): void
    {
        $result = $this->service->classify($content);

        $this->assertSame($expected, $result);
    }

    #[DataProvider('progressReviewIntentProvider')]
    public function test_it_classifies_progress_review_intents(string $content, TaskAssistantIntent $expected): void
    {
        $result = $this->service->classify($content);

        $this->assertSame($expected, $result);
    }

    #[DataProvider('taskManagementIntentProvider')]
    public function test_it_classifies_task_management_intents(string $content, TaskAssistantIntent $expected): void
    {
        $result = $this->service->classify($content);

        $this->assertSame($expected, $result);
    }

    #[DataProvider('productivityCoachingIntentProvider')]
    public function test_it_classifies_productivity_coaching_intents(string $content, TaskAssistantIntent $expected): void
    {
        $result = $this->service->classify($content);

        $this->assertSame($expected, $result);
    }

    public function test_it_returns_correct_flow_for_intent(): void
    {
        $this->assertSame('task_choice', $this->service->getFlowForIntent(TaskAssistantIntent::TaskPrioritization));
        $this->assertSame('daily_schedule', $this->service->getFlowForIntent(TaskAssistantIntent::TimeManagement));
        $this->assertSame('study_plan', $this->service->getFlowForIntent(TaskAssistantIntent::StudyPlanning));
        $this->assertSame('review_summary', $this->service->getFlowForIntent(TaskAssistantIntent::ProgressReview));
        $this->assertSame('mutating', $this->service->getFlowForIntent(TaskAssistantIntent::TaskManagement));
        $this->assertSame('advisory', $this->service->getFlowForIntent(TaskAssistantIntent::ProductivityCoaching));
    }

    public function test_it_classifies_with_flow(): void
    {
        $result = $this->service->classifyWithFlow('What should I work on next?');

        $this->assertSame(TaskAssistantIntent::TaskPrioritization, $result['intent']);
        $this->assertSame('task_choice', $result['flow']);
    }

    public function test_it_handles_empty_content(): void
    {
        $result = $this->service->classify('');

        $this->assertSame(TaskAssistantIntent::ProductivityCoaching, $result);
    }

    public function test_it_handles_mixed_case_content(): void
    {
        $result = $this->service->classify('CREATE A TASK');

        $this->assertSame(TaskAssistantIntent::TaskManagement, $result);
    }

    public function test_it_parses_llm_fallback_intent_from_structured_output(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured(['intent' => 'task_prioritization'])
                ->withUsage(new Usage(1, 2)),
        ]);

        $result = $this->service->classify('This is a completely unrelated sentence.');

        $this->assertSame(TaskAssistantIntent::TaskPrioritization, $result);
    }

    public function test_intent_prompt_requests_schema_aligned_json_object(): void
    {
        $method = new \ReflectionMethod(IntentClassificationService::class, 'buildIntentPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($this->service, 'anything');

        $this->assertIsString($prompt);
        $this->assertStringContainsString('valid JSON object', $prompt);
        $this->assertStringContainsString('"intent"', $prompt);
    }

    /**
     * @return array<string, array{string, TaskAssistantIntent}>
     */
    public static function taskPrioritizationIntentProvider(): array
    {
        return [
            'task choice basic' => ['What should I work on next?', TaskAssistantIntent::TaskPrioritization],
            'task choice variant' => ['Help me choose my next task', TaskAssistantIntent::TaskPrioritization],
            'focus for today' => ['What should I focus for today?', TaskAssistantIntent::TaskPrioritization],
            'work on today' => ['What should I work on today?', TaskAssistantIntent::TaskPrioritization],
            'prioritize tasks' => ['Prioritize my tasks', TaskAssistantIntent::TaskPrioritization],
            'which task first' => ['Which task should I do first?', TaskAssistantIntent::TaskPrioritization],
        ];
    }

    /**
     * @return array<string, array{string, TaskAssistantIntent}>
     */
    public static function timeManagementIntentProvider(): array
    {
        return [
            'schedule basic' => ['Create a schedule for today', TaskAssistantIntent::TimeManagement],
            'time blocking' => ['Help me time block my day', TaskAssistantIntent::TimeManagement],
            'daily plan' => ['Make a daily plan', TaskAssistantIntent::TimeManagement],
            'when to work' => ['When should I work on this?', TaskAssistantIntent::TimeManagement],
        ];
    }

    /**
     * @return array<string, array{string, TaskAssistantIntent}>
     */
    public static function studyPlanningIntentProvider(): array
    {
        return [
            'study plan' => ['Make a study plan', TaskAssistantIntent::StudyPlanning],
            'revision schedule' => ['Create a revision schedule', TaskAssistantIntent::StudyPlanning],
            'exam prep' => ['Help me prepare for exams', TaskAssistantIntent::StudyPlanning],
            'academic planning' => ['Plan my academic work', TaskAssistantIntent::StudyPlanning],
        ];
    }

    /**
     * @return array<string, array{string, TaskAssistantIntent}>
     */
    public static function progressReviewIntentProvider(): array
    {
        return [
            'review accomplished' => ['What have I accomplished?', TaskAssistantIntent::ProgressReview],
            'progress check' => ['Check my progress', TaskAssistantIntent::ProgressReview],
            'work summary' => ['Summarize my work', TaskAssistantIntent::ProgressReview],
            'progress report' => ['Give me a progress report', TaskAssistantIntent::ProgressReview],
        ];
    }

    /**
     * @return array<string, array{string, TaskAssistantIntent}>
     */
    public static function taskManagementIntentProvider(): array
    {
        return [
            'create task' => ['Create a task', TaskAssistantIntent::TaskManagement],
            'delete task' => ['Delete the task', TaskAssistantIntent::TaskManagement],
            'list tasks' => ['List all tasks', TaskAssistantIntent::TaskManagement],
            'complete task' => ['Mark task as complete', TaskAssistantIntent::TaskManagement],
            'update task' => ['Update the task details', TaskAssistantIntent::TaskManagement],
        ];
    }

    /**
     * @return array<string, array{string, TaskAssistantIntent}>
     */
    public static function productivityCoachingIntentProvider(): array
    {
        return [
            'feeling overwhelmed' => ['I am feeling overwhelmed', TaskAssistantIntent::ProductivityCoaching],
            'procrastinating' => ['I keep procrastinating', TaskAssistantIntent::ProductivityCoaching],
            'need motivation' => ['I need motivation', TaskAssistantIntent::ProductivityCoaching],
            'help me focus' => ['Help me stay focused', TaskAssistantIntent::ProductivityCoaching],
            'struggling' => ['I am struggling with productivity', TaskAssistantIntent::ProductivityCoaching],
        ];
    }
}
