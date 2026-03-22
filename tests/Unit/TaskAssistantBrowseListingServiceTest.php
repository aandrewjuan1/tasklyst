<?php

namespace Tests\Unit;

use App\Services\LLM\Browse\TaskAssistantBrowseListingService;
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
        $this->assertStringContainsString('·', $result['items'][0]['reason']);
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
                    'is_recurring' => false,
                ],
            ],
            'events' => [],
            'projects' => [],
        ];

        $result = $service->build('Give me my school related tasks for today', $snapshot);

        $this->assertFalse($result['ambiguous']);
        $this->assertStringContainsString('school', strtolower($result['filter_description']));
    }

    public function test_build_deterministic_assumptions_describes_filter_and_buckets(): void
    {
        $service = app(TaskAssistantBrowseListingService::class);

        $items = [
            ['entity_type' => 'task', 'due_bucket' => 'due_today'],
            ['entity_type' => 'task', 'due_bucket' => 'due_tomorrow'],
        ];

        $lines = $service->buildDeterministicAssumptions(false, 'time: this_week', $items);

        $this->assertNotEmpty($lines);
        $this->assertTrue(collect($lines)->contains(fn (string $l): bool => str_contains($l, 'time: this_week')));
        $this->assertTrue(collect($lines)->contains(fn (string $l): bool => str_contains($l, 'due today')));
    }
}
