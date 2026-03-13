<?php

namespace App\Models;

use App\Enums\ChatMessageRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'thread_id',
        'role',
        'author_id',
        'content_text',
        'content_json',
        'llm_raw',
        'meta',
        'client_request_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ChatMessageRole::class,
            'content_json' => 'array',
            'meta' => 'array',
        ];
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ChatThread::class, 'thread_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function isAssistant(): bool
    {
        return $this->role === ChatMessageRole::Assistant;
    }
}
