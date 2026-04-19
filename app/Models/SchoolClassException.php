<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchoolClassException extends Model
{
    /** @use HasFactory<\Database\Factories\SchoolClassExceptionFactory> */
    use HasFactory;

    protected $fillable = [
        'recurring_school_class_id',
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

    public function recurringSchoolClass(): BelongsTo
    {
        return $this->belongsTo(RecurringSchoolClass::class);
    }

    public function replacementInstance(): BelongsTo
    {
        return $this->belongsTo(SchoolClassInstance::class, 'replacement_instance_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
