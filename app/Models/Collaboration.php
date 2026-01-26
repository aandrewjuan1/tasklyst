<?php

namespace App\Models;

use App\Enums\CollaborationPermission;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Collaboration extends Model
{
    use HasFactory;

    protected $fillable = [
        'collaboratable_type',
        'collaboratable_id',
        'user_id',
        'permission',
    ];

    protected function casts(): array
    {
        return [
            'permission' => CollaborationPermission::class,
        ];
    }

    public function collaboratable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
