<?php

namespace App\Models;

use App\Enums\CollaborationPermission;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class CollaborationInvitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'collaboratable_type',
        'collaboratable_id',
        'inviter_id',
        'invitee_email',
        'invitee_user_id',
        'permission',
        'token',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'permission' => CollaborationPermission::class,
            'expires_at' => 'datetime',
        ];
    }

    public function collaboratable(): MorphTo
    {
        return $this->morphTo();
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    public function invitee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitee_user_id');
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (CollaborationInvitation $invitation): void {
            if (! is_string($invitation->token) || $invitation->token === '') {
                $invitation->token = Str::uuid()->toString();
            }

            if (! is_string($invitation->status) || $invitation->status === '') {
                $invitation->status = 'pending';
            }
        });
    }
}
