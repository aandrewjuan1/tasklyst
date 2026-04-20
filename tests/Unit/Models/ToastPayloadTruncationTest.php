<?php

use App\Models\Event;
use App\Models\Project;
use App\Models\SchoolClass;
use App\Models\Task;

it('truncates long task titles in create toast payloads', function (): void {
    $longTitle = str_repeat('TaskTitle', 30);

    $payload = Task::toastPayload('create', true, $longTitle);

    expect($payload['message'])->toContain('...');
});

it('truncates long event description values in property update payloads', function (): void {
    $longDescription = str_repeat('Event description content ', 12);

    $payload = Event::toastPayloadForPropertyUpdate(
        property: 'description',
        fromValue: 'Old',
        toValue: $longDescription,
        success: true,
        eventTitle: 'Weekly sync',
    );

    expect($payload['message'])->toContain('...');
});

it('truncates long project names in update toast payloads', function (): void {
    $longName = str_repeat('ProjectName', 25);

    $payload = Project::toastPayload('update', true, $longName);

    expect($payload['message'])->toContain('...');
});

it('truncates long school class subject names in update toast payloads', function (): void {
    $longSubjectName = str_repeat('Advanced Subject Name ', 10);

    $payload = SchoolClass::toastPayload('update', true, $longSubjectName);

    expect($payload['message'])->toContain('...');
});
