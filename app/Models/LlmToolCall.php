<?php

namespace App\Models;

use App\Enums\LlmToolCallStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LlmToolCall extends Model
{
    use HasFactory;

    protected $fillable = [
        'thread_id',
        'message_id',
        'tool_name',
        'params_json',
        'result_json',
        'status',
        'operation_token',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'params_json' => 'array',
            'result_json' => 'array',
            'status' => LlmToolCallStatus::class,
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(TaskAssistantThread::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(TaskAssistantMessage::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
