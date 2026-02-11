<?php

namespace App\Models;

use App\Enums\ActivityLogAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'loggable_type',
        'loggable_id',
        'user_id',
        'action',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'action' => ActivityLogAction::class,
            'payload' => 'array',
        ];
    }

    public function loggable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForItem(Builder $query, Model $item): Builder
    {
        return $query
            ->where('loggable_type', $item->getMorphClass())
            ->where('loggable_id', $item->getKey());
    }

    public function scopeForActor(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
