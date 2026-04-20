<?php

use App\Models\Teacher;
use App\Models\User;

test('teacher names are unique per user when normalized', function (): void {
    $user = User::factory()->create();

    $a = Teacher::firstOrCreateByDisplayName($user->id, 'Dr. Smith');
    $b = Teacher::firstOrCreateByDisplayName($user->id, '  DR. SMITH  ');

    expect($a->id)->toBe($b->id)
        ->and($a->name)->toBe('Dr. Smith');
});

test('same display name normalized can exist for different users', function (): void {
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();

    $t1 = Teacher::firstOrCreateByDisplayName($u1->id, 'Ms. Lee');
    $t2 = Teacher::firstOrCreateByDisplayName($u2->id, 'Ms. Lee');

    expect($t1->id)->not->toBe($t2->id)
        ->and($t1->user_id)->toBe($u1->id)
        ->and($t2->user_id)->toBe($u2->id);
});
