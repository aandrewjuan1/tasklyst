<?php

namespace Database\Seeders;

use App\Enums\ActivityLogAction;
use App\Enums\CollaborationPermission;
use App\Enums\EventRecurrenceType;
use App\Enums\EventStatus;
use App\Enums\TaskRecurrenceType;
use App\Enums\TaskStatus;
use App\Models\Collaboration;
use App\Models\CollaborationInvitation;
use App\Models\Comment;
use App\Models\Event;
use App\Models\EventException;
use App\Models\EventInstance;
use App\Models\Project;
use App\Models\RecurringEvent;
use App\Models\RecurringTask;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskException;
use App\Models\TaskInstance;
use App\Models\User;
use App\Services\ActivityLogRecorder;
use Illuminate\Database\Seeder;

class FullFakeDataSeeder extends Seeder
{
    private const TARGET_EMAIL = 'andrew.juan.cvt@eac.edu.ph';

    /** @var array<int, array{name: string, description: string|null}> */
    private const PROJECTS = [
        ['name' => 'Thesis Project', 'description' => 'Final year thesis writing and defense prep'],
        ['name' => 'Website Redesign', 'description' => 'Client site overhaul and content refresh'],
        ['name' => 'Home Renovation', 'description' => 'Kitchen and bathroom updates'],
        ['name' => 'Learn Spanish', 'description' => 'Duolingo and conversation practice'],
        ['name' => 'Fitness Challenge', 'description' => '30-day workout streak'],
    ];

    /** @var array<int, array{title: string, description: string|null, all_day: bool}> */
    private const EVENTS = [
        ['title' => '23 BDAY', 'description' => 'Birthday party at home', 'all_day' => true],
        ['title' => 'Team standup', 'description' => 'Daily 15-min sync', 'all_day' => false],
        ['title' => 'Dentist appointment', 'description' => null, 'all_day' => false],
        ['title' => 'Movie night', 'description' => 'Friends at cinema', 'all_day' => false],
        ['title' => 'Doctor checkup', 'description' => 'Annual physical', 'all_day' => false],
        ['title' => 'Lunch with Mom', 'description' => 'Sunday lunch', 'all_day' => false],
        ['title' => 'Conference call', 'description' => 'Client demo', 'all_day' => false],
        ['title' => 'Gym class', 'description' => 'Spin at 6pm', 'all_day' => false],
    ];

    /** @var array<int, array{title: string, description: string|null}> */
    private const STANDALONE_TASKS = [
        ['title' => 'Wash dishes', 'description' => null],
        ['title' => 'Take out trash', 'description' => null],
        ['title' => 'Water plants', 'description' => null],
        ['title' => 'Learn how to cook pasta', 'description' => 'Try carbonara recipe'],
        ['title' => 'Read 20 pages', 'description' => 'Current book'],
        ['title' => 'Call grandma', 'description' => null],
    ];

    /** @var array<int, string> */
    private const PROJECT_TASK_TITLES = [
        'Write chapter 1',
        'Design homepage mockup',
        'Get contractor quotes',
    ];

    /** @var array<int, string> */
    private const EVENT_TASK_TITLES = [
        'Buy cake and balloons',
        'Prepare standup agenda',
        'Bring insurance card',
    ];

    /** @var array<int, string> */
    private const COMMENT_SAMPLES = [
        'Let\'s review this next week.',
        'First draft done, need feedback.',
        'Remind me to bring the docs.',
        'Looks good to me!',
        'Can we move this to Friday?',
        'Done with my part.',
        'Blocked on design assets.',
        'Following up on this.',
    ];

    /** @var array<int, string> */
    private const EXCEPTION_REASONS = [
        'Out of town',
        'Rescheduled',
        'Cancelled for this week',
    ];

    public function run(): void
    {
        $user = User::where('email', self::TARGET_EMAIL)->first();

        if ($user === null) {
            throw new \RuntimeException(
                'Seed user not found. Ensure a user with email '.self::TARGET_EMAIL.' exists (e.g. sign up first).'
            );
        }

        $recorder = app(ActivityLogRecorder::class);

        $extraUsers = User::factory()->count(3)->create()->all();

        $tags = $this->createTags($user);
        $projects = $this->createProjects($user);
        $events = $this->createEvents($user);
        $tasks = $this->createTasks($user, $projects, $events);

        $recurringEvents = $this->createRecurringEvents($events);
        $recurringTasks = $this->createRecurringTasks($tasks);

        $this->createEventInstances($recurringEvents);
        $this->createTaskInstances($recurringTasks);
        $this->createEventExceptions($recurringEvents, $user);
        $this->createTaskExceptions($recurringTasks, $user);

        $this->createComments($user, $extraUsers, $projects, $events, $tasks);
        $this->attachTagsToItems($user, $tags, $events, $tasks);
        $this->createCollaborations($user, $extraUsers, $projects, $events, $tasks);
        $this->createCollaborationInvitations($user, $extraUsers, $projects, $events, $tasks);
        $this->createActivityLogs($recorder, $user, $projects, $events, $tasks);
    }

    /**
     * @return array<int, Tag>
     */
    private function createTags(User $user): array
    {
        $names = ['Work', 'Personal', 'Urgent', 'Meeting', 'Follow-up', 'Review', 'Blocked', 'Idea'];
        $tags = [];
        foreach (array_slice($names, 0, 6) as $name) {
            $tags[] = Tag::factory()->create(['user_id' => $user->id, 'name' => $name]);
        }

        return $tags;
    }

    /**
     * @return array<int, Project>
     */
    private function createProjects(User $user): array
    {
        $projects = [];
        foreach (self::PROJECTS as $i => $data) {
            $projects[] = Project::factory()->create([
                'user_id' => $user->id,
                'name' => $data['name'],
                'description' => $data['description'],
                'start_datetime' => fake()->optional(0.6)->dateTimeBetween('-2 weeks', '+1 month'),
                'end_datetime' => fake()->optional(0.6)->dateTimeBetween('+1 week', '+3 months'),
            ]);
        }

        return $projects;
    }

    /**
     * @return array<int, Event>
     */
    private function createEvents(User $user): array
    {
        $events = [];
        foreach (self::EVENTS as $data) {
            $events[] = Event::factory()->create([
                'user_id' => $user->id,
                'title' => $data['title'],
                'description' => $data['description'],
                'start_datetime' => fake()->optional(0.8)->dateTimeBetween('-1 week', '+1 month'),
                'end_datetime' => fake()->optional(0.8)->dateTimeBetween('+1 hour', '+2 months'),
                'all_day' => $data['all_day'],
                'status' => fake()->randomElement(EventStatus::cases()),
            ]);
        }

        return $events;
    }

    /**
     * @param  array<int, Project>  $projects
     * @param  array<int, Event>  $events
     * @return array<int, Task>
     */
    private function createTasks(User $user, array $projects, array $events): array
    {
        $tasks = [];

        foreach (self::STANDALONE_TASKS as $data) {
            $tasks[] = Task::factory()->create([
                'user_id' => $user->id,
                'project_id' => null,
                'event_id' => null,
                'title' => $data['title'],
                'description' => $data['description'],
                'status' => fake()->randomElement(TaskStatus::cases()),
                'start_datetime' => fake()->optional(0.5)->dateTimeBetween('-1 week', '+2 weeks'),
                'end_datetime' => fake()->optional(0.6)->dateTimeBetween('+1 day', '+1 month'),
            ]);
        }

        foreach (array_slice($projects, 0, 3) as $i => $project) {
            $title = self::PROJECT_TASK_TITLES[$i] ?? 'Project task '.($i + 1);
            $tasks[] = Task::factory()->create([
                'user_id' => $user->id,
                'project_id' => $project->id,
                'event_id' => null,
                'title' => $title,
                'description' => null,
                'status' => fake()->randomElement(TaskStatus::cases()),
                'start_datetime' => fake()->optional(0.5)->dateTimeBetween('-1 week', '+2 weeks'),
                'end_datetime' => fake()->optional(0.6)->dateTimeBetween('+1 day', '+1 month'),
            ]);
        }

        foreach (array_slice($events, 0, 3) as $i => $event) {
            $title = self::EVENT_TASK_TITLES[$i] ?? 'Event task '.($i + 1);
            $tasks[] = Task::factory()->create([
                'user_id' => $user->id,
                'project_id' => null,
                'event_id' => $event->id,
                'title' => $title,
                'description' => null,
                'status' => fake()->randomElement(TaskStatus::cases()),
                'start_datetime' => $event->start_datetime?->copy(),
                'end_datetime' => $event->end_datetime?->copy() ?? fake()->dateTimeBetween('+1 hour', '+1 week'),
            ]);
        }

        return $tasks;
    }

    /**
     * @param  array<int, Event>  $events
     * @return array<int, RecurringEvent>
     */
    private function createRecurringEvents(array $events): array
    {
        $recurring = [];
        foreach (array_slice($events, 0, 3) as $event) {
            $start = $event->start_datetime ?? now()->addDays(1);
            $recurring[] = RecurringEvent::create([
                'event_id' => $event->id,
                'recurrence_type' => fake()->randomElement(EventRecurrenceType::cases()),
                'interval' => 1,
                'days_of_week' => json_encode([1, 3, 5]),
                'start_datetime' => $start,
                'end_datetime' => fake()->optional(0.4)->dateTimeBetween($start, '+3 months'),
            ]);
        }

        return $recurring;
    }

    /**
     * @param  array<int, Task>  $tasks
     * @return array<int, RecurringTask>
     */
    private function createRecurringTasks(array $tasks): array
    {
        $recurring = [];
        foreach (array_slice($tasks, 0, 3) as $task) {
            $start = $task->start_datetime ?? now()->addDays(1);
            $recurring[] = RecurringTask::create([
                'task_id' => $task->id,
                'recurrence_type' => fake()->randomElement(TaskRecurrenceType::cases()),
                'interval' => 1,
                'start_datetime' => $start,
                'end_datetime' => fake()->optional(0.4)->dateTimeBetween($start, '+3 months'),
                'days_of_week' => json_encode([0, 2, 4]),
            ]);
        }

        return $recurring;
    }

    /**
     * @param  array<int, RecurringEvent>  $recurringEvents
     */
    private function createEventInstances(array $recurringEvents): void
    {
        foreach ($recurringEvents as $recurring) {
            for ($i = 0; $i < 4; $i++) {
                EventInstance::create([
                    'recurring_event_id' => $recurring->id,
                    'event_id' => $recurring->event_id,
                    'instance_date' => fake()->dateTimeBetween('-1 week', '+2 weeks')->format('Y-m-d'),
                    'status' => EventStatus::Scheduled,
                    'cancelled' => false,
                    'completed_at' => null,
                ]);
            }
        }
    }

    /**
     * @param  array<int, RecurringTask>  $recurringTasks
     */
    private function createTaskInstances(array $recurringTasks): void
    {
        foreach ($recurringTasks as $recurring) {
            for ($i = 0; $i < 4; $i++) {
                TaskInstance::create([
                    'recurring_task_id' => $recurring->id,
                    'task_id' => $recurring->task_id,
                    'instance_date' => fake()->dateTimeBetween('-1 week', '+2 weeks')->format('Y-m-d'),
                    'status' => TaskStatus::ToDo,
                    'completed_at' => null,
                ]);
            }
        }
    }

    /**
     * @param  array<int, RecurringEvent>  $recurringEvents
     */
    private function createEventExceptions(array $recurringEvents, User $user): void
    {
        $reasons = self::EXCEPTION_REASONS;
        foreach (array_slice($recurringEvents, 0, 2) as $i => $recurring) {
            EventException::create([
                'recurring_event_id' => $recurring->id,
                'exception_date' => fake()->dateTimeBetween('+1 week', '+3 weeks')->format('Y-m-d'),
                'is_deleted' => fake()->boolean(20),
                'replacement_instance_id' => null,
                'reason' => $reasons[$i % count($reasons)],
                'created_by' => $user->id,
            ]);
        }
    }

    /**
     * @param  array<int, RecurringTask>  $recurringTasks
     */
    private function createTaskExceptions(array $recurringTasks, User $user): void
    {
        $reasons = self::EXCEPTION_REASONS;
        foreach (array_slice($recurringTasks, 0, 2) as $i => $recurring) {
            TaskException::create([
                'recurring_task_id' => $recurring->id,
                'exception_date' => fake()->dateTimeBetween('+1 week', '+3 weeks')->format('Y-m-d'),
                'is_deleted' => fake()->boolean(20),
                'replacement_instance_id' => null,
                'reason' => $reasons[$i % count($reasons)],
                'created_by' => $user->id,
            ]);
        }
    }

    /**
     * @param  array<int, User>  $extraUsers
     * @param  array<int, Project>  $projects
     * @param  array<int, Event>  $events
     * @param  array<int, Task>  $tasks
     */
    private function createComments(User $user, array $extraUsers, array $projects, array $events, array $tasks): void
    {
        $commenterPool = array_merge([$user], $extraUsers);
        $samples = self::COMMENT_SAMPLES;

        foreach (array_slice($projects, 0, 3) as $i => $project) {
            $author = fake()->randomElement($commenterPool);
            Comment::create([
                'commentable_type' => Project::class,
                'commentable_id' => $project->id,
                'user_id' => $author->id,
                'content' => $samples[$i % count($samples)],
                'is_edited' => false,
                'edited_at' => null,
                'is_pinned' => fake()->boolean(20),
            ]);
        }

        foreach (array_slice($events, 0, 4) as $i => $event) {
            $author = fake()->randomElement($commenterPool);
            Comment::create([
                'commentable_type' => Event::class,
                'commentable_id' => $event->id,
                'user_id' => $author->id,
                'content' => $samples[($i + 1) % count($samples)],
                'is_edited' => fake()->boolean(25),
                'edited_at' => fake()->boolean(25) ? now() : null,
                'is_pinned' => fake()->boolean(15),
            ]);
        }

        foreach (array_slice($tasks, 0, 6) as $i => $task) {
            $author = fake()->randomElement($commenterPool);
            Comment::create([
                'commentable_type' => Task::class,
                'commentable_id' => $task->id,
                'user_id' => $author->id,
                'content' => $samples[($i + 2) % count($samples)],
                'is_edited' => fake()->boolean(20),
                'edited_at' => fake()->boolean(20) ? now() : null,
                'is_pinned' => fake()->boolean(10),
            ]);
        }
    }

    /**
     * @param  array<int, Tag>  $tags
     * @param  array<int, Event>  $events
     * @param  array<int, Task>  $tasks
     */
    private function attachTagsToItems(User $user, array $tags, array $events, array $tasks): void
    {
        $tagIds = collect($tags)->pluck('id')->all();

        foreach (array_slice($events, 0, 5) as $event) {
            $event->tags()->attach(fake()->randomElements($tagIds, min(fake()->numberBetween(1, 3), count($tagIds))));
        }

        foreach (array_slice($tasks, 0, 8) as $task) {
            $task->tags()->attach(fake()->randomElements($tagIds, min(fake()->numberBetween(1, 3), count($tagIds))));
        }
    }

    /**
     * @param  array<int, User>  $extraUsers
     * @param  array<int, Project>  $projects
     * @param  array<int, Event>  $events
     * @param  array<int, Task>  $tasks
     */
    private function createCollaborations(User $user, array $extraUsers, array $projects, array $events, array $tasks): void
    {
        foreach (array_slice($projects, 0, 2) as $project) {
            Collaboration::create([
                'collaboratable_type' => Project::class,
                'collaboratable_id' => $project->id,
                'user_id' => $extraUsers[0]->id,
                'permission' => CollaborationPermission::Edit,
            ]);
            if (count($extraUsers) > 1) {
                Collaboration::create([
                    'collaboratable_type' => Project::class,
                    'collaboratable_id' => $project->id,
                    'user_id' => $extraUsers[1]->id,
                    'permission' => CollaborationPermission::View,
                ]);
            }
        }

        foreach (array_slice($events, 0, 2) as $event) {
            Collaboration::create([
                'collaboratable_type' => Event::class,
                'collaboratable_id' => $event->id,
                'user_id' => $extraUsers[0]->id,
                'permission' => CollaborationPermission::Edit,
            ]);
        }

        foreach (array_slice($tasks, 0, 3) as $task) {
            Collaboration::create([
                'collaboratable_type' => Task::class,
                'collaboratable_id' => $task->id,
                'user_id' => $extraUsers[0]->id,
                'permission' => fake()->randomElement(CollaborationPermission::cases()),
            ]);
        }
    }

    /**
     * @param  array<int, User>  $extraUsers
     * @param  array<int, Project>  $projects
     * @param  array<int, Event>  $events
     * @param  array<int, Task>  $tasks
     */
    private function createCollaborationInvitations(User $user, array $extraUsers, array $projects, array $events, array $tasks): void
    {
        $project = $projects[0];
        CollaborationInvitation::create([
            'collaboratable_type' => Project::class,
            'collaboratable_id' => $project->id,
            'inviter_id' => $user->id,
            'invitee_email' => fake()->safeEmail(),
            'invitee_user_id' => null,
            'permission' => CollaborationPermission::View,
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        CollaborationInvitation::create([
            'collaboratable_type' => Task::class,
            'collaboratable_id' => $tasks[0]->id,
            'inviter_id' => $user->id,
            'invitee_email' => $extraUsers[1]->email,
            'invitee_user_id' => $extraUsers[1]->id,
            'permission' => CollaborationPermission::Edit,
            'status' => 'pending',
            'expires_at' => now()->addDays(14),
        ]);

        CollaborationInvitation::create([
            'collaboratable_type' => Event::class,
            'collaboratable_id' => $events[0]->id,
            'inviter_id' => $user->id,
            'invitee_email' => $extraUsers[2]->email ?? fake()->safeEmail(),
            'invitee_user_id' => count($extraUsers) > 2 ? $extraUsers[2]->id : null,
            'permission' => CollaborationPermission::View,
            'status' => 'pending',
            'expires_at' => null,
        ]);
    }

    /**
     * @param  array<int, Project>  $projects
     * @param  array<int, Event>  $events
     * @param  array<int, Task>  $tasks
     */
    private function createActivityLogs(ActivityLogRecorder $recorder, User $user, array $projects, array $events, array $tasks): void
    {
        foreach ($projects as $project) {
            $recorder->record($project, $user, ActivityLogAction::ItemCreated, []);
        }
        foreach ($events as $event) {
            $recorder->record($event, $user, ActivityLogAction::ItemCreated, []);
        }
        foreach ($tasks as $task) {
            $recorder->record($task, $user, ActivityLogAction::ItemCreated, []);
        }

        if (count($tasks) > 0) {
            $recorder->record($tasks[0], $user, ActivityLogAction::FieldUpdated, [
                'field' => 'status',
                'from' => TaskStatus::ToDo->value,
                'to' => TaskStatus::Doing->value,
            ]);
        }
        if (count($projects) > 0) {
            $recorder->record($projects[0], $user, ActivityLogAction::FieldUpdated, [
                'field' => 'name',
                'from' => 'Capstone draft',
                'to' => $projects[0]->name,
            ]);
        }

        $taskWithComment = $tasks[2] ?? $tasks[0];
        $comment = $taskWithComment->comments()->first();
        if ($comment !== null) {
            $recorder->record($taskWithComment, $comment->user, ActivityLogAction::CommentCreated, [
                'content' => $comment->content,
            ]);
        }
    }
}
