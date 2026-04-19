<?php

namespace App\Exceptions;

use RuntimeException;

final class TeacherCannotBeDeletedException extends RuntimeException
{
    public static function make(): self
    {
        return new self(__('This teacher cannot be deleted while they are assigned to one or more school classes.'));
    }
}
