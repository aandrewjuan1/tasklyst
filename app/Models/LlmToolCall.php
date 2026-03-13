<?php

namespace App\Models;

use App\Enums\ToolCallStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LlmToolCall extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_request_id',
        'user_id',
        'thread_id',
        'tool',
        'args_hash',
        'tool_result_payload',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tool_result_payload' => 'array',
            'status' => ToolCallStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ChatThread::class, 'thread_id');
    }

    public static function findByRequestId(string $clientRequestId): ?self
    {
        return self::query()->where('client_request_id', $clientRequestId)->first();
    }
}
