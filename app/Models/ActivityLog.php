<?php

namespace App\Models;

use App\Enums\ActivityLogAction;
use App\Enums\EventStatus;
use App\Enums\TaskStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'loggable_type',
        'loggable_id',
        'user_id',
        'action',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'action' => ActivityLogAction::class,
            'payload' => 'array',
        ];
    }

    public function loggable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForItem(Builder $query, Model $item): Builder
    {
        return $query
            ->where('loggable_type', $item->getMorphClass())
            ->where('loggable_id', $item->getKey());
    }

    public function scopeForActor(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function message(): string
    {
        $action = $this->action;
        $payload = $this->payload ?? [];

        if ($action === ActivityLogAction::FieldUpdated) {
            $field = (string) ($payload['field'] ?? '');

            // High-level labels for some complex fields.
            if ($field === 'tagIds') {
                return __('Tags updated');
            }

            if ($field === 'recurrence') {
                return __('Recurrence updated');
            }

            $from = $payload['from'] ?? null;
            $to = $payload['to'] ?? null;

            $fieldLabel = $this->fieldLabel($field);
            $fromLabel = $this->formatValueForField($field, $from);
            $toLabel = $this->formatValueForField($field, $to);

            if ($fromLabel !== null && $toLabel !== null && $fromLabel !== $toLabel) {
                return __(':field changed from :from to :to', [
                    'field' => $fieldLabel,
                    'from' => $fromLabel,
                    'to' => $toLabel,
                ]);
            }

            if (($from === null || $fromLabel === null) && $toLabel !== null) {
                return __(':field set to :to', [
                    'field' => $fieldLabel,
                    'to' => $toLabel,
                ]);
            }

            if ($to === null || $toLabel === null) {
                return __(':field cleared', [
                    'field' => $fieldLabel,
                ]);
            }

            return __(':field updated', [
                'field' => $fieldLabel,
            ]);
        }

        return $action->label();
    }

    protected function fieldLabel(string $field): string
    {
        return match ($field) {
            'name' => __('Name'),
            'title' => __('Title'),
            'description' => __('Description'),
            'status' => __('Status'),
            'priority' => __('Priority'),
            'complexity' => __('Complexity'),
            'startDatetime' => __('Start date'),
            'endDatetime' => __('End date'),
            default => ucfirst(str_replace('_', ' ', $field)),
        };
    }

    protected function formatValueForField(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($field === 'status') {
            $valueString = (string) $value;

            $taskStatus = TaskStatus::tryFrom($valueString);
            if ($taskStatus !== null) {
                return $taskStatus->label();
            }

            $eventStatus = EventStatus::tryFrom($valueString);
            if ($eventStatus !== null) {
                return $eventStatus->label();
            }

            return ucfirst(str_replace('_', ' ', $valueString));
        }

        if (in_array($field, ['startDatetime', 'endDatetime'], true)) {
            try {
                $dt = Carbon::parse((string) $value);

                return $dt->translatedFormat('M j, Y g:i A');
            } catch (\Throwable) {
                return (string) $value;
            }
        }

        if (in_array($field, ['allDay', 'all_day'], true)) {
            $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($bool === null) {
                return null;
            }

            return $bool ? __('Yes') : __('No');
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return null;
    }
}
