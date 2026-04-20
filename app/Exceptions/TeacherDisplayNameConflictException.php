<?php

namespace App\Exceptions;

use RuntimeException;

final class TeacherDisplayNameConflictException extends RuntimeException
{
    public static function make(): self
    {
        return new self(__('A teacher with this name already exists.'));
    }
}
