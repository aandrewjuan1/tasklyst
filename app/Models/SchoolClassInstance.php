<?php

namespace App\Models;

use App\Enums\EventStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchoolClassInstance extends Model
{
    /** @use HasFactory<\Database\Factories\SchoolClassInstanceFactory> */
    use HasFactory;

    protected $fillable = [
        'recurring_school_class_id',
        'school_class_id',
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

    public function recurringSchoolClass(): BelongsTo
    {
        return $this->belongsTo(RecurringSchoolClass::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }
}
