<?php

namespace Database\Seeders;

use App\Enums\ActivityLogAction;
use App\Enums\CollaborationPermission;
use App\Enums\EventRecurrenceType;
use App\Enums\EventStatus;
use App\Enums\TaskComplexity;
use App\Enums\TaskPriority;
use App\Enums\TaskRecurrenceType;
use App\Enums\TaskStatus;
use App\Models\ActivityLog;
use App\Models\Collaboration;
use App\Models\CollaborationInvitation;
use App\Models\Comment;
use App\Models\Event;
use App\Models\Project;
use App\Models\RecurringEvent;
use App\Models\RecurringTask;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class FullFakeDataSeeder extends Seeder
{
    private const USER_COUNT = 5;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = $this->createUsers();
        $tagsByUser = $this->createTagsPerUser($users);
        $projectsByUser = $this->createProjectsPerUser($users);
        $eventsByUser = $this->createEventsPerUser($users);
        $tasksByUser = $this->createTasksPerUser($users, $projectsByUser, $eventsByUser);
        $this->attachTagsToTasksAndEvents($users, $tasksByUser, $eventsByUser, $tagsByUser);
        $this->createComments($users, $tasksByUser, $eventsByUser, $projectsByUser);
        $this->createActivityLogs($users, $tasksByUser, $eventsByUser, $projectsByUser);
        $this->createCollaborationsAndInvitations($users, $tasksByUser, $eventsByUser, $projectsByUser);
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function createUsers(): \Illuminate\Support\Collection
    {
        $users = collect();
        for ($i = 1; $i <= self::USER_COUNT; $i++) {
            $users->push(User::factory()->create([
                'name' => fake()->unique()->name(),
                'email' => "fullfake.user{$i}@example.test",
                'workos_id' => fake()->unique()->uuid(),
            ]));
        }

        return $users;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, User>  $users
     * @return array<int, array<int, Tag>>
     */
    private function createTagsPerUser(\Illuminate\Support\Collection $users): array
    {
        $tagNames = ['work', 'personal', 'health', 'finance', 'learning', 'creative', 'urgent', 'review'];
        $byUser = [];

        foreach ($users as $user) {
            $byUser[$user->id] = [];
            $count = random_int(4, 7);
            $chosen = fake()->randomElements($tagNames, $count);
            foreach (array_unique($chosen) as $name) {
                $byUser[$user->id][] = Tag::create(['user_id' => $user->id, 'name' => $name]);
            }
        }

        return $byUser;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, User>  $users
     * @return array<int, array<int, Project>>
     */
    private function createProjectsPerUser(\Illuminate\Support\Collection $users): array
    {
        $byUser = [];
        $now = Carbon::now();

        foreach ($users as $user) {
            $byUser[$user->id] = [];
            $count = random_int(1, 3);
            for ($i = 0; $i < $count; $i++) {
                $start = $now->copy()->addDays(fake()->numberBetween(-30, 60));
                $end = $start->copy()->addDays(fake()->numberBetween(14, 90));
                $byUser[$user->id][] = Project::create([
                    'user_id' => $user->id,
                    'name' => fake()->catchPhrase(),
                    'description' => fake()->optional(0.7)->sentence(),
                    'start_datetime' => $start,
                    'end_datetime' => $end,
                ]);
            }
        }

        return $byUser;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, User>  $users
     * @return array<int, array<int, Event>>
     */
    private function createEventsPerUser(\Illuminate\Support\Collection $users): array
    {
        $byUser = [];
        $now = Carbon::now();

        foreach ($users as $user) {
            $byUser[$user->id] = [];
            $oneOffCount = random_int(2, 4);
            for ($i = 0; $i < $oneOffCount; $i++) {
                $start = $now->copy()->addDays(fake()->numberBetween(-7, 30))->setTime(fake()->numberBetween(8, 16), 0);
                $end = $start->copy()->addHours(fake()->numberBetween(1, 4));
                $byUser[$user->id][] = Event::create([
                    'user_id' => $user->id,
                    'title' => fake()->sentence(3),
                    'description' => fake()->optional(0.5)->sentence(),
                    'start_datetime' => $start,
                    'end_datetime' => $end,
                    'all_day' => fake()->boolean(25),
                    'status' => fake()->randomElement(EventStatus::cases()),
                ]);
            }
            $recurringCount = random_int(1, 2);
            for ($i = 0; $i < $recurringCount; $i++) {
                $event = $this->createRecurringEvent($user, $now);
                $byUser[$user->id][] = $event;
            }
        }

        return $byUser;
    }

    private function createRecurringEvent(User $user, Carbon $now): Event
    {
        $start = $now->copy()->addDays(fake()->numberBetween(0, 14))->startOfDay();
        $event = Event::create([
            'user_id' => $user->id,
            'title' => fake()->sentence(2),
            'description' => null,
            'start_datetime' => $start,
            'end_datetime' => $start->copy()->addHours(1),
            'all_day' => fake()->boolean(50),
            'status' => EventStatus::Scheduled,
        ]);
        RecurringEvent::create([
            'event_id' => $event->id,
            'recurrence_type' => fake()->randomElement(EventRecurrenceType::cases()),
            'interval' => 1,
            'days_of_week' => null,
            'start_datetime' => $start,
            'end_datetime' => fake()->optional(0.3)->dateTimeBetween($start, $start->copy()->addMonths(3)),
        ]);

        return $event;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, User>  $users
     * @param  array<int, array<int, Project>>  $projectsByUser
     * @param  array<int, array<int, Event>>  $eventsByUser
     * @return array<int, array<int, Task>>
     */
    private function createTasksPerUser(
        \Illuminate\Support\Collection $users,
        array $projectsByUser,
        array $eventsByUser
    ): array {
        $byUser = [];
        $now = Carbon::now();

        foreach ($users as $user) {
            $byUser[$user->id] = [];
            $projects = $projectsByUser[$user->id] ?? [];
            $events = $eventsByUser[$user->id] ?? [];

            $oneOffCount = random_int(3, 6);
            for ($i = 0; $i < $oneOffCount; $i++) {
                $project = $projects !== [] ? fake()->optional(0.5)->randomElement($projects) : null;
                $event = $events !== [] ? fake()->optional(0.2)->randomElement($events) : null;
                $start = $now->copy()->addDays(fake()->numberBetween(-14, 21));
                $end = $start->copy()->addDays(fake()->numberBetween(0, 7));
                $byUser[$user->id][] = Task::create([
                    'user_id' => $user->id,
                    'title' => fake()->sentence(4),
                    'description' => fake()->optional(0.5)->sentence(),
                    'status' => fake()->randomElement(TaskStatus::cases()),
                    'priority' => fake()->randomElement(TaskPriority::cases()),
                    'complexity' => fake()->randomElement(TaskComplexity::cases()),
                    'duration' => fake()->optional(0.7)->numberBetween(15, 240),
                    'start_datetime' => fake()->optional(0.6)->dateTimeBetween($start, $end),
                    'end_datetime' => fake()->optional(0.7)->dateTimeBetween($start, $end->copy()->addDays(14)),
                    'project_id' => $project?->id,
                    'event_id' => $event?->id,
                    'completed_at' => null,
                ]);
            }
            $recurringCount = random_int(1, 3);
            for ($i = 0; $i < $recurringCount; $i++) {
                $task = $this->createRecurringTask($user, $now);
                $byUser[$user->id][] = $task;
            }
        }

        return $byUser;
    }

    private function createRecurringTask(User $user, Carbon $now): Task
    {
        $task = Task::create([
            'user_id' => $user->id,
            'title' => fake()->sentence(3),
            'description' => null,
            'status' => TaskStatus::ToDo,
            'priority' => fake()->randomElement(TaskPriority::cases()),
            'complexity' => TaskComplexity::Moderate,
            'duration' => fake()->numberBetween(30, 120),
            'start_datetime' => null,
            'end_datetime' => null,
            'project_id' => null,
            'event_id' => null,
            'completed_at' => null,
        ]);
        RecurringTask::create([
            'task_id' => $task->id,
            'recurrence_type' => fake()->randomElement(TaskRecurrenceType::cases()),
            'interval' => 1,
            'start_datetime' => $now->copy()->startOfDay(),
            'end_datetime' => null,
            'days_of_week' => null,
        ]);

        return $task;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, User>  $users
     * @param  array<int, array<int, Task>>  $tasksByUser
     * @param  array<int, array<int, Event>>  $eventsByUser
     * @param  array<int, array<int, Tag>>  $tagsByUser
     */
    private function attachTagsToTasksAndEvents(
        \Illuminate\Support\Collection $users,
        array $tasksByUser,
        array $eventsByUser,
        array $tagsByUser
    ): void {
        foreach ($users as $user) {
            $tags = $tagsByUser[$user->id] ?? [];
            if ($tags === []) {
                continue;
            }
            $tagIds = collect($tags)->pluck('id')->all();
            foreach ($tasksByUser[$user->id] ?? [] as $task) {
                $take = min(random_int(0, 3), count($tagIds));
                if ($take > 0) {
                    $task->tags()->attach(fake()->randomElements($tagIds, $take));
                }
            }
            foreach ($eventsByUser[$user->id] ?? [] as $event) {
                $take = min(random_int(0, 2), count($tagIds));
                if ($take > 0) {
                    $event->tags()->attach(fake()->randomElements($tagIds, $take));
                }
            }
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, User>  $users
     * @param  array<int, array<int, Task>>  $tasksByUser
     * @param  array<int, array<int, Event>>  $eventsByUser
     * @param  array<int, array<int, Project>>  $projectsByUser
     */
    private function createComments(
        \Illuminate\Support\Collection $users,
        array $tasksByUser,
        array $eventsByUser,
        array $projectsByUser
    ): void {
        foreach ($users as $user) {
            foreach ($tasksByUser[$user->id] ?? [] as $task) {
                Comment::factory()
                    ->count(random_int(0, 2))
                    ->create([
                        'commentable_id' => $task->id,
                        'commentable_type' => Task::class,
                        'user_id' => $user->id,
                    ]);
            }
            foreach ($eventsByUser[$user->id] ?? [] as $event) {
                Comment::factory()
                    ->count(random_int(0, 2))
                    ->create([
                        'commentable_id' => $event->id,
                        'commentable_type' => Event::class,
                        'user_id' => $user->id,
                    ]);
            }
            foreach ($projectsByUser[$user->id] ?? [] as $project) {
                Comment::factory()
                    ->count(random_int(0, 2))
                    ->create([
                        'commentable_id' => $project->id,
                        'commentable_type' => Project::class,
                        'user_id' => $user->id,
                    ]);
            }
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, User>  $users
     * @param  array<int, array<int, Task>>  $tasksByUser
     * @param  array<int, array<int, Event>>  $eventsByUser
     * @param  array<int, array<int, Project>>  $projectsByUser
     */
    private function createActivityLogs(
        \Illuminate\Support\Collection $users,
        array $tasksByUser,
        array $eventsByUser,
        array $projectsByUser
    ): void {
        foreach ($users as $user) {
            foreach ($tasksByUser[$user->id] ?? [] as $task) {
                ActivityLog::create([
                    'loggable_type' => Task::class,
                    'loggable_id' => $task->id,
                    'user_id' => $user->id,
                    'action' => ActivityLogAction::ItemCreated,
                    'payload' => [],
                ]);
                if (fake()->boolean(40)) {
                    ActivityLog::create([
                        'loggable_type' => Task::class,
                        'loggable_id' => $task->id,
                        'user_id' => $user->id,
                        'action' => ActivityLogAction::FieldUpdated,
                        'payload' => [
                            'field' => 'status',
                            'from' => TaskStatus::ToDo->value,
                            'to' => $task->status->value,
                        ],
                    ]);
                }
            }
            foreach ($eventsByUser[$user->id] ?? [] as $event) {
                ActivityLog::create([
                    'loggable_type' => Event::class,
                    'loggable_id' => $event->id,
                    'user_id' => $user->id,
                    'action' => ActivityLogAction::ItemCreated,
                    'payload' => [],
                ]);
            }
            foreach ($projectsByUser[$user->id] ?? [] as $project) {
                ActivityLog::create([
                    'loggable_type' => Project::class,
                    'loggable_id' => $project->id,
                    'user_id' => $user->id,
                    'action' => ActivityLogAction::ItemCreated,
                    'payload' => [],
                ]);
            }
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, User>  $users
     * @param  array<int, array<int, Task>>  $tasksByUser
     * @param  array<int, array<int, Event>>  $eventsByUser
     * @param  array<int, array<int, Project>>  $projectsByUser
     */
    private function createCollaborationsAndInvitations(
        \Illuminate\Support\Collection $users,
        array $tasksByUser,
        array $eventsByUser,
        array $projectsByUser
    ): void {
        $permissions = [CollaborationPermission::View, CollaborationPermission::Edit];
        $userArray = $users->all();

        foreach ($users as $owner) {
            $otherUsers = collect($userArray)->reject(fn (User $u) => $u->id === $owner->id)->values();
            if ($otherUsers->isEmpty()) {
                continue;
            }

            $allTasks = collect($tasksByUser[$owner->id] ?? []);
            $allEvents = collect($eventsByUser[$owner->id] ?? []);
            $allProjects = collect($projectsByUser[$owner->id] ?? []);
            $collaboratables = $allTasks->map(fn (Task $t) => ['type' => Task::class, 'id' => $t->id])
                ->concat($allEvents->map(fn (Event $e) => ['type' => Event::class, 'id' => $e->id]))
                ->concat($allProjects->map(fn (Project $p) => ['type' => Project::class, 'id' => $p->id]))
                ->values();

            if ($collaboratables->isEmpty()) {
                continue;
            }

            $shareCount = min(4, $collaboratables->count());
            $toShare = $collaboratables->random($shareCount);
            $sharedKeys = $toShare->map(fn (array $item) => $item['type'].':'.$item['id'])->all();

            foreach ($toShare as $item) {
                $collabUser = $otherUsers->random();
                $permission = fake()->randomElement($permissions);

                Collaboration::create([
                    'collaboratable_type' => $item['type'],
                    'collaboratable_id' => $item['id'],
                    'user_id' => $collabUser->id,
                    'permission' => $permission,
                ]);
                CollaborationInvitation::create([
                    'collaboratable_type' => $item['type'],
                    'collaboratable_id' => $item['id'],
                    'inviter_id' => $owner->id,
                    'invitee_email' => $collabUser->email,
                    'invitee_user_id' => $collabUser->id,
                    'permission' => $permission,
                    'status' => 'accepted',
                    'expires_at' => null,
                ]);
            }

            $rest = $collaboratables->filter(
                fn (array $item) => ! in_array($item['type'].':'.$item['id'], $sharedKeys, true)
            )->values();
            $toPending = $rest->take(2);
            foreach ($toPending as $item) {
                $pendingEmail = 'pending.'.fake()->unique()->safeEmail();
                $permission = fake()->randomElement($permissions);
                CollaborationInvitation::create([
                    'collaboratable_type' => $item['type'],
                    'collaboratable_id' => $item['id'],
                    'inviter_id' => $owner->id,
                    'invitee_email' => $pendingEmail,
                    'invitee_user_id' => null,
                    'permission' => $permission,
                    'status' => 'pending',
                    'expires_at' => null,
                ]);
            }

            $toDecline = $rest->skip(2)->take(1);
            foreach ($toDecline as $item) {
                $declinedUser = $otherUsers->random();
                $permission = fake()->randomElement($permissions);
                CollaborationInvitation::create([
                    'collaboratable_type' => $item['type'],
                    'collaboratable_id' => $item['id'],
                    'inviter_id' => $owner->id,
                    'invitee_email' => $declinedUser->email,
                    'invitee_user_id' => $declinedUser->id,
                    'permission' => $permission,
                    'status' => 'declined',
                    'expires_at' => null,
                ]);
            }
        }
    }
}
