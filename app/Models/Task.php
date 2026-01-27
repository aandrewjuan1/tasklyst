<?php

namespace App\Models;

use App\Enums\TaskComplexity;
use App\Enums\TaskPriority;
use App\Enums\TaskRecurrenceType;
use App\Enums\TaskStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use HasFactory, SoftDeletes;

    protected static function boot(): void
    {
        parent::boot();

        static::deleting(function (Task $task) {
            $task->collaborations()->delete();
        });
    }

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'status',
        'priority',
        'complexity',
        'duration',
        'start_datetime',
        'end_datetime',
        'project_id',
        'event_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'complexity' => TaskComplexity::class,
            'start_datetime' => 'datetime',
            'end_datetime' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function recurringTask(): HasOne
    {
        return $this->hasOne(RecurringTask::class);
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function collaborations(): MorphMany
    {
        return $this->morphMany(Collaboration::class, 'collaboratable');
    }

    public function collaborators(): MorphToMany
    {
        return $this->morphToMany(User::class, 'collaboratable', 'collaborations')
            ->withPivot('permission')
            ->withTimestamps();
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $userQuery) use ($userId): void {
            $userQuery
                ->where('user_id', $userId)
                ->orWhereHas('collaborations', function (Builder $collaborationsQuery) use ($userId): void {
                    $collaborationsQuery->where('user_id', $userId);
                });
        });
    }

    public function scopeIncomplete(Builder $query): Builder
    {
        return $query->whereNull('completed_at');
    }

    public function scopeRelevantForDate(Builder $query, CarbonInterface $date): Builder
    {
        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();

        return $query->where(function (Builder $dateQuery) use ($startOfDay, $endOfDay): void {
            $dateQuery
                ->whereHas('recurringTask', function (Builder $recurringQuery) use ($startOfDay, $endOfDay): void {
                    $recurringQuery
                        ->where('recurrence_type', TaskRecurrenceType::Daily)
                        ->where(function (Builder $startQuery) use ($endOfDay): void {
                            $startQuery
                                ->whereNull('start_datetime')
                                ->orWhereDate('start_datetime', '<=', $endOfDay);
                        })
                        ->where(function (Builder $endQuery) use ($startOfDay): void {
                            $endQuery
                                ->whereNull('end_datetime')
                                ->orWhereDate('end_datetime', '>=', $startOfDay);
                        });
                })
                ->orWhere(function (Builder $noDatesQuery): void {
                    $noDatesQuery
                        ->whereNull('start_datetime')
                        ->whereNull('end_datetime');
                })
                ->orWhere(function (Builder $onlyEndQuery): void {
                    $onlyEndQuery
                        ->whereNull('start_datetime')
                        ->whereNotNull('end_datetime');
                })
                ->orWhereDate('start_datetime', $startOfDay->toDateString());
        });
    }
}
