<?php

namespace App\Models;

use App\DataTransferObjects\Llm\ConversationTurn;
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

    public function toConversationTurn(): ConversationTurn
    {
        $createdAt = $this->created_at instanceof \DateTimeImmutable
            ? $this->created_at
            : \DateTimeImmutable::createFromMutable($this->created_at);

        return new ConversationTurn(
            role: $this->role->value,
            text: $this->content_text,
            structured: $this->content_json,
            createdAt: $createdAt,
        );
    }
}
