<?php

namespace App\Enums;

enum TaskComplexity: string
{
    case Simple = 'simple';
    case Moderate = 'moderate';
    case Complex = 'complex';

    public function color(): string
    {
        return match ($this) {
            self::Simple => 'green-400',
            self::Moderate => 'yellow-400',
            self::Complex => 'red-400',
        };
    }
}
