<?php

use App\Support\CourseContextPillFormatter;

it('formats honorific and surname with subject', function (): void {
    expect(CourseContextPillFormatter::compactLine('CS 220 – Data Structures', 'Prof. Miguel Santos'))
        ->toBe('Prof. Santos: CS 220 – Data Structures');
});

it('normalizes honorific casing and trailing period', function (): void {
    expect(CourseContextPillFormatter::compactLine('MATH 201', 'DR. Liza Romero'))
        ->toBe('Dr. Romero: MATH 201');
});

it('uses last word as surname when no honorific', function (): void {
    expect(CourseContextPillFormatter::compactLine('ENG 105', 'Karen Villanueva'))
        ->toBe('Villanueva: ENG 105');
});

it('returns subject only when teacher is empty', function (): void {
    expect(CourseContextPillFormatter::compactLine('ITCS 101', ''))
        ->toBe('ITCS 101');
});

it('formats teacher only with honorific and surname', function (): void {
    expect(CourseContextPillFormatter::compactLine('', 'Engr. Paolo Reyes'))
        ->toBe('Engr. Reyes');
});

it('returns teacher unchanged when honorific has no following name', function (): void {
    expect(CourseContextPillFormatter::compactLine('Some Course', 'Prof.'))
        ->toBe('Prof.: Some Course');
});

it('uses single-token teacher as surname', function (): void {
    expect(CourseContextPillFormatter::compactLine('Algorithms', 'BCPAN'))
        ->toBe('BCPAN: Algorithms');
});

it('returns null when both are empty', function (): void {
    expect(CourseContextPillFormatter::compactLine('', ''))->toBeNull();
});
