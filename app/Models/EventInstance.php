<?php

namespace App\Models;

use App\Enums\EventStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventInstance extends Model
{
    /** @use HasFactory<\Database\Factories\EventInstanceFactory> */
    use HasFactory;

    protected $fillable = [
        'recurring_event_id',
        'event_id',
        'instance_date',
        'status',
        'cancelled',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'instance_date' => 'date',
            'status' => EventStatus::class,
            'cancelled' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public function recurringEvent(): BelongsTo
    {
        return $this->belongsTo(RecurringEvent::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
