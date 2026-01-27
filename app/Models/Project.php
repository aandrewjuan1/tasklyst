<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
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
}
