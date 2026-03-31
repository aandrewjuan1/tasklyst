<?php

namespace Tests\Unit;

use App\Services\LLM\TaskAssistant\TaskAssistantService;
use ReflectionMethod;
use Tests\TestCase;

class TaskAssistantServiceCountMismatchTest extends TestCase
{
    public function test_resolve_prioritize_count_mismatch_explanation_uses_implicit_fallback_when_no_explicit_count(): void
    {
        $service = app(TaskAssistantService::class);
        $method = new ReflectionMethod(TaskAssistantService::class, 'resolvePrioritizeCountMismatchExplanation');
        $method->setAccessible(true);

        $result = $method->invoke(
            $service,
            ['requested_count' => 3, 'actual_count' => 1, 'has_count_mismatch' => true, 'explicit_requested_count' => null],
            1,
            null
        );

        $this->assertIsString($result);
        $this->assertStringNotContainsString('You asked for', $result);
        $this->assertStringContainsString('found 1', $result);
    }

    public function test_resolve_prioritize_count_mismatch_explanation_uses_explicit_count_when_present(): void
    {
        $service = app(TaskAssistantService::class);
        $method = new ReflectionMethod(TaskAssistantService::class, 'resolvePrioritizeCountMismatchExplanation');
        $method->setAccessible(true);

        $result = $method->invoke(
            $service,
            ['requested_count' => 3, 'actual_count' => 1, 'has_count_mismatch' => true, 'explicit_requested_count' => 2],
            1,
            null
        );

        $this->assertIsString($result);
        $this->assertStringContainsString('You asked for 2', $result);
    }

    public function test_resolve_prioritize_count_mismatch_explanation_ignores_invalid_in_progress_claims(): void
    {
        $service = app(TaskAssistantService::class);
        $method = new ReflectionMethod(TaskAssistantService::class, 'resolvePrioritizeCountMismatchExplanation');
        $method->setAccessible(true);

        $result = $method->invoke(
            $service,
            ['requested_count' => 3, 'actual_count' => 1, 'has_count_mismatch' => true, 'explicit_requested_count' => null],
            1,
            "Let's focus on what you've already started today and then continue."
        );

        $this->assertIsString($result);
        $this->assertStringNotContainsString('already started', mb_strtolower($result));
    }

    public function test_resolve_prioritize_count_mismatch_explanation_ignores_llm_asked_for_count_when_implicit(): void
    {
        $service = app(TaskAssistantService::class);
        $method = new ReflectionMethod(TaskAssistantService::class, 'resolvePrioritizeCountMismatchExplanation');
        $method->setAccessible(true);

        $result = $method->invoke(
            $service,
            ['requested_count' => 3, 'actual_count' => 1, 'has_count_mismatch' => true, 'explicit_requested_count' => null],
            1,
            'While you asked for 3 tasks, only 1 is currently shown in this list.'
        );

        $this->assertIsString($result);
        $this->assertStringNotContainsString('You asked for', $result);
    }

    public function test_resolve_prioritize_count_mismatch_explanation_returns_null_without_mismatch(): void
    {
        $service = app(TaskAssistantService::class);
        $method = new ReflectionMethod(TaskAssistantService::class, 'resolvePrioritizeCountMismatchExplanation');
        $method->setAccessible(true);

        $result = $method->invoke(
            $service,
            ['requested_count' => 2, 'actual_count' => 2, 'has_count_mismatch' => false, 'explicit_requested_count' => null],
            2,
            'Any text'
        );

        $this->assertNull($result);
    }
}
