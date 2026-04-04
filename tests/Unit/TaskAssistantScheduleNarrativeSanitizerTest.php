<?php

use App\Support\LLM\TaskAssistantScheduleNarrativeSanitizer;

test('humanizeHorizonLabel maps default_today to today', function (): void {
    expect(TaskAssistantScheduleNarrativeSanitizer::humanizeHorizonLabel('default_today'))->toBe('today');
});

test('humanizeHorizonLabel maps smart_default_spread to plain English', function (): void {
    expect(TaskAssistantScheduleNarrativeSanitizer::humanizeHorizonLabel('smart_default_spread'))->toBe('the next few days');
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

test('sanitizeStudentFacingCopy removes meal and chunk phrasing', function (): void {
    $out = TaskAssistantScheduleNarrativeSanitizer::sanitizeStudentFacingCopy(
        'Let us do this right after dinner as your main chunk and keep a focused chunk tonight.'
    );

    expect(mb_strtolower($out))->not->toContain('dinner');
    expect(mb_strtolower($out))->not->toContain('chunk');
    expect($out)->toContain('time window');
    expect($out)->toContain('block');
});

test('alignLaterTodayPhrasingWithPlacementDay rewrites later today when placement is tomorrow', function (): void {
    $out = TaskAssistantScheduleNarrativeSanitizer::alignLaterTodayPhrasingWithPlacementDay(
        'I set up work for later today and for later today you are covered.',
        '2026-04-04',
        '2026-04-05',
        'Asia/Manila'
    );

    expect(mb_strtolower($out))->not->toContain('later today');
    expect($out)->toContain('tomorrow');
});

test('alignLaterTodayPhrasingWithPlacementDay leaves text unchanged when placement is today', function (): void {
    $original = 'We can tackle this later today.';
    $out = TaskAssistantScheduleNarrativeSanitizer::alignLaterTodayPhrasingWithPlacementDay(
        $original,
        '2026-04-04',
        '2026-04-04',
        'UTC'
    );

    expect($out)->toBe($original);
});

test('alignLaterTodayPhrasingWithPlacementDay rewrites open window today when placement is tomorrow', function (): void {
    $out = TaskAssistantScheduleNarrativeSanitizer::alignLaterTodayPhrasingWithPlacementDay(
        'I shaped 3 blocks across in your open window today—each row below is one stretch.',
        '2026-04-04',
        '2026-04-05',
        'Asia/Manila'
    );

    expect(mb_strtolower($out))->not->toContain('open window today');
    expect($out)->toContain('open window for tomorrow');
});
