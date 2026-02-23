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
        'last_synced_at',
    ];

    protected $casts = [
        'sync_enabled' => 'bool',
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
}
