<?php

namespace App\Models;

use App\Enums\EventRecurrenceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringEvent extends Model
{
    protected $fillable = [
        'event_id',
        'recurrence_type',
        'interval',
        'days_of_week',
        'start_datetime',
        'end_datetime',
        'timezone',
    ];

    protected function casts(): array
    {
        return [
            'recurrence_type' => EventRecurrenceType::class,
            'start_datetime' => 'datetime',
            'end_datetime' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
