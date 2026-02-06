<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    protected static function boot(): void
    {
        parent::boot();

        static::deleting(function (Project $project) {
            $project->collaborations()->delete();
        });
    }

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'start_datetime',
        'end_datetime',
    ];

    protected function casts(): array
    {
        return [
            'start_datetime' => 'datetime',
            'end_datetime' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
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

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('deleted_at');
    }

    public function scopeActiveForDate(Builder $query, CarbonInterface $date): Builder
    {
        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();

        return $query->where(function (Builder $dateQuery) use ($startOfDay, $endOfDay): void {
            $dateQuery
                ->where(function (Builder $noDatesQuery): void {
                    $noDatesQuery
                        ->whereNull('start_datetime')
                        ->whereNull('end_datetime');
                })
                ->orWhere(function (Builder $onlyEndQuery) use ($startOfDay): void {
                    $onlyEndQuery
                        ->whereNull('start_datetime')
                        ->whereDate('end_datetime', '>=', $startOfDay->toDateString());
                })
                ->orWhere(function (Builder $overlapQuery) use ($startOfDay, $endOfDay): void {
                    $overlapQuery
                        ->whereNotNull('start_datetime')
                        ->where(function (Builder $windowQuery) use ($startOfDay, $endOfDay): void {
                            $windowQuery
                                ->whereBetween('start_datetime', [$startOfDay, $endOfDay])
                                ->orWhere(function (Builder $rangeQuery) use ($startOfDay, $endOfDay): void {
                                    $rangeQuery
                                        ->where('start_datetime', '<=', $startOfDay)
                                        ->where(function (Builder $endQuery) use ($endOfDay): void {
                                            $endQuery
                                                ->whereNull('end_datetime')
                                                ->orWhere('end_datetime', '>=', $endOfDay);
                                        });
                                });
                        });
                });
        });
    }

    /**
     * Projects that ended before the given date (past).
     */
    public function scopeOverdue(Builder $query, CarbonInterface $asOfDate): Builder
    {
        $startOfDay = $asOfDate->copy()->startOfDay();

        return $query->whereNotNull('end_datetime')
            ->whereDate('end_datetime', '<', $startOfDay->toDateString());
    }

    /**
     * Projects starting on or after the given date.
     */
    public function scopeUpcoming(Builder $query, CarbonInterface $fromDate): Builder
    {
        return $query->whereNotNull('start_datetime')
            ->whereDate('start_datetime', '>=', $fromDate->copy()->startOfDay()->toDateString());
    }

    /**
     * Projects with no start or end date (ongoing).
     */
    public function scopeWithNoDate(Builder $query): Builder
    {
        return $query->whereNull('start_datetime')->whereNull('end_datetime');
    }

    /**
     * Order projects by start date.
     */
    public function scopeOrderByStartTime(Builder $query): Builder
    {
        return $query->orderBy('start_datetime');
    }

    /**
     * Order projects alphabetically by name.
     */
    public function scopeOrderByName(Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    /**
     * Projects starting within the next N days from the given date.
     */
    public function scopeStartingSoon(Builder $query, CarbonInterface $fromDate, int $days = 7): Builder
    {
        $endDate = $fromDate->copy()->addDays($days)->endOfDay();

        return $query->whereNotNull('start_datetime')
            ->whereBetween('start_datetime', [$fromDate->copy()->startOfDay(), $endDate]);
    }

    /**
     * Projects that have at least one incomplete task.
     */
    public function scopeWithIncompleteTasks(Builder $query): Builder
    {
        return $query->whereHas('tasks', function (Builder $q) {
            $q->whereNull('completed_at');
        });
    }

    /**
     * Projects that have at least one task.
     */
    public function scopeWithTasks(Builder $query): Builder
    {
        return $query->whereHas('tasks');
    }
}
