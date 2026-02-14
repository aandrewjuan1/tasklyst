<?php

use App\Models\Tag;
use App\Models\User;

test('tag selection embeds initial selected tags for optimistic display', function (): void {
    $user = User::factory()->create();

    $tags = Tag::factory()
        ->for($user)
        ->count(2)
        ->sequence(
            ['name' => 'Beta'],
            ['name' => 'Alpha'],
        )
        ->create();

    $html = view('components.workspace.tag-selection', [
        'selectedTags' => $tags,
        'readonly' => false,
    ])->render();

    expect($html)
        ->toContain('selectedTagPills()')
        ->toContain('mergedTags()')
        ->toContain('initialSelectedTags')
        ->toContain('x-text="tag.name"')
        ->toContain('Alpha')
        ->toContain('Beta')
        // Server-rendered tags for first paint
        ->toContain('x-show="!alpineReady"')
        // Alpine-rendered tags after hydration
        ->toContain('x-show="alpineReady"')
        ->toContain('x-init="alpineReady = true"')
        ->toContain('alpineReady: false');
});

test('tag selection shows placeholder when no tags selected', function (): void {
    $html = view('components.workspace.tag-selection', [
        'selectedTags' => [],
        'readonly' => false,
    ])->render();

    expect($html)
        ->toContain('Add tags')
        ->toContain('selectedTagPills.length === 0');
});
