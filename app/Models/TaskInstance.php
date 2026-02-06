<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskInstance extends Model
{
    /** @use HasFactory<\Database\Factories\TaskInstanceFactory> */
    use HasFactory;

    protected $fillable = [
        'recurring_task_id',
        'task_id',
        'instance_date',
        'status',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'instance_date' => 'date',
            'status' => TaskStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    public function recurringTask(): BelongsTo
    {
        return $this->belongsTo(RecurringTask::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
