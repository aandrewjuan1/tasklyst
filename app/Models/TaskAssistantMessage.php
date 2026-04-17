<?php

namespace App\Models;

use App\Enums\MessageRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskAssistantMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'thread_id',
        'role',
        'content',
        'tool_calls',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'tool_calls' => 'array',
            'metadata' => 'array',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(TaskAssistantThread::class);
    }

    public function toolCalls(): HasMany
    {
        return $this->hasMany(LlmToolCall::class, 'message_id');
    }

    public function schedulePlans(): HasMany
    {
        return $this->hasMany(AssistantSchedulePlan::class, 'assistant_message_id');
    }
}
