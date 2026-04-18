<?php

namespace App\Models;

use App\Enums\AssistantSchedulePlanItemStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class AssistantSchedulePlanItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'assistant_schedule_plan_id',
        'user_id',
        'proposal_uuid',
        'proposal_id',
        'entity_type',
        'entity_id',
        'title',
        'planned_start_at',
        'planned_end_at',
        'planned_duration_minutes',
        'planned_day',
        'dedupe_key',
        'active_dedupe_unique',
        'status',
        'accepted_at',
        'completed_at',
        'dismissed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => AssistantSchedulePlanItemStatus::class,
            'planned_start_at' => 'datetime',
            'planned_end_at' => 'datetime',
            'planned_day' => 'date',
            'accepted_at' => 'datetime',
            'completed_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (AssistantSchedulePlanItem $item): void {
            if ($item->planned_start_at !== null) {
                $item->recomputeDedupeFields();
            }
            $item->syncActiveDedupeUniqueFromStatus();
        });
    }

    /**
     * Stable key: one active scheduled item per user + entity + calendar day (app timezone).
     */
    public static function buildDedupeKey(int $userId, string $entityType, int $entityId, string $plannedDayYmd): string
    {
        return $userId.'|'.$entityType.'|'.$entityId.'|'.$plannedDayYmd;
    }

    public function recomputeDedupeFields(): void
    {
        if ($this->planned_start_at === null) {
            return;
        }

        $timezone = (string) config('app.timezone', 'UTC');
        $day = Carbon::parse($this->planned_start_at)->setTimezone($timezone)->toDateString();
        $this->planned_day = $day;
        $this->dedupe_key = self::buildDedupeKey(
            (int) $this->user_id,
            (string) $this->entity_type,
            (int) $this->entity_id,
            $day,
        );
    }

    public function syncActiveDedupeUniqueFromStatus(): void
    {
        if (in_array($this->status, [
            AssistantSchedulePlanItemStatus::Planned,
            AssistantSchedulePlanItemStatus::InProgress,
        ], true)) {
            $this->active_dedupe_unique = $this->dedupe_key;
        } else {
            $this->active_dedupe_unique = null;
        }
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(AssistantSchedulePlan::class, 'assistant_schedule_plan_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            AssistantSchedulePlanItemStatus::Planned,
            AssistantSchedulePlanItemStatus::InProgress,
        ]);
    }

    public function scopeUpcomingFrom(Builder $query, ?CarbonInterface $from = null): Builder
    {
        $from ??= now();

        return $query->where('planned_start_at', '>=', $from);
    }
}
