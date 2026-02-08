<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Tag extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'user_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tasks(): MorphToMany
    {
        return $this->morphedByMany(Task::class, 'taggable');
    }

    public function events(): MorphToMany
    {
        return $this->morphedByMany(Event::class, 'taggable');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByName(Builder $query, string $name): Builder
    {
        return $query->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))]);
    }

    /**
     * Filter tag IDs to only those that exist and belong to the user.
     * Prevents validation errors when the frontend has stale tag IDs (e.g. deleted tags).
     *
     * @param  array<int|string>  $tagIds
     * @return array<int>
     */
    public static function validIdsForUser(int $userId, array $tagIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $tagIds))));
        if ($ids === []) {
            return [];
        }

        return self::query()->forUser($userId)->whereIn('id', $ids)->pluck('id')->all();
    }
}
