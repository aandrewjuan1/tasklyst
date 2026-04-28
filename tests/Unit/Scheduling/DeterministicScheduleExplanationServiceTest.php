<?php

use App\Services\LLM\Scheduling\DeterministicScheduleExplanationService;

it('selects strict window miss scenario from triggers', function (): void {
    $service = new DeterministicScheduleExplanationService;

    $out = $service->composeNormal([
        'flow_source' => 'schedule',
        'schedule_scope' => 'all_entities',
        'requested_window_label' => 'tomorrow morning',
        'requested_count' => 2,
        'placed_count' => 1,
        'unplaced_count' => 1,
        'trigger_list' => ['strict_window_no_fit', 'unplaced_units'],
        'strict_window_requested' => true,
        'blocking_reasons' => [
            ['title' => 'Physics Lecture', 'blocked_window' => '8:00 AM-10:00 AM', 'reason' => 'overlap', 'source_type' => 'class'],
            ['title' => 'Lab Prep', 'blocked_window' => '10:00 AM-12:00 PM', 'reason' => 'occupied', 'source_type' => 'class'],
        ],
        'chosen_time_label' => '1:30 PM',
    ]);

    expect(data_get($out, 'explanation_meta.scenario_key'))->toBe('STRICT_WINDOW_NO_FIT');
    expect((string) ($out['reasoning'] ?? ''))->toContain('Physics Lecture');
    expect((string) ($out['reasoning'] ?? ''))->toContain('1:30 PM');
});

it('selects prioritize schedule scope modifier when task-only source is used', function (): void {
    $service = new DeterministicScheduleExplanationService;

    $out = $service->composeNormal([
        'flow_source' => 'prioritize_schedule',
        'schedule_scope' => 'tasks_only',
        'requested_window_label' => 'today',
        'requested_count' => 3,
        'placed_count' => 3,
        'unplaced_count' => 0,
        'trigger_list' => [],
        'strict_window_requested' => false,
        'explicit_requested_window' => false,
        'requested_window_honored' => false,
        'blocking_reasons' => [],
        'chosen_time_label' => '9:00 AM',
    ]);

    expect(data_get($out, 'explanation_meta.scenario_key'))->toBe('FLOW_PRIORITIZE_SCHEDULE_TASKS_ONLY');
    expect((string) ($out['framing'] ?? ''))->toContain('top-ranked');
});

it('uses missing blocker titles fallback when unsatisfied trigger has no titles', function (): void {
    $service = new DeterministicScheduleExplanationService;

    $out = $service->composeNormal([
        'flow_source' => 'schedule',
        'schedule_scope' => 'all_entities',
        'requested_window_label' => 'morning',
        'requested_count' => 1,
        'placed_count' => 1,
        'unplaced_count' => 0,
        'trigger_list' => ['requested_window_unsatisfied'],
        'strict_window_requested' => false,
        'blocking_reasons' => [],
        'chosen_time_label' => '2:00 PM',
    ]);

    expect(data_get($out, 'explanation_meta.scenario_key'))->toBe('MISSING_BLOCKER_TITLES');
    expect((string) ($out['reasoning'] ?? ''))->toContain('occupied');
});

it('selects top n shortfall when fewer items placed than requested', function (): void {
    $service = new DeterministicScheduleExplanationService;

    $out = $service->composeNormal([
        'flow_source' => 'schedule',
        'schedule_scope' => 'all_entities',
        'requested_window_label' => 'this afternoon',
        'requested_count' => 3,
        'placed_count' => 2,
        'unplaced_count' => 1,
        'trigger_list' => ['top_n_shortfall'],
        'strict_window_requested' => false,
        'blocking_reasons' => [
            ['title' => 'Data Structures', 'blocked_window' => '1:00 PM-2:30 PM', 'reason' => 'overlap'],
        ],
        'chosen_time_label' => '3:00 PM',
    ]);

    expect(data_get($out, 'explanation_meta.scenario_key'))->toBe('TOP_N_SHORTFALL');
    expect((string) ($out['reasoning'] ?? ''))->toContain('You asked for 3');
});

it('builds deterministic confirmation narrative with scenario metadata', function (): void {
    $service = new DeterministicScheduleExplanationService;

    $out = $service->composeConfirmation([
        'reason_code' => 'top_n_shortfall',
        'requested_count' => 3,
        'placed_count' => 2,
        'requested_window_label' => 'tomorrow morning',
        'reason_message' => 'Only two fit.',
        'prompt' => 'Continue with this draft or widen the window?',
        'reason_details' => ['Class overlap reduces open time.'],
    ]);

    expect(data_get($out, 'explanation_meta.mode'))->toBe('confirmation');
    expect((string) ($out['framing'] ?? ''))->toContain('top 3');
    expect((string) ($out['confirmation'] ?? ''))->toContain('widen');
});

it('selects blocker shifted scenario and mentions up to two blocker titles with windows', function (): void {
    $service = new DeterministicScheduleExplanationService;

    $out = $service->composeNormal([
        'flow_source' => 'schedule',
        'schedule_scope' => 'all_entities',
        'requested_window_label' => 'later today',
        'requested_count' => 1,
        'placed_count' => 1,
        'unplaced_count' => 0,
        'trigger_list' => [],
        'strict_window_requested' => false,
        'blocking_reasons' => [
            ['title' => 'ELECTIVE 5', 'blocked_window' => '8:00 AM-10:00 AM', 'reason' => 'This class window overlaps your requested time.', 'source_type' => 'class'],
            ['title' => 'ELECTIVE 10', 'blocked_window' => '1:00 PM-2:00 PM', 'reason' => 'This class window overlaps your requested time.', 'source_type' => 'class'],
            ['title' => 'Client Review', 'blocked_window' => '2:00 PM-3:00 PM', 'reason' => 'This event overlaps your requested time window.', 'source_type' => 'event'],
        ],
        'chosen_time_label' => '10:15 AM',
    ]);

    expect(data_get($out, 'explanation_meta.scenario_key'))->toBe('BLOCKED_WINDOW_SHIFTED');
    expect((string) ($out['reasoning'] ?? ''))->toContain('ELECTIVE 5');
    expect((string) ($out['reasoning'] ?? ''))->toContain('ELECTIVE 10');
    expect((string) ($out['reasoning'] ?? ''))->not->toContain('Client Review');
    expect((string) ($out['reasoning'] ?? ''))->not->toContain('occupied your earlier requested window');
});

it('keeps blocker evidence for tomorrow horizon', function (): void {
    $service = new DeterministicScheduleExplanationService;

    $out = $service->composeNormal([
        'flow_source' => 'schedule',
        'schedule_scope' => 'all_entities',
        'requested_window_label' => 'tomorrow',
        'requested_count' => 1,
        'placed_count' => 1,
        'unplaced_count' => 0,
        'trigger_list' => ['requested_window_unsatisfied'],
        'strict_window_requested' => false,
        'blocking_reasons' => [
            ['title' => 'Physics Lecture', 'blocked_window' => '8:00 AM-10:00 AM', 'reason' => 'This class window overlaps your requested time.', 'source_type' => 'class'],
        ],
        'chosen_time_label' => '10:30 AM',
    ]);

    expect((string) ($out['reasoning'] ?? ''))->toContain('Physics Lecture');
    expect((string) ($out['reasoning'] ?? ''))->toContain('10:30 AM');
});

it('uses daypart reference wording for morning slot with afternoon classes', function (): void {
    $service = new DeterministicScheduleExplanationService;

    $out = $service->composeNormal([
        'flow_source' => 'schedule',
        'schedule_scope' => 'all_entities',
        'requested_window_label' => "this week's window",
        'requested_count' => 1,
        'placed_count' => 1,
        'unplaced_count' => 0,
        'trigger_list' => ['requested_window_unsatisfied'],
        'strict_window_requested' => false,
        'explicit_requested_window' => false,
        'blocking_reasons' => [
            ['title' => 'ELECTIVE 3', 'blocked_window' => '1:45 PM-6:15 PM', 'reason' => 'overlap', 'source_type' => 'class'],
            ['title' => 'YES', 'blocked_window' => '12:45 PM-2:15 PM', 'reason' => 'overlap', 'source_type' => 'class'],
        ],
        'chosen_time_label' => '8:00 AM',
    ]);

    expect(data_get($out, 'explanation_meta.scenario_key'))->toBe('BLOCKED_WINDOW_SHIFTED');
    expect((string) ($out['reasoning'] ?? ''))->toContain('morning');
    expect((string) ($out['reasoning'] ?? ''))->toContain('afternoon classes');
    expect((string) ($out['reasoning'] ?? ''))->not->toContain('occupied your earlier requested window');
});

it('builds warmer targeted schedule coaching for later windows', function (): void {
    $service = new DeterministicScheduleExplanationService;

    $out = $service->composeNormal([
        'flow_source' => 'targeted_schedule',
        'schedule_scope' => 'all_entities',
        'requested_window_label' => 'today',
        'requested_count' => 1,
        'placed_count' => 1,
        'unplaced_count' => 0,
        'trigger_list' => [],
        'strict_window_requested' => false,
        'explicit_requested_window' => true,
        'requested_window_honored' => true,
        'is_targeted_schedule' => true,
        'targeted_entity_title' => '10KM RUN',
        'time_window_hint_source' => 'later',
        'blocking_reasons' => [],
        'chosen_time_label' => '5:30 PM',
    ]);

    expect((string) data_get($out, 'explanation_meta.flow_source'))->toBe('targeted_schedule');
    expect((bool) data_get($out, 'explanation_meta.targeted_schedule'))->toBeTrue();
    expect((string) ($out['framing'] ?? ''))->toContain('10KM RUN');
    expect((string) ($out['reasoning'] ?? ''))->toContain('5:30 PM');
    expect((string) ($out['reasoning'] ?? ''))->toContain('Start gently');
    expect((string) ($out['reasoning'] ?? ''))->toContain('stopping point');
    expect((string) ($out['confirmation'] ?? ''))->toContain('5:30 PM');
});

it('requested window honored reasoning is additive and avoids conflict-free restatement', function (): void {
    $service = new DeterministicScheduleExplanationService;

    $out = $service->composeNormal([
        'flow_source' => 'schedule',
        'schedule_scope' => 'all_entities',
        'requested_window_label' => 'today',
        'requested_count' => 1,
        'placed_count' => 1,
        'unplaced_count' => 0,
        'trigger_list' => [],
        'strict_window_requested' => false,
        'explicit_requested_window' => true,
        'requested_window_honored' => true,
        'blocking_reasons' => [],
        'chosen_time_label' => '6:30 PM',
    ]);

    expect(data_get($out, 'explanation_meta.scenario_key'))->toBe('REQUESTED_WINDOW_HONORED');
    expect(mb_strtolower((string) ($out['reasoning'] ?? '')))->not->toContain('conflict-free');
    expect((string) ($out['reasoning'] ?? ''))->toContain('inside today');
});

it('appends all-day overlap note into reasoning when provided', function (): void {
    $service = new DeterministicScheduleExplanationService;

    $out = $service->composeNormal([
        'flow_source' => 'schedule',
        'schedule_scope' => 'all_entities',
        'requested_window_label' => 'today',
        'requested_count' => 1,
        'placed_count' => 1,
        'unplaced_count' => 0,
        'trigger_list' => [],
        'strict_window_requested' => false,
        'blocking_reasons' => [],
        'chosen_time_label' => '6:00 PM',
        'all_day_overlap_note' => 'Heads up: you also have all-day events on these dates: Campus Festival (2026-04-24).',
    ]);

    expect((string) ($out['reasoning'] ?? ''))->toContain('all-day events');
    expect((string) data_get($out, 'explanation_meta.all_day_overlap_note'))->toContain('Campus Festival');
});
