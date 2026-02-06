<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventException extends Model
{
    /** @use HasFactory<\Database\Factories\EventExceptionFactory> */
    use HasFactory;

    protected $fillable = [
        'recurring_event_id',
        'exception_date',
        'is_deleted',
        'replacement_instance_id',
        'reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'exception_date' => 'date',
            'is_deleted' => 'boolean',
        ];
    }

    public function recurringEvent(): BelongsTo
    {
        return $this->belongsTo(RecurringEvent::class);
    }

    public function replacementInstance(): BelongsTo
    {
        return $this->belongsTo(EventInstance::class, 'replacement_instance_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
