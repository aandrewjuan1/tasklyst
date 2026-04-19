<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Teacher extends Model
{
    /** @use HasFactory<\Database\Factories\TeacherFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'name_normalized',
    ];

    protected static function booted(): void
    {
        static::saving(function (Teacher $teacher): void {
            $teacher->name_normalized = self::normalizeDisplayName($teacher->name);
        });
    }

    public static function normalizeDisplayName(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function schoolClasses(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByNormalizedName(Builder $query, string $displayName): Builder
    {
        return $query->where('name_normalized', self::normalizeDisplayName($displayName));
    }

    public static function firstOrCreateByDisplayName(int $userId, string $displayName): self
    {
        $trimmed = trim($displayName);
        $normalized = self::normalizeDisplayName($trimmed);

        return self::query()->firstOrCreate(
            [
                'user_id' => $userId,
                'name_normalized' => $normalized,
            ],
            [
                'name' => $trimmed,
            ]
        );
    }
}
