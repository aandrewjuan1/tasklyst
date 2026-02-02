<?php

use Illuminate\Support\Facades\Blade;

it('renders date picker days with stable keys', function (): void {
    $html = Blade::render('<x-date-picker label="Due" model="task.due_at" type="date" />');

    expect($html)->toContain('x-for="day in days"');
    expect($html)->toContain(':key="day.key"');
    expect($html)->toContain(':class="dayButtonClasses(day)"');
});
