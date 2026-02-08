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

    /**
     * Map frontend property name (camelCase) to database column.
     */
    public static function propertyToColumn(string $property): string
    {
        return match ($property) {
            'startDatetime' => 'start_datetime',
            'endDatetime' => 'end_datetime',
            default => $property,
        };
    }

    /**
     * Get current value for a property (for update toast display).
     */
    public function getPropertyValueForUpdate(string $property): mixed
    {
        $column = self::propertyToColumn($property);

        return match ($column) {
            'start_datetime' => $this->start_datetime,
            'end_datetime' => $this->end_datetime,
            default => $this->{$column},
        };
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

    /**
     * Build a friendly toast payload for Project CRUD actions.
     *
     * @return array{type: 'success'|'error'|'info', message: string, icon: string}
     */
    public static function toastPayload(string $action, bool $success, ?string $name = null): array
    {
        $trimmedName = $name !== null ? trim($name) : null;
        $hasName = $trimmedName !== null && $trimmedName !== '';

        $quotedName = $hasName ? '"'.$trimmedName.'"' : null;

        $type = $success ? 'success' : 'error';

        return match ($action) {
            'create' => $success
                ? [
                    'type' => $type,
                    'message' => $hasName ? __('Added :name.', ['name' => $quotedName]) : __('Added the project.'),
                    'icon' => 'plus-circle',
                ]
                : [
                    'type' => $type,
                    'message' => $hasName
                        ? __('Couldn\'t add :name. Try again.', ['name' => $quotedName])
                        : __('Couldn\'t add the project. Try again.'),
                    'icon' => 'exclamation-triangle',
                ],
            'update' => $success
                ? [
                    'type' => $type,
                    'message' => $hasName ? __('Saved changes to :name.', ['name' => $quotedName]) : __('Saved changes.'),
                    'icon' => 'pencil-square',
                ]
                : [
                    'type' => $type,
                    'message' => $hasName
                        ? __('Couldn\'t save changes to :name. Try again.', ['name' => $quotedName])
                        : __('Couldn\'t save changes. Try again.'),
                    'icon' => 'exclamation-triangle',
                ],
            'delete' => $success
                ? [
                    'type' => $type,
                    'message' => $hasName ? __('Deleted :name.', ['name' => $quotedName]) : __('Deleted the project.'),
                    'icon' => 'trash',
                ]
                : [
                    'type' => $type,
                    'message' => $hasName
                        ? __('Couldn\'t delete :name. Try again.', ['name' => $quotedName])
                        : __('Couldn\'t delete the project. Try again.'),
                    'icon' => 'exclamation-triangle',
                ],
            default => [
                'type' => $type,
                'message' => $success ? __('Done.') : __('Something went wrong. Please try again.'),
                'icon' => $success ? 'check-circle' : 'exclamation-triangle',
            ],
        };
    }

    /**
     * Build a friendly toast payload for inline Project edits.
     *
     * @return array{type: 'success'|'error'|'info', message: string, icon: string}
     */
    public static function toastPayloadForPropertyUpdate(string $property, mixed $fromValue, mixed $toValue, bool $success, ?string $projectName = null): array
    {
        $type = $success ? 'success' : 'error';
        $projectSuffix = self::toastProjectSuffix($projectName);

        $propertyLabel = self::propertyLabel($property);
        $formattedFrom = self::formatPropertyValue($property, $fromValue);
        $formattedTo = self::formatPropertyValue($property, $toValue);
        $icon = self::propertyIcon($property, $success);

        if (! $success) {
            $message = $propertyLabel !== null
                ? __('Couldn\'t save :property. Try again.', ['property' => $propertyLabel]).$projectSuffix
                : __('Couldn\'t save changes. Try again.').$projectSuffix;

            return [
                'type' => $type,
                'message' => $message,
                'icon' => $icon,
            ];
        }

        if ($propertyLabel === null) {
            return [
                'type' => $type,
                'message' => __('Saved changes.').$projectSuffix,
                'icon' => $icon,
            ];
        }

        if ($formattedFrom !== null || $formattedTo !== null) {
            $message = __(':property: :from → :to.', [
                'property' => $propertyLabel,
                'from' => $formattedFrom ?? __('Not set'),
                'to' => $formattedTo ?? __('Not set'),
            ]).$projectSuffix;
        } else {
            $message = __('Saved :property.', ['property' => $propertyLabel]).$projectSuffix;
        }

        return [
            'type' => $type,
            'message' => $message,
            'icon' => $icon,
        ];
    }

    private static function toastProjectSuffix(?string $projectName): string
    {
        $trimmed = $projectName !== null ? trim($projectName) : '';
        if ($trimmed === '') {
            return '';
        }

        return ' — '.__('Project').': '.'"'.$trimmed.'"';
    }

    private static function propertyLabel(string $property): ?string
    {
        return match ($property) {
            'name' => __('Name'),
            'description' => __('Description'),
            'startDatetime' => __('Start'),
            'endDatetime' => __('End'),
            default => null,
        };
    }

    private static function propertyIcon(string $property, bool $success): string
    {
        if (! $success) {
            return 'exclamation-triangle';
        }

        return match ($property) {
            'name', 'description' => 'pencil-square',
            'startDatetime', 'endDatetime' => 'clock',
            default => 'pencil-square',
        };
    }

    private static function formatPropertyValue(string $property, mixed $value): ?string
    {
        return match ($property) {
            'name', 'description' => is_string($value) ? '"'.trim($value).'"' : null,
            'startDatetime', 'endDatetime' => self::formatDatetime($value),
            default => is_scalar($value) ? (string) $value : null,
        };
    }

    private static function formatDatetime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return __('Not set');
        }

        try {
            return \Carbon\Carbon::parse((string) $value)->translatedFormat('M j, Y · g:i A');
        } catch (\Throwable) {
            return __('Not set');
        }
    }
}
