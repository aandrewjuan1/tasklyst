<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskAssistantThread extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TaskAssistantMessage::class, 'thread_id')->orderBy('created_at');
    }

    public function schedulePlans(): HasMany
    {
        return $this->hasMany(AssistantSchedulePlan::class, 'thread_id');
    }
}
