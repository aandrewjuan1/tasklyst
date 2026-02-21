<?php

namespace App\Models;

use App\Enums\EventStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Event extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Build a friendly toast payload for Event CRUD actions.
     *
     * @return array{type: 'success'|'error'|'info', message: string, icon: string}
     */
    public static function toastPayload(string $action, bool $success, ?string $title = null): array
    {
        $trimmedTitle = $title !== null ? trim($title) : null;
        $hasTitle = $trimmedTitle !== null && $trimmedTitle !== '';

        $quotedTitle = $hasTitle ? '“'.$trimmedTitle.'”' : null;

        $type = $success ? 'success' : 'error';

        return match ($action) {
            'create' => $success
                ? [
                    'type' => $type,
                    'message' => $hasTitle ? __('Added :title.', ['title' => $quotedTitle]) : __('Added the event.'),
                    'icon' => 'plus-circle',
                ]
                : [
                    'type' => $type,
                    'message' => $hasTitle
                        ? __('Couldn’t add :title. Try again.', ['title' => $quotedTitle])
                        : __('Couldn’t add the event. Try again.'),
                    'icon' => 'exclamation-triangle',
                ],
            'update' => $success
                ? [
                    'type' => $type,
                    'message' => $hasTitle ? __('Saved changes to :title.', ['title' => $quotedTitle]) : __('Saved changes.'),
                    'icon' => 'pencil-square',
                ]
                : [
                    'type' => $type,
                    'message' => $hasTitle
                        ? __("Couldn't save changes to :title. Try again.", ['title' => $quotedTitle])
                        : __("Couldn't save changes. Try again."),
                    'icon' => 'exclamation-triangle',
                ],
            'delete' => $success
                ? [
                    'type' => $type,
                    'message' => $hasTitle ? __('Moved :title to trash.', ['title' => $quotedTitle]) : __('Moved the event to trash.'),
                    'icon' => 'trash',
                ]
                : [
                    'type' => $type,
                    'message' => $hasTitle
                        ? __('Couldn’t move :title to trash. Try again.', ['title' => $quotedTitle])
                        : __('Couldn’t move the event to trash. Try again.'),
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
     * Build a friendly toast payload for inline Event edits.
     *
     * @param  string|null  $addedTagName  When exactly one tag was added (optional).
     * @param  string|null  $removedTagName  When exactly one tag was removed (optional).
     * @return array{type: 'success'|'error'|'info', message: string, icon: string}
     */
    public static function toastPayloadForPropertyUpdate(string $property, mixed $fromValue, mixed $toValue, bool $success, ?string $eventTitle = null, ?string $addedTagName = null, ?string $removedTagName = null): array
    {
        $type = $success ? 'success' : 'error';
        $eventSuffix = self::toastEventSuffix($eventTitle);

        $propertyLabel = self::propertyLabel($property);
        $formattedFrom = self::formatPropertyValue($property, $fromValue);
        $formattedTo = self::formatPropertyValue($property, $toValue);
        $icon = self::propertyIcon($property, $success);

        if (! $success) {
            $message = $propertyLabel !== null
                ? __('Couldn’t save :property. Try again.', ['property' => $propertyLabel]).$eventSuffix
                : __('Couldn’t save changes. Try again.').$eventSuffix;

            return [
                'type' => $type,
                'message' => $message,
                'icon' => $icon,
            ];
        }

        if ($propertyLabel === null) {
            return [
                'type' => $type,
                'message' => __('Saved changes.').$eventSuffix,
                'icon' => $icon,
            ];
        }

        if ($property === 'tagIds') {
            $fromCount = is_array($fromValue) ? count($fromValue) : 0;
            $toCount = is_array($toValue) ? count($toValue) : 0;
            $trimmedTitle = $eventTitle !== null ? trim($eventTitle) : '';
            $quotedTitle = $trimmedTitle !== '' ? '"'.$trimmedTitle.'"' : null;
            $quotedTag = $addedTagName !== null && $addedTagName !== '' ? '"'.trim($addedTagName).'"' : null;
            $quotedRemovedTag = $removedTagName !== null && $removedTagName !== '' ? '"'.trim($removedTagName).'"' : null;

            $message = match (true) {
                $toCount > $fromCount => match (true) {
                    $quotedTag !== null && $quotedTitle !== null => __('Tag :tag added to :title.', ['tag' => $quotedTag, 'title' => $quotedTitle]),
                    $quotedTag !== null => __('Tag :tag added.', ['tag' => $quotedTag]),
                    $quotedTitle !== null => __('Tag added to :title.', ['title' => $quotedTitle]),
                    default => __('Tag added.'),
                },
                $toCount < $fromCount => match (true) {
                    $quotedRemovedTag !== null && $quotedTitle !== null => __('Tag :tag removed from :title.', ['tag' => $quotedRemovedTag, 'title' => $quotedTitle]),
                    $quotedRemovedTag !== null => __('Tag :tag removed.', ['tag' => $quotedRemovedTag]),
                    $quotedTitle !== null => __('Tag removed from :title.', ['title' => $quotedTitle]),
                    default => __('Tag removed.'),
                },
                default => $quotedTitle !== null
                    ? __('Tags updated on :title.', ['title' => $quotedTitle])
                    : __('Tags updated.'),
            };

            return [
                'type' => $type,
                'message' => $message,
                'icon' => $icon,
            ];
        }

        if ($formattedFrom !== null || $formattedTo !== null) {
            $message = __(':property: :from → :to.', [
                'property' => $propertyLabel,
                'from' => $formattedFrom ?? __('Not set'),
                'to' => $formattedTo ?? __('Not set'),
            ]).$eventSuffix;
        } else {
            $message = __('Saved :property.', ['property' => $propertyLabel]).$eventSuffix;
        }

        return [
            'type' => $type,
            'message' => $message,
            'icon' => $icon,
        ];
    }

    private static function toastEventSuffix(?string $eventTitle): string
    {
        $trimmed = $eventTitle !== null ? trim($eventTitle) : '';
        if ($trimmed === '') {
            return '';
        }

        return ' — '.__('Event').': '.'“'.$trimmed.'”';
    }

    private static function propertyLabel(string $property): ?string
    {
        return match ($property) {
            'title' => __('Title'),
            'description' => __('Description'),
            'status' => __('Status'),
            'startDatetime' => __('Start'),
            'endDatetime' => __('End'),
            'tagIds' => __('Tags'),
            'allDay' => __('All Day'),
            'recurrence' => __('Recurring'),
            default => null,
        };
    }

    private static function propertyIcon(string $property, bool $success): string
    {
        if (! $success) {
            return 'exclamation-triangle';
        }

        return match ($property) {
            'title', 'description' => 'pencil-square',
            'status' => 'check-circle',
            'startDatetime', 'endDatetime' => 'clock',
            'tagIds' => 'tag',
            'allDay' => 'sun',
            'recurrence' => 'arrow-path',
            default => 'pencil-square',
        };
    }

    private static function formatPropertyValue(string $property, mixed $value): ?string
    {
        return match ($property) {
            'title' => is_string($value) ? '“'.trim($value).'”' : null,
            'status' => self::enumLabel(EventStatus::class, $value),
            'startDatetime', 'endDatetime' => self::formatDatetime($value),
            'tagIds' => self::formatTagCount($value),
            'allDay' => self::formatAllDay($value),
            'recurrence' => self::formatRecurrence($value),
            'description' => is_string($value) ? '"'.trim($value).'"' : null,
            default => is_scalar($value) ? (string) $value : null,
        };
    }

    /**
     * @param  class-string  $enumClass
     */
    private static function enumLabel(string $enumClass, mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            /** @var \BackedEnum|null $enum */
            $enum = $enumClass::tryFrom($value);
        } catch (\Throwable) {
            return null;
        }

        if ($enum === null) {
            return null;
        }

        $name = $enum->name;

        return (string) preg_replace('/(?<!^)([A-Z])/', ' $1', $name);
    }

    private static function formatDatetime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return __('Not set');
        }

        try {
            return Carbon::parse((string) $value)->translatedFormat('M j, Y · g:i A');
        } catch (\Throwable) {
            return __('Not set');
        }
    }

    private static function formatTagCount(mixed $value): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        $count = count($value);
        if ($count === 0) {
            return __('None');
        }

        return (string) $count;
    }

    private static function formatAllDay(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($bool === null) {
            return null;
        }

        return $bool ? __('Yes') : __('No');
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function formatRecurrence(mixed $value): ?string
    {
        if (! is_array($value)) {
            return null;
        }

        $enabled = (bool) ($value['enabled'] ?? false);
        if (! $enabled) {
            return __('Off');
        }

        $type = (string) ($value['type'] ?? '');
        $interval = (int) ($value['interval'] ?? 1);
        $days = $value['daysOfWeek'] ?? [];

        if ($type === '') {
            return __('On');
        }

        $typeLabel = strtoupper($type);

        if ($type === 'weekly' && is_array($days) && $days !== []) {
            $labels = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];
            $dayNames = array_map(fn (int $d) => $labels[$d] ?? null, array_map('intval', $days));
            $dayNames = array_values(array_filter($dayNames));

            $intervalPart = $interval <= 1 ? 'WEEKLY' : 'EVERY '.$interval.' WEEKS';
            $daysPart = $dayNames !== [] ? ' ('.implode(', ', $dayNames).')' : '';

            return $intervalPart.$daysPart;
        }

        if ($interval <= 1) {
            return $typeLabel;
        }

        $plural = match ($type) {
            'daily' => 'DAYS',
            'weekly' => 'WEEKS',
            'monthly' => 'MONTHS',
            'yearly' => 'YEARS',
            default => $typeLabel,
        };

        return 'EVERY '.$interval.' '.$plural;
    }

    protected static function boot(): void
    {
        parent::boot();

        static::deleting(function (Event $event) {
            $event->collaborations()->delete();
            $event->collaborationInvitations()->delete();
            if ($event->isForceDeleting()) {
                $event->recurringEvent?->delete();
            }
        });
    }

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'start_datetime',
        'end_datetime',
        'all_day',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => EventStatus::class,
            'start_datetime' => 'datetime',
            'end_datetime' => 'datetime',
            'all_day' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recurringEvent(): HasOne
    {
        return $this->hasOne(RecurringEvent::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('id');
    }

    public function collaborations(): MorphMany
    {
        return $this->morphMany(Collaboration::class, 'collaboratable');
    }

    public function collaborationInvitations(): MorphMany
    {
        return $this->morphMany(CollaborationInvitation::class, 'collaboratable');
    }

    public function collaborators(): MorphToMany
    {
        return $this->morphToMany(User::class, 'collaboratable', 'collaborations')
            ->withPivot('permission')
            ->withTimestamps();
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->orderBy('tags.name');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable')->orderBy('is_pinned', 'desc')->orderByDesc('created_at');
    }

    public function scopeWithRecentComments(Builder $query, int $limit = 5): Builder
    {
        return $query->with(['comments' => fn ($q) => $q->with('user')->orderBy('is_pinned', 'desc')->orderByDesc('created_at')->limit($limit)]);
    }

    public function activityLogs(): MorphMany
    {
        return $this->morphMany(ActivityLog::class, 'loggable')->orderByDesc('created_at');
    }

    public function scopeWithRecentActivityLogs(Builder $query, int $limit = 5): Builder
    {
        return $query->with(['activityLogs' => fn ($q) => $q->with('user')->latest()->limit($limit)]);
    }

    /**
     * Map frontend property name (camelCase) to database column.
     */
    public static function propertyToColumn(string $property): string
    {
        return match ($property) {
            'startDatetime' => 'start_datetime',
            'endDatetime' => 'end_datetime',
            'allDay' => 'all_day',
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
            'status' => $this->status?->value,
            'start_datetime' => $this->start_datetime,
            'end_datetime' => $this->end_datetime,
            'all_day' => $this->all_day,
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

    public function scopeActiveForDate(Builder $query, CarbonInterface $date): Builder
    {
        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();

        return $query->where(function (Builder $dateQuery) use ($startOfDay, $endOfDay): void {
            $dateQuery
                ->whereHas('recurringEvent', function (Builder $recurringQuery) use ($startOfDay, $endOfDay): void {
                    $recurringQuery
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
     * Exclude cancelled events.
     */
    public function scopeNotCancelled(Builder $query): Builder
    {
        return $query->where('status', '!=', EventStatus::Cancelled->value);
    }

    /**
     * Exclude completed events.
     */
    public function scopeNotCompleted(Builder $query): Builder
    {
        return $query->where('status', '!=', EventStatus::Completed->value);
    }

    /**
     * Filter events by status.
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Order events by start time (chronological).
     */
    public function scopeOrderByStartTime(Builder $query): Builder
    {
        return $query->orderBy('start_datetime');
    }

    /**
     * Events with no start or end date (unscheduled).
     */
    public function scopeWithNoDate(Builder $query): Builder
    {
        return $query->whereNull('start_datetime')->whereNull('end_datetime');
    }

    /**
     * Events whose end datetime is before the given datetime (date and time).
     * Pass now() for time-aware overdue.
     */
    public function scopeOverdue(Builder $query, CarbonInterface $asOf): Builder
    {
        return $query->whereNotNull('end_datetime')
            ->where('end_datetime', '<', $asOf);
    }

    /**
     * Events starting on or after the given date.
     */
    public function scopeUpcoming(Builder $query, CarbonInterface $fromDate): Builder
    {
        return $query->whereNotNull('start_datetime')
            ->whereDate('start_datetime', '>=', $fromDate->copy()->startOfDay()->toDateString());
    }

    /**
     * Events starting within the next N days from the given date.
     */
    public function scopeStartingSoon(Builder $query, CarbonInterface $fromDate, int $days = 7): Builder
    {
        $endDate = $fromDate->copy()->addDays($days)->endOfDay();

        return $query->whereNotNull('start_datetime')
            ->whereBetween('start_datetime', [$fromDate->copy()->startOfDay(), $endDate]);
    }

    /**
     * Events where the given time is between start and end (happening now).
     */
    public function scopeHappeningNow(Builder $query, CarbonInterface $atTime): Builder
    {
        return $query->whereNotNull('start_datetime')
            ->where('start_datetime', '<=', $atTime)
            ->where(function (Builder $q) use ($atTime) {
                $q->whereNull('end_datetime')->orWhere('end_datetime', '>=', $atTime);
            });
    }

    /**
     * All-day events only.
     */
    public function scopeAllDay(Builder $query): Builder
    {
        return $query->where('all_day', true);
    }

    /**
     * Timed events only (not all-day).
     */
    public function scopeTimed(Builder $query): Builder
    {
        return $query->where('all_day', false);
    }
}
