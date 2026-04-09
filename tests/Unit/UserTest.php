<?php

use App\Models\User;

it('returns the first word of the full name as firstName', function () {
    $user = User::factory()->make(['name' => 'Jane Marie Doe']);

    expect($user->firstName())->toBe('Jane');
});

it('returns the full string as firstName when there is a single name', function () {
    $user = User::factory()->make(['name' => 'Madonna']);

    expect($user->firstName())->toBe('Madonna');
});

it('trims and splits on repeated whitespace', function () {
    $user = User::factory()->make(['name' => "  Alex\t Lee  "]);

    expect($user->firstName())->toBe('Alex');
});

it('returns empty string when name is empty', function () {
    $user = User::factory()->make(['name' => '']);

    expect($user->firstName())->toBe('');
});
