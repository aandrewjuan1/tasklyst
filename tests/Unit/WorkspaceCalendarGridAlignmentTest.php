<?php

use Illuminate\Support\Carbon;

it('places the first of the month in the column that matches locale start of week', function (): void {
    $calendarDate = Carbon::create(2026, 4, 1);
    $gridWeekStartDow = (int) $calendarDate->copy()->startOfWeek()->dayOfWeek;
    $daysToShowFromPreviousMonth = ($calendarDate->dayOfWeek - $gridWeekStartDow + 7) % 7;

    expect(($gridWeekStartDow + $daysToShowFromPreviousMonth) % 7)->toBe($calendarDate->dayOfWeek);
});

it('aligns Sunday April 19 2026 to the last column when the week starts Monday', function (): void {
    $firstOfApril = Carbon::create(2026, 4, 1)->locale('de');
    $gridWeekStartDow = (int) $firstOfApril->copy()->startOfWeek()->dayOfWeek;
    expect($gridWeekStartDow)->toBe(Carbon::MONDAY);

    $sunday = Carbon::create(2026, 4, 19)->locale('de');
    expect($sunday->isSunday())->toBeTrue();

    $columnIndex = ($sunday->dayOfWeek - $gridWeekStartDow + 7) % 7;
    expect($columnIndex)->toBe(6);
});
