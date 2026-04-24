<?php

namespace Tests\Unit\Scheduling;

use App\Services\LLM\Scheduling\ScheduleFallbackReasonExplainer;
use Tests\TestCase;

class ScheduleFallbackReasonExplainerTest extends TestCase
{
    public function test_summarize_returns_warm_clear_reason_lines_for_later_shortage_and_duration_mismatch(): void
    {
        $explainer = new ScheduleFallbackReasonExplainer;

        $reasons = $explainer->summarize([
            'requested_window' => [
                'start' => '23:30',
                'end' => '23:59',
            ],
            'placement_digest' => [
                'confirmation_signals' => [
                    'triggers' => ['strict_window_no_fit'],
                ],
                'unplaced_units' => [
                    [
                        'minutes' => 120,
                        'reason' => 'strict_window_no_fit',
                    ],
                ],
            ],
            'proposals' => [],
        ], 'later');

        $this->assertSame([
            'It is already late, and the remaining open blocks are too short for this task duration.',
        ], $reasons);
    }
}
