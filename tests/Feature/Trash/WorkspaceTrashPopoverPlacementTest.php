<?php

use App\Models\User;

it('renders the trash popover on the workspace toolbar for authenticated users', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('workspace'));

    $response->assertSuccessful();
    $response->assertSee(__('Open trash bin'), false);
});
