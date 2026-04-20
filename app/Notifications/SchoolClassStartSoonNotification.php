<?php

namespace App\Notifications;

use App\Models\SchoolClass;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SchoolClassStartSoonNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly int $schoolClassId,
        public readonly string $subjectName,
        public readonly ?string $startsAtIso = null,
        public readonly ?string $endsAtIso = null,
        public readonly ?int $offsetMinutes = null,
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
        $message = $this->offsetMinutes !== null && $this->offsetMinutes > 0
            ? __('Class starts in :minutes min: :title', ['minutes' => $this->offsetMinutes, 'title' => '“'.$this->subjectName.'”'])
            : __('Class starting soon: :title', ['title' => '“'.$this->subjectName.'”']);

        $date = null;
        if (is_string($this->startsAtIso) && $this->startsAtIso !== '') {
            try {
                $date = \Carbon\CarbonImmutable::parse($this->startsAtIso)->toDateString();
            } catch (\Throwable) {
                $date = null;
            }
        }

        return [
            'type' => 'school_class_start_soon',
            'title' => __('Class starting soon'),
            'message' => $message,
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
                'offset_minutes' => $this->offsetMinutes,
            ],
        ];
    }
}
