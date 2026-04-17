<?php

namespace App\Models;

use App\Enums\AssistantSchedulePlanItemStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
            'accepted_at' => 'datetime',
            'completed_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'metadata' => 'array',
        ];
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
