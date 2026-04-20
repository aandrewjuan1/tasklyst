<?php

namespace App\Livewire\Concerns;

use App\DataTransferObjects\SchoolClass\CreateSchoolClassDto;
use App\Models\SchoolClass;
use App\Support\Validation\SchoolClassPayloadValidation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Async;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Renderless;

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

        $classesQuery = SchoolClass::query()
            ->forUser($userId)
            ->notArchived()
            ->with(['recurringSchoolClass.schoolClass', 'teacher', 'tasks'])
            ->withCount('tasks')
            ->orderBy('start_time');

        if (method_exists($this, 'applyWorkspaceSearchToSchoolClassQuery')) {
            $this->applyWorkspaceSearchToSchoolClassQuery($classesQuery);
        }

        $classes = $classesQuery
            ->orderBy('subject_name')
            ->get();

        if (method_exists($this, 'shouldSearchAllItems') && $this->shouldSearchAllItems()) {
            return $classes->values();
        }

        $dayStart = $this->getParsedSelectedDate()->copy()->startOfDay();
        $dayEnd = $this->getParsedSelectedDate()->copy()->endOfDay();

        return $this->schoolClassService->filterSchoolClassesForCalendarDay($classes, $dayStart, $dayEnd);
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

        if ($filterItemType !== null && $filterItemType !== 'classes') {
            return collect();
        }

        return $this->schoolClassesForSelectedDate;
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

        try {
            $dto = CreateSchoolClassDto::fromValidated($inner);
        } catch (\Illuminate\Validation\ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                foreach ($messages as $message) {
                    $this->addError($key, $message);
                }
            }
            $this->dispatch('toast', type: 'error', message: __('Please fix the school class details and try again.'));

            return;
        }

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
        if (method_exists($this, 'dispatchWorkspaceVisibilityToastForCreatedItem')) {
            $this->dispatchWorkspaceVisibilityToastForCreatedItem('schoolClass', $schoolClass);
        }

        if (method_exists($this, 'refreshWorkspaceCalendar')) {
            $this->refreshWorkspaceCalendar();
        }
    }

    #[Renderless]
    public function updateSchoolClassProperty(
        int $schoolClassId,
        string $property,
        mixed $value,
        bool $silentToasts = false,
        ?string $occurrenceDate = null
    ): bool|array {
        unset($occurrenceDate);

        $user = $this->requireAuth(__('You must be logged in to update school classes.'));
        if ($user === null) {
            return false;
        }

        $schoolClass = SchoolClass::query()
            ->forUser($user->id)
            ->with(['recurringSchoolClass', 'teacher'])
            ->withRecentActivityLogs(5)
            ->find($schoolClassId);

        if ($schoolClass === null) {
            $this->dispatch('toast', type: 'error', message: __('Class not found.'));

            return false;
        }

        $this->authorize('update', $schoolClass);

        if (! in_array($property, SchoolClassPayloadValidation::allowedUpdateProperties(), true)) {
            $this->dispatch('toast', type: 'error', message: __('Invalid property for update.'));

            return false;
        }

        $rules = SchoolClassPayloadValidation::rulesForProperty($property);
        if ($rules === []) {
            $this->dispatch('toast', type: 'error', message: __('Invalid property for update.'));

            return false;
        }

        $validator = Validator::make(['value' => $value], $rules);
        if ($validator->fails()) {
            $this->dispatch('toast', type: 'error', message: $validator->errors()->first('value') ?: __('Invalid value.'));

            return false;
        }

        $validatedValue = $validator->validated()['value'];
        $result = $this->updateSchoolClassPropertyAction->execute($schoolClass, $property, $validatedValue, $user);

        if (! $result->success) {
            if ($result->errorMessage !== null) {
                $this->dispatch('toast', type: 'error', message: $result->errorMessage);
            } else {
                $this->dispatch('toast', ...SchoolClass::toastPayloadForPropertyUpdate(
                    $property,
                    $result->oldValue,
                    $result->newValue,
                    false,
                    $schoolClass->subject_name
                ));
            }

            return false;
        }

        if (! $silentToasts) {
            $this->dispatch('toast', ...SchoolClass::toastPayloadForPropertyUpdate(
                $property,
                $result->oldValue,
                $result->newValue,
                true,
                $schoolClass->subject_name
            ));
        }

        $this->maybeQueueWorkspaceCalendarRefreshAfterSchoolClassPropertyUpdate($property);

        if ($property === 'recurrence') {
            $schoolClass->load('recurringSchoolClass');

            return ['success' => true, 'recurringSchoolClassId' => $schoolClass->recurringSchoolClass?->id];
        }

        return true;
    }

    /**
     * Delete a school class for the authenticated user.
     */
    #[Async]
    #[Renderless]
    public function deleteSchoolClass(int $schoolClassId): bool
    {
        $user = $this->requireAuth(__('You must be logged in to delete school classes.'));
        if ($user === null) {
            return false;
        }

        $schoolClass = SchoolClass::query()->forUser($user->id)->find($schoolClassId);

        if ($schoolClass === null) {
            $this->dispatch('toast', type: 'error', message: __('Class not found.'));

            return false;
        }

        if ((int) $schoolClass->user_id !== (int) $user->id) {
            $this->dispatch('toast', type: 'error', message: __('Only the owner can delete this class.'));

            return false;
        }

        $this->authorize('delete', $schoolClass);

        try {
            $deleted = $this->deleteSchoolClassAction->execute($schoolClass, $user);
        } catch (\Throwable $e) {
            Log::error('Failed to delete school class from workspace.', [
                'user_id' => $user->id,
                'school_class_id' => $schoolClassId,
                'exception' => $e,
            ]);

            $this->dispatch('toast', ...SchoolClass::toastPayload('delete', false, $schoolClass->subject_name));

            return false;
        }

        if (! $deleted) {
            $this->dispatch('toast', ...SchoolClass::toastPayload('delete', false, $schoolClass->subject_name));

            return false;
        }

        $this->dispatch('toast', ...SchoolClass::toastPayload('delete', true, $schoolClass->subject_name));

        if (method_exists($this, 'queueWorkspaceCalendarRefresh')) {
            $this->queueWorkspaceCalendarRefresh();
        }

        return true;
    }

    /**
     * Restore a soft-deleted school class for the authenticated user.
     */
    #[Async]
    #[Renderless]
    public function restoreSchoolClass(int $schoolClassId): bool
    {
        $user = $this->requireAuth(__('You must be logged in to restore school classes.'));
        if ($user === null) {
            return false;
        }

        $schoolClass = SchoolClass::query()->onlyTrashed()->forUser($user->id)->find($schoolClassId);

        if ($schoolClass === null) {
            $this->dispatch('toast', type: 'error', message: __('Class not found.'));

            return false;
        }

        if ((int) $schoolClass->user_id !== (int) $user->id) {
            $this->dispatch('toast', type: 'error', message: __('Only the owner can restore this class.'));

            return false;
        }

        $this->authorize('restore', $schoolClass);

        try {
            $restored = $this->restoreSchoolClassAction->execute($schoolClass, $user);
        } catch (\Throwable $e) {
            Log::error('Failed to restore school class.', [
                'user_id' => $user->id,
                'school_class_id' => $schoolClassId,
                'exception' => $e,
            ]);

            $this->dispatch('toast', type: 'error', message: __('Couldn\'t restore the class. Try again.'));

            return false;
        }

        if (! $restored) {
            $this->dispatch('toast', type: 'error', message: __('Couldn\'t restore the class. Try again.'));

            return false;
        }

        $this->dispatch('toast', type: 'success', message: __('Restored the class.'));

        if (method_exists($this, 'queueWorkspaceCalendarRefresh')) {
            $this->queueWorkspaceCalendarRefresh();
        }

        return true;
    }

    /**
     * Permanently delete a school class for the authenticated user.
     */
    #[Async]
    #[Renderless]
    public function forceDeleteSchoolClass(int $schoolClassId): bool
    {
        $user = $this->requireAuth(__('You must be logged in to permanently delete school classes.'));
        if ($user === null) {
            return false;
        }

        $schoolClass = SchoolClass::query()->withTrashed()->forUser($user->id)->find($schoolClassId);

        if ($schoolClass === null) {
            $this->dispatch('toast', type: 'error', message: __('Class not found.'));

            return false;
        }

        if ((int) $schoolClass->user_id !== (int) $user->id) {
            $this->dispatch('toast', type: 'error', message: __('Only the owner can permanently delete this class.'));

            return false;
        }

        $this->authorize('forceDelete', $schoolClass);

        try {
            $deleted = $this->forceDeleteSchoolClassAction->execute($schoolClass, $user);
        } catch (\Throwable $e) {
            Log::error('Failed to permanently delete school class.', [
                'user_id' => $user->id,
                'school_class_id' => $schoolClassId,
                'exception' => $e,
            ]);

            $this->dispatch('toast', type: 'error', message: __('Couldn\'t permanently delete the class. Try again.'));

            return false;
        }

        if (! $deleted) {
            $this->dispatch('toast', type: 'error', message: __('Couldn\'t permanently delete the class. Try again.'));

            return false;
        }

        $this->dispatch('toast', type: 'success', message: __('Permanently deleted the class.'));

        if (method_exists($this, 'queueWorkspaceCalendarRefresh')) {
            $this->queueWorkspaceCalendarRefresh();
        }

        return true;
    }

    public function syncSchoolClassesAfterTeacherDeleted(int $teacherId): void
    {
        unset($teacherId);

        if (method_exists($this, 'refreshWorkspaceItems')) {
            $this->refreshWorkspaceItems();
        }
    }

    /**
     * Load school classes for parent selection (e.g. "Put task in class" popover).
     * No date filter; returns all non-archived classes for the user.
     *
     * @return array{items: array<int, array{id: int, subject_name: string, teacher_name: string|null}>, hasMore: bool}
     */
    public function loadSchoolClassesForParentSelection(?int $cursorId = null, int $limit = 50): array
    {
        $userId = Auth::id();
        if ($userId === null) {
            return ['items' => [], 'hasMore' => false];
        }

        $query = SchoolClass::query()
            ->forUser($userId)
            ->notArchived()
            ->with('teacher')
            ->orderBy('subject_name')
            ->limit($limit + 1);

        if ($cursorId !== null) {
            $query->where('id', '>', $cursorId);
        }

        $schoolClasses = $query->get(['id', 'subject_name', 'teacher_id']);
        $hasMore = $schoolClasses->count() > $limit;
        $items = $schoolClasses->take($limit)->map(fn (SchoolClass $schoolClass) => [
            'id' => $schoolClass->id,
            'subject_name' => $schoolClass->subject_name,
            'teacher_name' => $schoolClass->teacher?->name,
        ])->values()->all();

        return ['items' => $items, 'hasMore' => $hasMore];
    }

    protected function maybeQueueWorkspaceCalendarRefreshAfterSchoolClassPropertyUpdate(string $property): void
    {
        if (! method_exists($this, 'queueWorkspaceCalendarRefresh')) {
            return;
        }

        if (! in_array($property, ['startDatetime', 'endDatetime', 'recurrence', 'subjectName'], true)) {
            return;
        }

        $this->queueWorkspaceCalendarRefresh();
    }
}
