<?php

namespace App\Enums;

enum FocusModeType: string
{
    case Sprint = 'sprint';
    case Pomodoro = 'pomodoro';

    /**
     * Parse focus mode type from client input (e.g. 'countdown' or 'sprint' -> Sprint, 'pomodoro' -> Pomodoro).
     */
    public static function fromClient(?string $value): self
    {
        $normalized = $value !== null && $value !== '' ? strtolower($value) : 'sprint';

        return match ($normalized) {
            'pomodoro' => self::Pomodoro,
            'countdown', 'sprint' => self::Sprint,
            default => self::Sprint,
        };
    }
}
