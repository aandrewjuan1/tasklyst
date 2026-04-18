<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssistantSchedulePlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'thread_id',
        'assistant_message_id',
        'source',
        'accepted_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(TaskAssistantThread::class, 'thread_id');
    }

    public function assistantMessage(): BelongsTo
    {
        return $this->belongsTo(TaskAssistantMessage::class, 'assistant_message_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AssistantSchedulePlanItem::class);
    }
}
