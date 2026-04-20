<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'workos_id',
        'avatar',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'workos_id',
        'remember_token',
    ];

    /**
     * The user's first given name (first word of the full name).
     */
    public function firstName(): string
    {
        $name = trim((string) $this->name);
        if ($name === '') {
            return '';
        }

        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);

        return $parts[0] ?? '';
    }

    /**
     * Get the user's initials.
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'calendar_import_past_months' => 'integer',
        ];
    }

    /**
     * Effective past lookback in calendar months for Brightspace feed imports.
     * Must be one of the values in `calendar_feeds.allowed_import_past_months`; invalid stored values fall back to default.
     */
    public function resolvedCalendarImportPastMonths(): int
    {
        /** @var list<int|string> $allowedRaw */
        $allowedRaw = config('calendar_feeds.allowed_import_past_months', [1, 3, 6]);
        $allowed = array_values(array_map(static fn (mixed $v): int => (int) $v, $allowedRaw));
        $default = (int) config('calendar_feeds.default_import_past_months');
        if (! in_array($default, $allowed, true)) {
            $default = $allowed[1] ?? $allowed[0];
        }
        $base = $this->calendar_import_past_months ?? $default;

        return in_array($base, $allowed, true) ? $base : $default;
    }

    public function collaborations(): HasMany
    {
        return $this->hasMany(Collaboration::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function taskAssistantThreads(): HasMany
    {
        return $this->hasMany(TaskAssistantThread::class);
    }

    public function assistantSchedulePlans(): HasMany
    {
        return $this->hasMany(AssistantSchedulePlan::class);
    }

    public function assistantSchedulePlanItems(): HasMany
    {
        return $this->hasMany(AssistantSchedulePlanItem::class);
    }

    public function schoolClasses(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }

    public function teachers(): HasMany
    {
        return $this->hasMany(Teacher::class);
    }

    public function pomodoroSetting(): HasOne
    {
        return $this->hasOne(PomodoroSetting::class);
    }

    /**
     * @return MorphMany<DatabaseNotification, $this>
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(DatabaseNotification::class, 'notifiable')->orderByDesc('created_at');
    }
}
