<?php

namespace Tests\Unit;

use App\Services\LLM\TaskAssistant\TaskAssistantContextAnalyzer;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\StructuredResponseFake;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

class TaskAssistantContextAnalyzerTest extends TestCase
{
    public function test_it_normalizes_loose_llm_context_output(): void
    {
        Prism::fake([
            StructuredResponseFake::make()
                ->withStructured([
                    'intent_type' => 'Schedule a day',
                    'priority_filters' => [
                        'urgent tasks should be scheduled first',
                        'high priority tasks should follow',
                    ],
                    'task_keywords' => ['schedule', 'day', 'tasks', 'math'],
                    'time_constraint' => 'No strict bound, but focus today.',
                    'comparison_focus' => 'none',
                ])
                ->withUsage(new Usage(5, 10)),
        ]);

        $analyzer = new TaskAssistantContextAnalyzer;
        $analysis = $analyzer->analyzeUserContext('Schedule my day today', [
            'tasks' => [],
        ]);

        $this->assertSame(['urgent', 'high'], $analysis['priority_filters']);
        $this->assertSame(['math'], $analysis['task_keywords']);
        $this->assertSame('today', $analysis['time_constraint']);
    }
}
