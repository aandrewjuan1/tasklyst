<?php

namespace App\Models;

use App\Enums\MessageRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskAssistantMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'thread_id',
        'role',
        'content',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'metadata' => 'array',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(TaskAssistantThread::class);
    }

    public function schedulePlans(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssistantSchedulePlan::class, 'assistant_message_id');
    }
}
