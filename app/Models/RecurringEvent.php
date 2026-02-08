<?php

namespace App\Models;

use App\Enums\EventRecurrenceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'recurrence_type',
        'interval',
        'days_of_week',
        'start_datetime',
        'end_datetime',
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

    public function eventInstances(): HasMany
    {
        return $this->hasMany(EventInstance::class);
    }

    public function eventExceptions(): HasMany
    {
        return $this->hasMany(EventException::class);
    }

    /**
     * Build recurrence payload array for workspace/frontend.
     *
     * @return array{enabled: bool, type: ?string, interval: int, daysOfWeek: array<int, int>}
     */
    public static function toPayloadArray(?self $recurring): array
    {
        if ($recurring === null) {
            return ['enabled' => false, 'type' => null, 'interval' => 1, 'daysOfWeek' => []];
        }

        return [
            'enabled' => true,
            'type' => $recurring->recurrence_type?->value,
            'interval' => $recurring->interval ?? 1,
            'daysOfWeek' => $recurring->days_of_week ? (json_decode($recurring->days_of_week, true) ?? []) : [],
        ];
    }
}
