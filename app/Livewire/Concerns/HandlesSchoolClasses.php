<?php

namespace App\Livewire\Concerns;

use App\DataTransferObjects\SchoolClass\CreateSchoolClassDto;
use App\Models\SchoolClass;
use App\Support\Validation\SchoolClassPayloadValidation;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;

trait HandlesSchoolClasses
{
    /**
     * @var array<string, mixed>
     */
    public array $schoolClassPayload = [];

    /**
     * School classes relevant to the selected calendar day (term overlap or recurring occurrence on that day).
     *
     * @return Collection<int, SchoolClass>
     */
    #[Computed]
    public function schoolClassesForSelectedDate(): Collection
    {
        $userId = Auth::id();
        if ($userId === null) {
            return collect();
        }

        $dayStart = $this->getParsedSelectedDate()->copy()->startOfDay();
        $dayEnd = $this->getParsedSelectedDate()->copy()->endOfDay();

        $classes = SchoolClass::query()
            ->forUser($userId)
            ->notArchived()
            ->with('recurringSchoolClass')
            ->orderBy('start_datetime')
            ->orderBy('subject_name')
            ->get();

        return $classes
            ->filter(fn (SchoolClass $class): bool => $this->schoolClassIsRelevantOnDay($class, $dayStart, $dayEnd))
            ->values();
    }

    /**
     * School classes merged into the main workspace list (with tasks, events, projects).
     * Respects item-type filters and workspace search tokens (subject / teacher).
     *
     * @return Collection<int, SchoolClass>
     */
    #[Computed]
    public function schoolClassesForWorkspaceList(): Collection
    {
        $filterItemType = property_exists($this, 'filterItemType')
            ? $this->normalizeFilterValue($this->filterItemType)
            : null;

        if ($filterItemType !== null) {
            return collect();
        }

        $classes = $this->schoolClassesForSelectedDate;

        if (! method_exists($this, 'getWorkspaceSearchTokens')) {
            return $classes;
        }

        $tokens = $this->getWorkspaceSearchTokens();
        if ($tokens === []) {
            return $classes;
        }

        return $classes
            ->filter(function (SchoolClass $class) use ($tokens): bool {
                foreach ($tokens as $token) {
                    $needle = Str::lower($token);
                    if (Str::contains(Str::lower((string) $class->subject_name), $needle)
                        || Str::contains(Str::lower((string) ($class->teacher_name ?? '')), $needle)) {
                        return true;
                    }
                }

                return false;
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createSchoolClass(array $payload): void
    {
        $user = $this->requireAuth(__('You must be logged in to create a school class.'));
        if ($user === null) {
            return;
        }

        $this->authorize('create', SchoolClass::class);

        $defaults = SchoolClassPayloadValidation::defaults();
        $defaults['recurrence'] = [
            'enabled' => false,
            'type' => null,
            'interval' => 1,
            'daysOfWeek' => [],
        ];

        $this->schoolClassPayload = array_replace_recursive($defaults, $payload);

        try {
            /** @var array{schoolClassPayload: array<string, mixed>} $validated */
            $validated = $this->validate(SchoolClassPayloadValidation::rules());
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('School class validation failed', [
                'errors' => $e->errors(),
                'payload' => $this->schoolClassPayload,
            ]);
            $this->dispatch('toast', type: 'error', message: __('Please fix the school class details and try again.'));

            return;
        }

        $inner = $validated['schoolClassPayload'];
        $dto = CreateSchoolClassDto::fromValidated($inner);

        try {
            $schoolClass = $this->createSchoolClassAction->execute($user, $dto);
        } catch (\Throwable $e) {
            Log::error('Failed to create school class from workspace.', [
                'user_id' => $user->id,
                'payload' => $this->schoolClassPayload,
                'exception' => $e,
            ]);
            $this->dispatch('toast', ...SchoolClass::toastPayload('create', false, $dto->subjectName));

            return;
        }

        $this->dispatch('school-class-created', id: $schoolClass->id, subjectName: $schoolClass->subject_name);
        $this->dispatch('toast', ...SchoolClass::toastPayload('create', true, $schoolClass->subject_name));

        if (method_exists($this, 'refreshWorkspaceItems')) {
            $this->refreshWorkspaceItems();
        }
    }

    private function schoolClassIsRelevantOnDay(SchoolClass $class, Carbon $dayStart, Carbon $dayEnd): bool
    {
        $recurring = $class->recurringSchoolClass;
        if ($recurring === null) {
            return $this->nonRecurringSchoolClassOverlapsDay($class, $dayStart, $dayEnd);
        }

        $occurrences = $this->schoolClassService->getOccurrencesForDateRange($recurring, $dayStart, $dayEnd);

        return $occurrences !== [];
    }

    private function nonRecurringSchoolClassOverlapsDay(SchoolClass $class, Carbon $dayStart, Carbon $dayEnd): bool
    {
        $start = $class->start_datetime;
        $end = $class->end_datetime;

        return $start->lte($dayEnd) && $end->gte($dayStart);
    }
}
