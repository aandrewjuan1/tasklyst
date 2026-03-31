<?php

use App\Support\LLM\TaskAssistantScheduleNarrativeSanitizer;

test('humanizeHorizonLabel maps default_today to today', function (): void {
    expect(TaskAssistantScheduleNarrativeSanitizer::humanizeHorizonLabel('default_today'))->toBe('today');
});

test('horizonContextLineForPrompt uses friendly copy for single day', function (): void {
    $line = TaskAssistantScheduleNarrativeSanitizer::horizonContextLineForPrompt([
        'mode' => 'single_day',
        'start_date' => '2026-03-31',
        'end_date' => '2026-03-31',
        'label' => 'default_today',
    ]);

    expect($line)->toContain('today');
    expect($line)->toContain('2026-03-31');
    expect($line)->not->toContain('default_today');
});

test('sanitizeStudentFacingCopy removes placement jargon', function (): void {
    $out = TaskAssistantScheduleNarrativeSanitizer::sanitizeStudentFacingCopy(
        'It also keeps us within our default placement window.'
    );

    expect(mb_strtolower($out))->not->toContain('placement');
    expect($out)->not->toContain('default_today');
});

test('sanitizeStudentFacingCopy replaces BLOCKS_JSON token', function (): void {
    $out = TaskAssistantScheduleNarrativeSanitizer::sanitizeStudentFacingCopy(
        'See BLOCKS_JSON for details.'
    );

    expect($out)->not->toContain('BLOCKS_JSON');
    expect($out)->toContain('planned blocks');
});
