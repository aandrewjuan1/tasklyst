<?php

namespace App\Models;

use App\Enums\ReminderStatus;
use App\Enums\ReminderType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Reminder extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'remindable_type',
        'remindable_id',
        'type',
        'scheduled_at',
        'status',
        'sent_at',
        'cancelled_at',
        'snoozed_until',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'type' => ReminderType::class,
            'status' => ReminderStatus::class,
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'snoozed_until' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function remindable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ReminderStatus::Pending);
    }

    public function scopeDue(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();

        return $query
            ->pending()
            ->where(function (Builder $q) use ($now): void {
                $q->where(function (Builder $scheduled) use ($now): void {
                    $scheduled->whereNull('snoozed_until')
                        ->where('scheduled_at', '<=', $now);
                })->orWhere(function (Builder $snoozed) use ($now): void {
                    $snoozed->whereNotNull('snoozed_until')
                        ->where('snoozed_until', '<=', $now);
                });
            });
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
