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
            self::Simple => 'green-800',
            self::Moderate => 'yellow-800',
            self::Complex => 'red-800',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Simple => __('Simple'),
            self::Moderate => __('Moderate'),
            self::Complex => __('Complex'),
        };
    }
}
