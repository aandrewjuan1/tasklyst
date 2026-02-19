<?php

namespace App\ViewModels;

use App\Enums\EventStatus;
use App\Enums\TaskComplexity;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ListItemCardViewModel
{
    /** @var array<int, mixed> */
    public array $availableTags;

    public function __construct(
        public string $kind,
        public Model $item,
        public ?string $listFilterDate,
        public array $filters,
        array|Collection $availableTags,
        public bool $isOverdue,
        public ?array $activeFocusSession,
        public int $defaultWorkDurationMinutes,
        public ?array $pomodoroSettings = null,
    ) {
        $this->kind = strtolower($kind);
        $this->availableTags = is_array($availableTags) ? $availableTags : $availableTags->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        $kind = $this->kind;
        $item = $this->item;

        $title = match ($kind) {
            'project' => $item->name,
            'event' => $item->title,
            'task' => $item->title,
            default => '',
        };

        $description = match ($kind) {
            'project' => $item->description,
            'event' => $item->description,
            'task' => $item->description,
            default => null,
        };

        $type = match ($kind) {
            'project' => __('Project'),
            'event' => __('Event'),
            'task' => __('Task'),
            default => null,
        };

        $deleteMethod = match ($kind) {
            'project' => 'deleteProject',
            'event' => 'deleteEvent',
            'task' => 'deleteTask',
            default => null,
        };

        $updatePropertyMethod = match ($kind) {
            'project' => 'updateProjectProperty',
            'event' => 'updateEventProperty',
            'task' => 'updateTaskProperty',
            default => null,
        };

        $owner = $item->user ?? null;
        $hasCollaborators = ($item->collaborators ?? collect())->count() > 0;
        $currentUserIsOwner = auth()->id() && $owner && (int) auth()->id() === (int) $owner->id;
        $showOwnerBadge = $hasCollaborators && ! $currentUserIsOwner && $owner;
        $canEdit = auth()->user()?->can('update', $item) ?? false;
        $canEditTags = $currentUserIsOwner && $canEdit;
        $canEditDates = $currentUserIsOwner && $canEdit;
        $canEditRecurrence = $currentUserIsOwner && $canEdit;
        $canDelete = $currentUserIsOwner && $canEdit;

        $focusModeDefaultHint = $kind === 'task'
            ? __('Using :minutes min (default). Set duration on the task to customize.', ['minutes' => $this->defaultWorkDurationMinutes])
            : '';

        $dropdownItemClass = null;
        $statusOptions = $priorityOptions = $complexityOptions = $durationOptions = null;
        $effectiveStatus = $statusInitialOption = $priorityInitialOption = $complexityInitialOption = null;
        $statusInitialClass = $priorityInitialClass = $complexityInitialClass = $durationInitialLabel = null;
        $startDatetimeInitial = $endDatetimeInitial = null;
        $recurrenceInitial = null;

        $eventStatusOptions = $eventEffectiveStatus = $eventStatusInitialOption = null;
        $eventStatusInitialClass = $eventAllDayInitialClass = null;
        $eventStartDatetimeInitial = $eventEndDatetimeInitial = null;
        $eventRecurrenceInitial = null;

        if ($kind === 'task') {
            $dropdownItemClass = 'flex w-full items-center rounded-md px-3 py-2 text-sm text-left hover:bg-muted/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';
            $statusOptions = [
                ['value' => 'to_do', 'label' => __('To Do'), 'color' => TaskStatus::ToDo->color()],
                ['value' => 'doing', 'label' => __('Doing'), 'color' => TaskStatus::Doing->color()],
                ['value' => 'done', 'label' => __('Done'), 'color' => TaskStatus::Done->color()],
            ];
            $priorityOptions = [
                ['value' => 'low', 'label' => __('Low'), 'color' => TaskPriority::Low->color()],
                ['value' => 'medium', 'label' => __('Medium'), 'color' => TaskPriority::Medium->color()],
                ['value' => 'high', 'label' => __('High'), 'color' => TaskPriority::High->color()],
                ['value' => 'urgent', 'label' => __('Urgent'), 'color' => TaskPriority::Urgent->color()],
            ];
            $complexityOptions = [
                ['value' => 'simple', 'label' => __('Simple'), 'color' => TaskComplexity::Simple->color()],
                ['value' => 'moderate', 'label' => __('Moderate'), 'color' => TaskComplexity::Moderate->color()],
                ['value' => 'complex', 'label' => __('Complex'), 'color' => TaskComplexity::Complex->color()],
            ];
            $durationOptions = [
                ['value' => 15, 'label' => '15 min'],
                ['value' => 30, 'label' => '30 min'],
                ['value' => 60, 'label' => '1 hour'],
                ['value' => 120, 'label' => '2 hours'],
                ['value' => 240, 'label' => '4 hours'],
                ['value' => 480, 'label' => '8 hours'],
            ];

            $effectiveStatus = $item->effectiveStatusForDate ?? $item->status;
            $statusInitialOption = collect($statusOptions)->firstWhere('value', $effectiveStatus?->value);
            $priorityInitialOption = collect($priorityOptions)->firstWhere('value', $item->priority?->value);
            $complexityInitialOption = collect($complexityOptions)->firstWhere('value', $item->complexity?->value);

            $statusInitialClass = $statusInitialOption
                ? 'bg-'.$statusInitialOption['color'].'/10 text-'.$statusInitialOption['color']
                : 'bg-muted text-muted-foreground';
            $priorityInitialClass = $priorityInitialOption
                ? 'bg-'.$priorityInitialOption['color'].'/10 text-'.$priorityInitialOption['color']
                : 'bg-muted text-muted-foreground';
            $complexityInitialClass = $complexityInitialOption
                ? 'bg-'.$complexityInitialOption['color'].'/10 text-'.$complexityInitialOption['color']
                : 'bg-muted text-muted-foreground';

            $durationInitialLabel = $item->duration === null ? __('Not set') : '';
            if ($item->duration !== null) {
                $m = (int) $item->duration;
                if ($m < 60) {
                    $durationInitialLabel = $m.' '.__('min');
                } else {
                    $hours = (int) ceil($m / 60);
                    $remainder = $m % 60;
                    $hourWord = $hours === 1 ? __('hour') : Str::plural(__('hour'), 2);
                    $durationInitialLabel = $hours.' '.$hourWord;
                    if ($remainder) {
                        $durationInitialLabel .= ' '.$remainder.' '.__('min');
                    }
                }
            }

            $startDatetimeInitial = $item->start_datetime?->format('Y-m-d\TH:i:s');
            $endDatetimeInitial = $item->end_datetime?->format('Y-m-d\TH:i:s');

            $recurrenceInitial = [
                'enabled' => false,
                'type' => null,
                'interval' => 1,
                'daysOfWeek' => [],
            ];
            if ($item->recurringTask) {
                $rt = $item->recurringTask;
                $daysOfWeek = $rt->days_of_week ? (json_decode($rt->days_of_week, true) ?? []) : [];
                $recurrenceInitial = [
                    'enabled' => true,
                    'type' => $rt->recurrence_type?->value,
                    'interval' => $rt->interval ?? 1,
                    'daysOfWeek' => is_array($daysOfWeek) ? $daysOfWeek : [],
                ];
            }
        }

        if ($kind === 'event') {
            $dropdownItemClass = 'flex w-full items-center rounded-md px-3 py-2 text-sm text-left hover:bg-muted/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';

            $eventStatusOptions = [
                ['value' => EventStatus::Scheduled->value, 'label' => __('Scheduled'), 'color' => EventStatus::Scheduled->color()],
                ['value' => EventStatus::Ongoing->value, 'label' => __('Ongoing'), 'color' => EventStatus::Ongoing->color()],
                ['value' => EventStatus::Tentative->value, 'label' => __('Tentative'), 'color' => EventStatus::Tentative->color()],
                ['value' => EventStatus::Completed->value, 'label' => __('Completed'), 'color' => EventStatus::Completed->color()],
                ['value' => EventStatus::Cancelled->value, 'label' => __('Cancelled'), 'color' => EventStatus::Cancelled->color()],
            ];

            $eventEffectiveStatus = $item->effectiveStatusForDate ?? $item->status;
            $eventStatusInitialOption = collect($eventStatusOptions)->firstWhere('value', $eventEffectiveStatus?->value);

            $eventStatusInitialClass = $eventStatusInitialOption
                ? 'bg-'.$eventStatusInitialOption['color'].'/10 text-'.$eventStatusInitialOption['color']
                : 'bg-muted text-muted-foreground';

            $eventAllDayInitialClass = $item->all_day
                ? 'bg-emerald-500/10 text-emerald-500 shadow-sm'
                : 'bg-muted text-muted-foreground';

            $eventStartDatetimeInitial = $item->start_datetime?->format('Y-m-d\TH:i:s');
            $eventEndDatetimeInitial = $item->end_datetime?->format('Y-m-d\TH:i:s');

            $eventRecurrenceInitial = [
                'enabled' => false,
                'type' => null,
                'interval' => 1,
                'daysOfWeek' => [],
            ];

            if ($item->recurringEvent) {
                $re = $item->recurringEvent;
                $daysOfWeek = $re->days_of_week ? (json_decode($re->days_of_week, true) ?? []) : [];
                $eventRecurrenceInitial = [
                    'enabled' => true,
                    'type' => $re->recurrence_type?->value,
                    'interval' => $re->interval ?? 1,
                    'daysOfWeek' => is_array($daysOfWeek) ? $daysOfWeek : [],
                ];
            }
        }

        $headerRecurrenceInitial = match ($kind) {
            'task' => $recurrenceInitial ?? null,
            'event' => $eventRecurrenceInitial ?? null,
            default => null,
        };

        return [
            'kind' => $kind,
            'title' => $title,
            'description' => $description,
            'type' => $type,
            'deleteMethod' => $deleteMethod,
            'updatePropertyMethod' => $updatePropertyMethod,
            'owner' => $owner,
            'hasCollaborators' => $hasCollaborators,
            'currentUserIsOwner' => $currentUserIsOwner,
            'showOwnerBadge' => $showOwnerBadge,
            'canEdit' => $canEdit,
            'canEditTags' => $canEditTags,
            'canEditDates' => $canEditDates,
            'canEditRecurrence' => $canEditRecurrence,
            'canDelete' => $canDelete,
            'focusModeDefaultHint' => $focusModeDefaultHint,
            'dropdownItemClass' => $dropdownItemClass,
            'statusOptions' => $statusOptions,
            'priorityOptions' => $priorityOptions,
            'complexityOptions' => $complexityOptions,
            'durationOptions' => $durationOptions,
            'effectiveStatus' => $effectiveStatus,
            'statusInitialOption' => $statusInitialOption,
            'priorityInitialOption' => $priorityInitialOption,
            'complexityInitialOption' => $complexityInitialOption,
            'statusInitialClass' => $statusInitialClass,
            'priorityInitialClass' => $priorityInitialClass,
            'complexityInitialClass' => $complexityInitialClass,
            'durationInitialLabel' => $durationInitialLabel,
            'startDatetimeInitial' => $startDatetimeInitial,
            'endDatetimeInitial' => $endDatetimeInitial,
            'recurrenceInitial' => $recurrenceInitial,
            'eventStatusOptions' => $eventStatusOptions,
            'eventEffectiveStatus' => $eventEffectiveStatus,
            'eventStatusInitialOption' => $eventStatusInitialOption,
            'eventStatusInitialClass' => $eventStatusInitialClass,
            'eventAllDayInitialClass' => $eventAllDayInitialClass,
            'eventStartDatetimeInitial' => $eventStartDatetimeInitial,
            'eventEndDatetimeInitial' => $eventEndDatetimeInitial,
            'eventRecurrenceInitial' => $eventRecurrenceInitial,
            'headerRecurrenceInitial' => $headerRecurrenceInitial,
            'item' => $item,
            'listFilterDate' => $this->listFilterDate,
            'filters' => $this->filters,
            'availableTags' => $this->availableTags,
            'showSkipOccurrence' => in_array($kind, ['task', 'event'], true)
                && ($kind === 'task' ? (bool) $item->recurringTask : (bool) $item->recurringEvent)
                && $this->listFilterDate !== null
                && (string) $this->listFilterDate !== '',
            'recurringEventIdForSelection' => $kind === 'event' && $item->recurringEvent ? $item->recurringEvent->id : null,
            'recurringTaskIdForSelection' => $kind === 'task' && $item->recurringTask ? $item->recurringTask->id : null,
        ];
    }

    /**
     * Config object for listItemCard(config) Alpine component. No functions; only data.
     *
     * @return array<string, mixed>
     */
    public function alpineConfig(): array
    {
        $data = $this->viewData();
        $item = $this->item;
        $kind = $this->kind;

        $titleProperty = match ($kind) {
            'project' => 'name',
            default => 'title',
        };

        return [
            'alpineReady' => false,
            'deletingInProgress' => false,
            'dateChangeHidingCard' => false,
            'clientOverdue' => false,
            'clientNotOverdue' => false,
            'hideCard' => false,
            'dropdownOpenCount' => 0,
            'kind' => $kind,
            'listFilterDate' => $this->listFilterDate,
            'filters' => $this->filters,
            'canEdit' => $data['canEdit'],
            'canDelete' => $data['canDelete'],
            'deleteMethod' => $data['deleteMethod'],
            'itemId' => $item->id,
            'isRecurringTask' => $kind === 'task' && (bool) $item->recurringTask,
            'hasRecurringEvent' => $kind === 'event' && (bool) $item->recurringEvent,
            'showSkipOccurrence' => $data['showSkipOccurrence'],
            'recurringEventId' => $kind === 'event' && $item->recurringEvent ? $item->recurringEvent->id : null,
            'recurringTaskId' => $kind === 'task' && $item->recurringTask ? $item->recurringTask->id : null,
            'exceptionDate' => $this->listFilterDate,
            'skipInProgress' => false,
            'skipOccurrenceLabel' => __('Skip this occurrence'),
            'skipOccurrenceSkippingLabel' => __('Skipping...'),
            'skipOccurrenceErrorToast' => __('Could not skip occurrence. Please try again.'),
            'skipOccurrenceErrorPermission' => __('You do not have permission to skip this occurrence.'),
            'skipOccurrenceErrorNotFound' => __('Event or task not found.'),
            'skipOccurrenceErrorValidation' => __('Invalid request. Please try again.'),
            'recurrence' => $data['headerRecurrenceInitial'],
            'deleteErrorToast' => __('Couldn\'t move to trash. Please try again.'),
            'isEditingTitle' => false,
            'editedTitle' => $data['title'],
            'titleSnapshot' => null,
            'savingTitle' => false,
            'justCanceledTitle' => false,
            'savedViaEnter' => false,
            'updatePropertyMethod' => $data['updatePropertyMethod'],
            'titleProperty' => $titleProperty,
            'titleErrorToast' => __('Title cannot be empty.'),
            'titleUpdateErrorToast' => __('Something went wrong updating the title.'),
            'recurrenceUpdateErrorToast' => __('Something went wrong. Please try again.'),
            'descriptionUpdateErrorToast' => __("Couldn't save :property. Try again.", ['property' => __('Description')]),
            'focusStartErrorToast' => __('Could not start focus mode. Please try again.'),
            'focusStopErrorToast' => __('Could not stop focus mode. Please try again.'),
            'focusCompleteErrorToast' => __('Could not save focus session. Please try again.'),
            'focusMarkDoneErrorToast' => __('Could not mark task as done. Please try again.'),
            'focusSessionNoLongerActiveToast' => __('Focus session is no longer active.'),
            'focusModeDefaultHint' => $data['focusModeDefaultHint'],
            'isEditingDescription' => false,
            'editedDescription' => $data['description'] ?? '',
            'descriptionSnapshot' => null,
            'savingDescription' => false,
            'justCanceledDescription' => false,
            'savedDescriptionViaEnter' => false,
            'descriptionProperty' => 'description',
            'addDescriptionLabel' => __('Add description'),
            'isOverdue' => $this->isOverdue,
            'activeFocusSession' => $this->activeFocusSession,
            'pendingStartPromise' => null,
            'focusStopRequestedBeforeStartResolved' => false,
            'defaultWorkDurationMinutes' => $this->defaultWorkDurationMinutes,
            'taskDurationMinutes' => $kind === 'task' ? $item->duration : null,
            'taskStatus' => $kind === 'task' ? ($data['effectiveStatus']?->value ?? null) : null,
            'focusTickerNow' => null,
            'focusIntervalId' => null,
            'focusElapsedPercentValue' => 0,
            'focusIsPaused' => false,
            'focusPauseStartedAt' => null,
            'focusPausedSecondsAccumulated' => 0,
            '_focusJustResumed' => false,
            'sessionComplete' => false,
            'focusModeType' => 'countdown',
            'focusModeTypes' => [
                ['value' => 'countdown', 'label' => __('Sprint'), 'available' => true],
                ['value' => 'pomodoro', 'label' => __('Pomodoro'), 'available' => true],
            ],
            'focusModeComingSoonToast' => __('This focus mode is coming soon.'),
            'focusDurationLabelMin' => __('min'),
            'focusDurationLabelHr' => __('hour'),
            'focusDurationLabelHrs' => __('hours'),
            'pomodoroWorkMinutes' => $this->pomodoroSettings['work_duration_minutes'] ?? config('pomodoro.defaults.work_duration_minutes', 25),
            'pomodoroShortBreakMinutes' => $this->pomodoroSettings['short_break_minutes'] ?? config('pomodoro.defaults.short_break_minutes', 5),
            'pomodoroLongBreakMinutes' => $this->pomodoroSettings['long_break_minutes'] ?? config('pomodoro.defaults.long_break_minutes', 15),
            'pomodoroLongBreakAfter' => $this->pomodoroSettings['long_break_after_pomodoros'] ?? config('pomodoro.defaults.long_break_after_pomodoros', 4),
            'pomodoroWorkMin' => config('pomodoro.min_duration_minutes', 1),
            'pomodoroWorkMax' => config('pomodoro.max_work_duration_minutes', 120),
            'pomodoroShortBreakMax' => 60,
            'pomodoroLongBreakMax' => 60,
            'pomodoroLongBreakAfterMin' => 2,
            'pomodoroLongBreakAfterMax' => 10,
            'pomodoroAutoStartBreak' => $this->pomodoroSettings['auto_start_break'] ?? config('pomodoro.defaults.auto_start_break', false),
            'pomodoroAutoStartPomodoro' => $this->pomodoroSettings['auto_start_pomodoro'] ?? config('pomodoro.defaults.auto_start_pomodoro', false),
            'pomodoroSoundEnabled' => $this->pomodoroSettings['sound_enabled'] ?? config('pomodoro.defaults.sound_enabled', true),
            'pomodoroSoundVolume' => $this->pomodoroSettings['sound_volume'] ?? config('pomodoro.defaults.sound_volume', 80),
            'pomodoroLongBreakEveryLabel' => __('long break every'),
            'pomodoroTooltipWhat' => __('The Pomodoro Technique is a time management method: work in focused blocks (e.g. 25 minutes), then take a short break. After several blocks, take a longer break.'),
            'pomodoroTooltipHow' => __('Set your work duration, short and long break lengths, and how many work blocks before a long break. Press Start when ready.'),
            'pomodoroWorkLabel' => __('Work (minutes)'),
            'pomodoroShortBreakLabel' => __('Short break (minutes)'),
            'pomodoroLongBreakLabel' => __('Long break (minutes)'),
            'pomodoroEveryLabel' => __('Every (pomodoros)'),
            'pomodoroAutoStartBreakLabel' => __('Auto-start break'),
            'pomodoroAutoStartPomodoroLabel' => __('Auto-start next pomodoro'),
            'pomodoroSoundLabel' => __('Sound on complete'),
            'pomodoroVolumeLabel' => __('Volume'),
            'pomodoroSettingsSaveErrorToast' => __('Could not save Pomodoro settings. Please try again.'),
        ];
    }
}
