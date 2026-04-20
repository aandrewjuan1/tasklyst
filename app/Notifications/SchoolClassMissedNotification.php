<?php

namespace App\Notifications;

use App\Models\SchoolClass;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SchoolClassMissedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $schoolClassId,
        public readonly string $subjectName,
        public readonly ?string $startsAtIso = null,
        public readonly ?string $endsAtIso = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $date = null;
        if (is_string($this->startsAtIso) && $this->startsAtIso !== '') {
            try {
                $date = \Carbon\CarbonImmutable::parse($this->startsAtIso)->toDateString();
            } catch (\Throwable) {
                $date = null;
            }
        }

        return [
            'type' => 'school_class_missed',
            'title' => __('Class ended'),
            'message' => __('Class ended: :title', ['title' => '“'.$this->subjectName.'”']),
            'entity' => [
                'kind' => 'schoolClass',
                'id' => $this->schoolClassId,
                'model' => SchoolClass::class,
            ],
            'route' => 'workspace',
            'params' => array_filter([
                'date' => $date,
                'view' => 'list',
                'type' => 'classes',
                'school_class' => $this->schoolClassId,
            ], fn ($v) => $v !== null && $v !== ''),
            'meta' => [
                'starts_at' => $this->startsAtIso,
                'ends_at' => $this->endsAtIso,
            ],
        ];
    }
}
