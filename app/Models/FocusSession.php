<?php

namespace App\Models;

use App\Enums\FocusSessionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FocusSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'focusable_type',
        'focusable_id',
        'type',
        'sequence_number',
        'duration_seconds',
        'completed',
        'started_at',
        'ended_at',
        'paused_seconds',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'type' => FocusSessionType::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'completed' => 'boolean',
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function focusable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForTask(Builder $query, Task $task): Builder
    {
        return $query->where('focusable_type', $task->getMorphClass())
            ->where('focusable_id', $task->getKey());
    }

    public function scopeWork(Builder $query): Builder
    {
        return $query->where('type', FocusSessionType::Work);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('completed', true);
    }

    public function scopeInProgress(Builder $query): Builder
    {
        return $query->where('completed', false)->whereNull('ended_at');
    }

    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('started_at', today());
    }

    public function scopeThisWeek(Builder $query): Builder
    {
        return $query->whereBetween('started_at', [now()->startOfWeek(), now()->endOfWeek()]);
    }
}
