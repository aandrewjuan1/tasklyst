<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class SchoolClass extends Model
{
    /** @use HasFactory<\Database\Factories\SchoolClassFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'teacher_id',
        'subject_name',
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

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function recurringSchoolClass(): HasOne
    {
        return $this->hasOne(RecurringSchoolClass::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function activityLogs(): MorphMany
    {
        return $this->morphMany(ActivityLog::class, 'loggable')->orderByDesc('created_at');
    }

    public function scopeWithRecentActivityLogs(Builder $query, int $limit = 5): Builder
    {
        return $query->with(['activityLogs' => fn ($q) => $q->with('user')->latest()->limit($limit)]);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('deleted_at');
    }

    /**
     * Map frontend property name (camelCase) to database column (or service input key for teacher).
     */
    public static function propertyToColumn(string $property): string
    {
        return match ($property) {
            'subjectName' => 'subject_name',
            'teacherName' => 'teacher_name',
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
        if ($property === 'teacherName') {
            return $this->teacher?->name;
        }

        $column = self::propertyToColumn($property);

        return match ($column) {
            'start_datetime' => $this->start_datetime,
            'end_datetime' => $this->end_datetime,
            'subject_name' => $this->subject_name,
            default => $this->{$column},
        };
    }

    /**
     * Build a friendly toast payload for SchoolClass CRUD actions.
     *
     * @return array{type: 'success'|'error'|'info', message: string, icon: string}
     */
    public static function toastPayload(string $action, bool $success, ?string $subjectName = null): array
    {
        $trimmedSubject = $subjectName !== null ? trim($subjectName) : null;
        $hasSubject = $trimmedSubject !== null && $trimmedSubject !== '';

        $quotedSubject = $hasSubject ? '"'.$trimmedSubject.'"' : null;

        $type = $success ? 'success' : 'error';

        return match ($action) {
            'create' => $success
                ? [
                    'type' => $type,
                    'message' => $hasSubject ? __('Added :name.', ['name' => $quotedSubject]) : __('Added the school class.'),
                    'icon' => 'plus-circle',
                ]
                : [
                    'type' => $type,
                    'message' => $hasSubject
                        ? __('Couldn\'t add :name. Try again.', ['name' => $quotedSubject])
                        : __('Couldn\'t add the school class. Try again.'),
                    'icon' => 'exclamation-triangle',
                ],
            'update' => $success
                ? [
                    'type' => $type,
                    'message' => $hasSubject ? __('Saved changes to :name.', ['name' => $quotedSubject]) : __('Saved changes.'),
                    'icon' => 'pencil-square',
                ]
                : [
                    'type' => $type,
                    'message' => $hasSubject
                        ? __('Couldn\'t save changes to :name. Try again.', ['name' => $quotedSubject])
                        : __('Couldn\'t save changes. Try again.'),
                    'icon' => 'exclamation-triangle',
                ],
            'delete' => $success
                ? [
                    'type' => $type,
                    'message' => $hasSubject ? __('Moved :name to trash.', ['name' => $quotedSubject]) : __('Moved the school class to trash.'),
                    'icon' => 'trash',
                ]
                : [
                    'type' => $type,
                    'message' => $hasSubject
                        ? __('Couldn\'t move :name to trash. Try again.', ['name' => $quotedSubject])
                        : __('Couldn\'t move the school class to trash. Try again.'),
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
     * Build a friendly toast payload for inline SchoolClass edits.
     *
     * @return array{type: 'success'|'error'|'info', message: string, icon: string}
     */
    public static function toastPayloadForPropertyUpdate(string $property, mixed $fromValue, mixed $toValue, bool $success, ?string $subjectName = null): array
    {
        $type = $success ? 'success' : 'error';
        $classSuffix = self::toastSchoolClassSuffix($subjectName);

        $propertyLabel = self::propertyLabel($property);
        $formattedFrom = self::formatPropertyValue($property, $fromValue);
        $formattedTo = self::formatPropertyValue($property, $toValue);
        $icon = self::propertyIcon($property, $success);

        if (! $success) {
            $message = $propertyLabel !== null
                ? __('Couldn\'t save :property. Try again.', ['property' => $propertyLabel]).$classSuffix
                : __('Couldn\'t save changes. Try again.').$classSuffix;

            return [
                'type' => $type,
                'message' => $message,
                'icon' => $icon,
            ];
        }

        if ($propertyLabel === null) {
            return [
                'type' => $type,
                'message' => __('Saved changes.').$classSuffix,
                'icon' => $icon,
            ];
        }

        if ($formattedFrom !== null || $formattedTo !== null) {
            $message = __(':property: :from → :to.', [
                'property' => $propertyLabel,
                'from' => $formattedFrom ?? __('Not set'),
                'to' => $formattedTo ?? __('Not set'),
            ]).$classSuffix;
        } else {
            $message = __('Saved :property.', ['property' => $propertyLabel]).$classSuffix;
        }

        return [
            'type' => $type,
            'message' => $message,
            'icon' => $icon,
        ];
    }

    private static function toastSchoolClassSuffix(?string $subjectName): string
    {
        $trimmed = $subjectName !== null ? trim($subjectName) : '';
        if ($trimmed === '') {
            return '';
        }

        return ' — '.__('School class').': '.'"'.$trimmed.'"';
    }

    private static function propertyLabel(string $property): ?string
    {
        return match ($property) {
            'subjectName' => __('Subject'),
            'teacherName' => __('Teacher'),
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
            'subjectName', 'teacherName' => 'pencil-square',
            'startDatetime', 'endDatetime' => 'clock',
            default => 'pencil-square',
        };
    }

    private static function formatPropertyValue(string $property, mixed $value): ?string
    {
        return match ($property) {
            'subjectName', 'teacherName' => is_string($value) ? '"'.trim($value).'"' : null,
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
            return Carbon::parse((string) $value)->translatedFormat('M j, Y · g:i A');
        } catch (\Throwable) {
            return __('Not set');
        }
    }
}
