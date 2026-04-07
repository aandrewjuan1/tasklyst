<?php

test('example', function () {
    $user = \App\Models\User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertStatus(200);
});
