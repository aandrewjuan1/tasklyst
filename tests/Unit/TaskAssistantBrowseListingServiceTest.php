<?php

namespace Tests\Unit;

use App\Services\LLM\Browse\TaskAssistantBrowseListingService;
use App\Support\LLM\TaskAssistantBrowseDefaults;
use Tests\TestCase;

class TaskAssistantBrowseListingServiceTest extends TestCase
{
    public function test_ambiguous_list_request_limits_to_top_bucket(): void
    {
        $service = app(TaskAssistantBrowseListingService::class);

        $snapshot = [
            'timezone' => 'UTC',
            'tasks' => [
                [
                    'id' => 1,
                    'title' => 'Alpha',
                    'subject_name' => null,
                    'teacher_name' => null,
                    'tags' => [],
                    'status' => 'to_do',
                    'priority' => 'high',
                    'ends_at' => now()->addDay()->toIso8601String(),
                    'project_id' => null,
                    'event_id' => null,
                    'duration_minutes' => 30,
                    'complexity' => null,
                    'is_recurring' => false,
                ],
                [
                    'id' => 2,
                    'title' => 'Beta',
                    'subject_name' => null,
                    'teacher_name' => null,
                    'tags' => [],
                    'status' => 'to_do',
                    'priority' => 'medium',
                    'complexity' => null,
                    'ends_at' => now()->addDays(2)->toIso8601String(),
                    'project_id' => null,
                    'event_id' => null,
                    'duration_minutes' => 45,
                    'is_recurring' => false,
                ],
            ],
            'events' => [],
            'projects' => [],
        ];

        $result = $service->build('List my tasks', $snapshot);

        $this->assertTrue($result['ambiguous']);
        $this->assertLessThanOrEqual(5, count($result['items']));
        $this->assertStringContainsString('ordered by urgency', $result['deterministic_summary']);
        $this->assertNotEmpty($result['items'][0]['due_bucket'] ?? null);
        $this->assertNotEmpty($result['items'][0]['due_phrase'] ?? null);
        $this->assertNotEmpty($result['items'][0]['due_on'] ?? null);
        $this->assertSame(TaskAssistantBrowseDefaults::complexityNotSetLabel(), $result['items'][0]['complexity_label'] ?? null);
    }

    public function test_school_keyword_request_is_not_ambiguous(): void
    {
        $service = app(TaskAssistantBrowseListingService::class);

        $snapshot = [
            'timezone' => 'UTC',
            'tasks' => [
                [
                    'id' => 1,
                    'title' => 'School essay',
                    'subject_name' => 'English',
                    'teacher_name' => null,
                    'tags' => ['school'],
                    'status' => 'to_do',
                    'priority' => 'high',
                    'ends_at' => now()->startOfDay()->toIso8601String(),
                    'project_id' => null,
                    'event_id' => null,
                    'duration_minutes' => 60,
                    'complexity' => null,
                    'is_recurring' => false,
                ],
            ],
            'events' => [],
            'projects' => [],
        ];

        $result = $service->build('Give me my school related tasks for today', $snapshot);

        $this->assertFalse($result['ambiguous']);
        $this->assertStringContainsString('domain: school', strtolower($result['filter_context_for_prompt']));
    }
}
