<?php

namespace App\Models;

use App\Enums\EventRecurrenceType;
use App\Enums\EventStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
            'delete' => $success
                ? [
                    'type' => $type,
                    'message' => $hasTitle ? __('Deleted :title.', ['title' => $quotedTitle]) : __('Deleted the event.'),
                    'icon' => 'trash',
                ]
                : [
                    'type' => $type,
                    'message' => $hasTitle
                        ? __('Couldn’t delete :title. Try again.', ['title' => $quotedTitle])
                        : __('Couldn’t delete the event. Try again.'),
                    'icon' => 'exclamation-triangle',
                ],
            default => [
                'type' => $type,
                'message' => $success ? __('Done.') : __('Something went wrong. Please try again.'),
                'icon' => $success ? 'check-circle' : 'exclamation-triangle',
            ],
        };
    }

    protected static function boot(): void
    {
        parent::boot();

        static::deleting(function (Event $event) {
            $event->collaborations()->delete();
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

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
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
                        ->where('recurrence_type', EventRecurrenceType::Daily)
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
}
