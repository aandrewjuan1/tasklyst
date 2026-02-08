<?php

namespace App\Models;

use App\Enums\TaskRecurrenceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecurringTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_id',
        'recurrence_type',
        'interval',
        'start_datetime',
        'end_datetime',
        'days_of_week',
    ];

    protected function casts(): array
    {
        return [
            'recurrence_type' => TaskRecurrenceType::class,
            'start_datetime' => 'datetime',
            'end_datetime' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function taskInstances(): HasMany
    {
        return $this->hasMany(TaskInstance::class);
    }

    public function taskExceptions(): HasMany
    {
        return $this->hasMany(TaskException::class);
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
