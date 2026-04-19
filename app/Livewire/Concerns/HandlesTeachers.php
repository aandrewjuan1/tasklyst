<?php

namespace App\Livewire\Concerns;

use App\DataTransferObjects\Teacher\CreateTeacherDto;
use App\DataTransferObjects\Teacher\UpdateTeacherDto;
use App\Exceptions\TeacherCannotBeDeletedException;
use App\Exceptions\TeacherDisplayNameConflictException;
use App\Models\Teacher;
use App\Support\Validation\TeacherPayloadValidation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Renderless;

trait HandlesTeachers
{
    /**
     * @param  bool  $silentToasts  When true, do not dispatch success toasts.
     */
    #[Renderless]
    public function createTeacher(string $name, bool $silentToasts = false): void
    {
        $user = $this->requireAuth(__('You must be logged in to create teachers.'));
        if ($user === null) {
            return;
        }

        $this->authorize('create', Teacher::class);

        $payload = array_replace_recursive(TeacherPayloadValidation::defaults(), ['name' => trim($name)]);
        $validator = Validator::make($payload, TeacherPayloadValidation::rules(), TeacherPayloadValidation::messages());

        if ($validator->fails()) {
            Log::error('Teacher validation failed', [
                'errors' => $validator->errors()->all(),
                'name' => $name,
            ]);
            $this->dispatch('toast', type: 'error', message: $validator->errors()->first('name') ?: __('Please fix the teacher name and try again.'));

            return;
        }

        $validatedName = $validator->validated()['name'];
        $dto = CreateTeacherDto::fromValidated($validatedName);

        try {
            $result = $this->createTeacherAction->execute($user, $dto);
        } catch (\Throwable $e) {
            Log::error('Failed to create teacher from workspace.', [
                'user_id' => $user->id,
                'name' => $name,
                'exception' => $e,
            ]);

            $this->dispatch('toast', type: 'error', message: __('Something went wrong creating the teacher.'));

            return;
        }

        $this->dispatch('teacher-created', id: $result->teacher->id, name: $result->teacher->name);
        if (! $result->wasExisting && ! $silentToasts) {
            $this->dispatch('toast', type: 'success', message: __('Teacher created.'));
        }
        $this->dispatch('$refresh');
    }

    /**
     * @param  bool  $silentToasts  When true, do not dispatch success toasts.
     */
    #[Renderless]
    public function updateTeacher(int $teacherId, string $name, bool $silentToasts = false): void
    {
        $user = $this->requireAuth(__('You must be logged in to update teachers.'));
        if ($user === null) {
            return;
        }

        $teacher = Teacher::query()->forUser($user->id)->find($teacherId);

        if ($teacher === null) {
            if (! $silentToasts) {
                $this->dispatch('toast', type: 'error', message: __('Teacher not found.'));
            }

            return;
        }

        $this->authorize('update', $teacher);

        $payload = array_replace_recursive(TeacherPayloadValidation::defaults(), ['name' => trim($name)]);
        $validator = Validator::make($payload, TeacherPayloadValidation::rules(), TeacherPayloadValidation::messages());

        if ($validator->fails()) {
            Log::error('Teacher validation failed', [
                'errors' => $validator->errors()->all(),
                'name' => $name,
            ]);
            $this->dispatch('toast', type: 'error', message: $validator->errors()->first('name') ?: __('Please fix the teacher name and try again.'));

            return;
        }

        $validatedName = $validator->validated()['name'];
        $dto = UpdateTeacherDto::fromValidated($validatedName);

        try {
            $updated = $this->updateTeacherAction->execute($teacher, $dto);
        } catch (TeacherDisplayNameConflictException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        } catch (\Throwable $e) {
            Log::error('Failed to update teacher from workspace.', [
                'user_id' => $user->id,
                'teacher_id' => $teacherId,
                'exception' => $e,
            ]);

            $this->dispatch('toast', type: 'error', message: __('Something went wrong updating the teacher.'));

            return;
        }

        $this->dispatch('teacher-updated', id: $updated->id, name: $updated->name);
        if (! $silentToasts) {
            $this->dispatch('toast', type: 'success', message: __('Teacher updated.'));
        }
        $this->dispatch('$refresh');
    }

    /**
     * @param  bool  $silentToasts  When true, do not dispatch success toast.
     */
    #[Renderless]
    public function deleteTeacher(int $teacherId, bool $silentToasts = false): void
    {
        $user = $this->requireAuth(__('You must be logged in to delete teachers.'));
        if ($user === null) {
            return;
        }

        $teacher = Teacher::query()->forUser($user->id)->find($teacherId);

        if ($teacher === null) {
            if (! $silentToasts) {
                $this->dispatch('toast', type: 'error', message: __('Teacher not found.'));
            }

            return;
        }

        $this->authorize('delete', $teacher);

        try {
            $deleted = $this->deleteTeacherAction->execute($teacher);
        } catch (TeacherCannotBeDeletedException $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        } catch (\Throwable $e) {
            Log::error('Failed to delete teacher from workspace.', [
                'user_id' => $user->id,
                'teacher_id' => $teacherId,
                'exception' => $e,
            ]);

            $this->dispatch('toast', type: 'error', message: __('Something went wrong deleting the teacher.'));

            return;
        }

        if (! $deleted) {
            $this->dispatch('toast', type: 'error', message: __('Something went wrong deleting the teacher.'));

            return;
        }

        $this->dispatch('teacher-deleted', id: $teacherId);
        if (! $silentToasts) {
            $this->dispatch('toast', type: 'success', message: __('Teacher ":name" deleted.', ['name' => $teacher->name]));
        }
        $this->dispatch('$refresh');
    }

    /**
     * @return Collection<int, Teacher>
     */
    #[Computed]
    public function teachers(): Collection
    {
        $userId = Auth::id();

        if ($userId === null) {
            return collect();
        }

        return Teacher::query()
            ->forUser($userId)
            ->orderBy('name')
            ->get();
    }
}
