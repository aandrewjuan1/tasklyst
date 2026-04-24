<?php

use App\Support\Validation\CalendarFeedPayloadValidation;
use Illuminate\Support\Facades\Validator;

it('accepts a valid eac brightspace feed url', function () {
    $data = [
        'calendarFeedPayload' => [
            'feedUrl' => 'https://eac.brightspace.com/d2l/le/calendar/feed/user/feed.ics?token=apqjq1ev4nrpqrjddc65',
            'name' => 'Brightspace – All Courses',
            'excludeOverdueItems' => true,
            'importPastMonths' => 3,
        ],
    ];

    $validator = Validator::make($data, CalendarFeedPayloadValidation::rules());

    expect($validator->fails())->toBeFalse();
});

it('rejects a non brightspace feed url', function () {
    $data = [
        'calendarFeedPayload' => [
            'feedUrl' => 'https://www.tiktok.com/@wistful.spoony/video/7603603609464622339',
            'name' => 'Some name',
            'excludeOverdueItems' => true,
            'importPastMonths' => 3,
        ],
    ];

    $validator = Validator::make($data, CalendarFeedPayloadValidation::rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('calendarFeedPayload.feedUrl'))->toBeTrue();
});
