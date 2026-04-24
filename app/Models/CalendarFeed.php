<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CalendarFeed extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'feed_url',
        'source',
        'sync_enabled',
        'exclude_overdue_items',
        'import_past_months',
        'last_synced_at',
    ];

    protected $casts = [
        'sync_enabled' => 'bool',
        'exclude_overdue_items' => 'bool',
        'import_past_months' => 'integer',
        'last_synced_at' => 'datetime',
    ];

    protected $hidden = [
        'feed_url',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'calendar_feed_id');
    }

    public function scopeSyncEnabled($query)
    {
        return $query->where('sync_enabled', true);
    }

    public function resolvedImportPastMonths(): int
    {
        /** @var list<int|string> $allowedRaw */
        $allowedRaw = config('calendar_feeds.allowed_import_past_months', [1, 3, 6]);
        $allowed = array_values(array_map(static fn (mixed $v): int => (int) $v, $allowedRaw));

        $default = (int) config('calendar_feeds.default_import_past_months');
        if (! in_array($default, $allowed, true)) {
            $default = $allowed[1] ?? $allowed[0];
        }

        $feedValue = $this->import_past_months;
        if ($feedValue !== null && in_array($feedValue, $allowed, true)) {
            return $feedValue;
        }

        if ($this->relationLoaded('user') && $this->user instanceof User) {
            return $this->user->resolvedCalendarImportPastMonths();
        }

        return $default;
    }
}
