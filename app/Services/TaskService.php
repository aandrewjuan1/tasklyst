<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TaskService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createTask(User $user, array $attributes): Task
    {
        return DB::transaction(function () use ($user, $attributes): Task {
            $tagIds = $attributes['tagIds'] ?? [];
            unset($attributes['tagIds']);

            $task = Task::query()->create([
                ...$attributes,
                'user_id' => $user->id,
            ]);

            if (! empty($tagIds)) {
                $task->tags()->attach($tagIds);
            }

            return $task;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateTask(Task $task, array $attributes): Task
    {
        unset($attributes['user_id']);

        return DB::transaction(function () use ($task, $attributes): Task {
            $task->fill($attributes);
            $task->save();

            return $task;
        });
    }

    public function deleteTask(Task $task): bool
    {
        return DB::transaction(function () use ($task): bool {
            return (bool) $task->delete();
        });
    }
}
