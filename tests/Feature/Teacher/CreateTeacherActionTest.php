<?php

use App\Actions\Teacher\CreateTeacherAction;
use App\DataTransferObjects\Teacher\CreateTeacherDto;
use App\Models\User;

test('second create with same normalized name returns was existing', function (): void {
    $user = User::factory()->create();
    $action = app(CreateTeacherAction::class);

    $dto = CreateTeacherDto::fromValidated('Professor Oak');
    $first = $action->execute($user, $dto);
    $second = $action->execute($user, CreateTeacherDto::fromValidated('  PROFESSOR OAK  '));

    expect($first->wasExisting)->toBeFalse()
        ->and($second->wasExisting)->toBeTrue()
        ->and($second->teacher->id)->toBe($first->teacher->id);
});
