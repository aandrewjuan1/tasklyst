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
            return Task::query()->create([
                ...$attributes,
                'user_id' => $user->id,
            ]);
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
