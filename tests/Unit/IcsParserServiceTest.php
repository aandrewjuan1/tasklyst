<?php

use App\Services\IcsParserService;
use Carbon\Carbon;

it('parses basic vevent fields', function () {
    $ics = <<<'ICS'
BEGIN:VCALENDAR
BEGIN:VEVENT
UID:123@example.com
SUMMARY:Midterm Exam
DESCRIPTION:Chapters 1-5
LOCATION:Room 101
DTSTART:20260301T090000Z
DTEND:20260301T100000Z
END:VEVENT
END:VCALENDAR
ICS;

    $service = new IcsParserService;

    $events = $service->parse($ics);

    expect($events)->toHaveCount(1);

    $event = $events[0];

    expect($event['uid'])->toBe('123@example.com');
    expect($event['summary'])->toBe('Midterm Exam');
    expect($event['description'])->toBe('Chapters 1-5');
    expect($event['location'])->toBe('Room 101');
    expect($event['all_day'])->toBeFalse();

    expect($event['dtstart'])->toBeInstanceOf(Carbon::class);
    expect($event['dtend'])->toBeInstanceOf(Carbon::class);
});

it('parses all-day events from value date', function () {
    $ics = <<<'ICS'
BEGIN:VCALENDAR
BEGIN:VEVENT
UID:all-day@example.com
SUMMARY:Holiday
DTSTART;VALUE=DATE:20261225
DTEND;VALUE=DATE:20261226
END:VEVENT
END:VCALENDAR
ICS;

    $service = new IcsParserService;

    $events = $service->parse($ics);

    expect($events)->toHaveCount(1);

    $event = $events[0];

    expect($event['uid'])->toBe('all-day@example.com');
    expect($event['all_day'])->toBeTrue();
    expect($event['dtstart'])->toBeInstanceOf(Carbon::class);
    expect($event['dtstart']->toDateString())->toBe('2026-12-25');
});
