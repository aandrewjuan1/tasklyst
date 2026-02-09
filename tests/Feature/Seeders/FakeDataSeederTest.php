<?php

use App\Models\Collaboration;
use App\Models\CollaborationInvitation;
use App\Models\Comment;
use App\Models\Event;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\FakeDataSeeder;

it('seeds comments and collaborator data for workspace fake data', function (): void {
    // Ensure the base workspace user exists for the seeder.
    $baseUser = User::factory()->create([
        'email' => 'andrew.juan.cvt@eac.edu.ph',
    ]);

    app(FakeDataSeeder::class)->run();

    // The seeder should have created tasks and events for this user.
    $taskCount = Task::query()->where('user_id', $baseUser->id)->count();
    $eventCount = Event::query()->where('user_id', $baseUser->id)->count();

    expect($taskCount)->toBeGreaterThan(0)
        ->and($eventCount)->toBeGreaterThan(0);

    // Each seeded task and event should now have at least one comment.
    expect(
        Comment::query()
            ->where('commentable_type', Task::class)
            ->whereIn('commentable_id', Task::query()->where('user_id', $baseUser->id)->pluck('id'))
            ->count()
    )->toBeGreaterThan(0);

    expect(
        Comment::query()
            ->where('commentable_type', Event::class)
            ->whereIn('commentable_id', Event::query()->where('user_id', $baseUser->id)->pluck('id'))
            ->count()
    )->toBeGreaterThan(0);

    // Collaborator users should exist with the expected emails.
    $acceptedUser = User::query()->where('email', 'collab-accepted@example.test')->first();
    $pendingUser = User::query()->where('email', 'collab-pending@example.test')->first();
    $declinedUser = User::query()->where('email', 'collab-declined@example.test')->first();

    expect($acceptedUser)->not->toBeNull()
        ->and($pendingUser)->not->toBeNull()
        ->and($declinedUser)->not->toBeNull();

    // Accepted collaborator should have at least one collaboration.
    $acceptedCollaboration = Collaboration::query()
        ->where('user_id', $acceptedUser->id)
        ->count();

    expect($acceptedCollaboration)->toBeGreaterThan(0);

    $acceptedInvitation = CollaborationInvitation::query()
        ->where('invitee_email', $acceptedUser->email)
        ->where('status', 'accepted')
        ->first();

    expect($acceptedInvitation)->not->toBeNull();

    // Pending and declined invitations should also exist for their respective users.
    $pendingInvitation = CollaborationInvitation::query()
        ->where('invitee_email', $pendingUser->email)
        ->where('status', 'pending')
        ->first();

    $declinedInvitation = CollaborationInvitation::query()
        ->where('invitee_email', $declinedUser->email)
        ->where('status', 'declined')
        ->first();

    expect($pendingInvitation)->not->toBeNull()
        ->and($declinedInvitation)->not->toBeNull();
}
);
