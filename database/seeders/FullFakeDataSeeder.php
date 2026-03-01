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
use Illuminate\Support\Carbon;

class FullFakeDataSeeder extends Seeder
{
    private const TARGET_EMAIL = 'andrew.juan.cvt@eac.edu.ph';

    private const LEVEL_EASY = 'easy';

    private const LEVEL_REALISTIC = 'realistic';

    private const LEVEL_NIGHTMARE = 'nightmare';

    /** @var array<int, array{name: string, description: string|null}> */
    private const PROJECTS = [
        ['name' => 'Website Redesign', 'description' => 'Client site overhaul and content refresh'],
        ['name' => 'Capstone / Thesis', 'description' => 'Final year thesis writing and defense prep'],
        ['name' => 'Notes cleanup', 'description' => 'Organize and archive old notes'],
        ['name' => 'Migration (legacy)', 'description' => 'Legacy system migration, high complexity'],
        ['name' => 'App redesign', 'description' => 'Mobile app UI overhaul, 3 months'],
        ['name' => 'Home Renovation', 'description' => 'Kitchen and bathroom updates'],
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

    /** Clean: clear title, optional description. */
    /** @var array<int, array{title: string, description: string|null}> */
    private const TASKS_CLEAN = [
        ['title' => 'Finish report', 'description' => '2h, due tomorrow'],
        ['title' => 'Send email', 'description' => '15m, today'],
        ['title' => 'Prepare slides', 'description' => '1h, Friday'],
        ['title' => 'Wash dishes', 'description' => null],
        ['title' => 'Take out trash', 'description' => null],
        ['title' => 'Water plants', 'description' => null],
        ['title' => 'Learn how to cook pasta', 'description' => 'Try carbonara recipe'],
        ['title' => 'Read 20 pages', 'description' => 'Current book'],
        ['title' => 'Call grandma', 'description' => null],
    ];

    /** Messy / vague titles or minimal description. */
    /** @var array<int, array{title: string, description: string|null}> */
    private const TASKS_MESSY = [
        ['title' => 'Fix stuff', 'description' => null],
        ['title' => 'Work on project', 'description' => null],
        ['title' => 'Do the thing', 'description' => null],
        ['title' => 'Important task', 'description' => null],
        ['title' => 'Project thing', 'description' => null],
    ];

    /** Duplicate title for conflicting-priority scenarios. */
    private const TASK_TITLE_DUPLICATE = 'Submit proposal';

    /** @var array<int, string> */
    private const PROJECT_TASK_TITLES = [
        'Write chapter 1',
        'Design homepage mockup',
        'Get contractor quotes',
        'Review migration plan',
        'Create wireframes',
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

    private string $level = self::LEVEL_REALISTIC;

    public function run(): void
    {
        $user = User::where('email', self::TARGET_EMAIL)->first();

        if ($user === null) {
            throw new \RuntimeException(
                'Seed user not found. Ensure a user with email '.self::TARGET_EMAIL.' exists (e.g. sign up first).'
            );
        }

        $level = config('tasklyst.fake_data_level', self::LEVEL_REALISTIC);
        $this->level = in_array($level, [self::LEVEL_EASY, self::LEVEL_REALISTIC, self::LEVEL_NIGHTMARE], true)
            ? $level
            : self::LEVEL_REALISTIC;

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
            $startDatetime = fake()->optional(0.6)->dateTimeBetween('-2 weeks', now());
            $endDatetime = null;

            if ($this->level === self::LEVEL_EASY) {
                $endDatetime = fake()->optional(0.6)->dateTimeBetween('+1 week', '+3 months');
            } else {
                if ($i === 0) {
                    $endDatetime = fake()->dateTimeBetween('-2 weeks', '-1 day');
                } elseif ($i === 1) {
                    $endDatetime = null;
                } else {
                    $endDatetime = fake()->optional(0.7)->dateTimeBetween('+1 week', '+3 months');
                }
            }

            $projects[] = Project::factory()->create([
                'user_id' => $user->id,
                'name' => $data['name'],
                'description' => $data['description'],
                'start_datetime' => $startDatetime,
                'end_datetime' => $endDatetime,
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
        $baseDate = Carbon::today();
        foreach (self::EVENTS as $data) {
            $start = fake()->optional(0.8)->dateTimeBetween('-1 week', now());
            $end = fake()->optional(0.8)->dateTimeBetween('+1 hour', '+2 months');
            $events[] = Event::factory()->create([
                'user_id' => $user->id,
                'title' => $data['title'],
                'description' => $data['description'],
                'start_datetime' => $start,
                'end_datetime' => $end,
                'all_day' => $data['all_day'],
                'status' => fake()->randomElement(EventStatus::cases()),
            ]);
        }

        if ($this->level === self::LEVEL_REALISTIC || $this->level === self::LEVEL_NIGHTMARE) {
            $events[] = Event::factory()->create([
                'user_id' => $user->id,
                'title' => 'Meeting 13:00–14:00',
                'description' => 'Sprint planning',
                'start_datetime' => $baseDate->copy()->setTime(13, 0),
                'end_datetime' => $baseDate->copy()->setTime(14, 0),
                'all_day' => false,
                'status' => EventStatus::Scheduled,
            ]);
            $events[] = Event::factory()->create([
                'user_id' => $user->id,
                'title' => 'Call 13:30–14:30',
                'description' => 'Client call',
                'start_datetime' => $baseDate->copy()->setTime(13, 30),
                'end_datetime' => $baseDate->copy()->setTime(14, 30),
                'all_day' => false,
                'status' => EventStatus::Scheduled,
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
        $standaloneSpecs = $this->buildStandaloneTaskSpecs();

        foreach ($standaloneSpecs as $spec) {
            $tasks[] = Task::factory()->create([
                'user_id' => $user->id,
                'project_id' => null,
                'event_id' => null,
                'title' => $spec['title'],
                'description' => $spec['description'],
                'status' => fake()->randomElement(TaskStatus::cases()),
                'priority' => $spec['priority'],
                'complexity' => $spec['complexity'],
                'duration' => $spec['duration'],
                'start_datetime' => $spec['start_datetime'],
                'end_datetime' => $spec['end_datetime'],
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
                'priority' => fake()->randomElement(TaskPriority::cases()),
                'complexity' => fake()->randomElement(TaskComplexity::cases()),
                'duration' => fake()->optional(0.6)->numberBetween(15, 240),
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
                'priority' => fake()->randomElement(TaskPriority::cases()),
                'complexity' => fake()->randomElement(TaskComplexity::cases()),
                'duration' => fake()->optional(0.5)->numberBetween(15, 120),
                'start_datetime' => $event->start_datetime?->copy(),
                'end_datetime' => $event->end_datetime?->copy() ?? fake()->dateTimeBetween('+1 hour', '+1 week'),
            ]);
        }

        return $tasks;
    }

    /**
     * Build standalone task specs (title, description, priority, complexity, duration, times) by level.
     *
     * @return array<int, array{title: string, description: string|null, priority: TaskPriority, complexity: TaskComplexity, duration: int|null, start_datetime: \DateTimeInterface|null, end_datetime: \DateTimeInterface|null}>
     */
    private function buildStandaloneTaskSpecs(): array
    {
        $specs = [];
        $now = Carbon::now();

        if ($this->level === self::LEVEL_EASY) {
            foreach (array_slice(self::TASKS_CLEAN, 0, 6) as $data) {
                $specs[] = [
                    'title' => $data['title'],
                    'description' => $data['description'],
                    'priority' => fake()->randomElement(TaskPriority::cases()),
                    'complexity' => fake()->randomElement(TaskComplexity::cases()),
                    'duration' => fake()->numberBetween(15, 240),
                    'start_datetime' => fake()->optional(0.5)->dateTimeBetween('-1 week', 'now'),
                    'end_datetime' => fake()->dateTimeBetween('+1 day', '+1 month'),
                ];
            }

            return $specs;
        }

        $cleanCount = (int) round(6 * 0.4);
        $messyCount = (int) round(6 * 0.30);
        $conflictingCount = (int) round(6 * 0.15);
        $incompleteCount = (int) round(6 * 0.15);
        if ($this->level === self::LEVEL_NIGHTMARE) {
            $conflictingCount = 3;
            $incompleteCount = 3;
            $cleanCount = 2;
            $messyCount = 2;
        }

        $clean = self::TASKS_CLEAN;
        $messy = self::TASKS_MESSY;
        for ($i = 0; $i < $cleanCount && $i < count($clean); $i++) {
            $data = $clean[$i];
            $specs[] = [
                'title' => $data['title'],
                'description' => $data['description'],
                'priority' => fake()->randomElement(TaskPriority::cases()),
                'complexity' => fake()->randomElement(TaskComplexity::cases()),
                'duration' => fake()->numberBetween(15, 240),
                'start_datetime' => fake()->optional(0.5)->dateTimeBetween('-1 week', 'now'),
                'end_datetime' => fake()->dateTimeBetween('+1 day', '+1 month'),
            ];
        }
        for ($i = 0; $i < $messyCount && $i < count($messy); $i++) {
            $data = $messy[$i];
            $specs[] = [
                'title' => $data['title'],
                'description' => $data['description'],
                'priority' => fake()->randomElement(TaskPriority::cases()),
                'complexity' => fake()->randomElement(TaskComplexity::cases()),
                'duration' => fake()->optional(0.5)->numberBetween(15, 120),
                'start_datetime' => fake()->optional(0.5)->dateTimeBetween('-1 week', 'now'),
                'end_datetime' => fake()->optional(0.6)->dateTimeBetween('+1 day', '+1 month'),
            ];
        }
        for ($i = 0; $i < $conflictingCount; $i++) {
            $specs[] = [
                'title' => self::TASK_TITLE_DUPLICATE,
                'description' => null,
                'priority' => $this->level === self::LEVEL_NIGHTMARE ? TaskPriority::Urgent : fake()->randomElement([TaskPriority::Urgent, TaskPriority::High]),
                'complexity' => fake()->randomElement(TaskComplexity::cases()),
                'duration' => fake()->numberBetween(60, 180),
                'start_datetime' => fake()->optional(0.5)->dateTimeBetween('-1 week', 'now'),
                'end_datetime' => fake()->dateTimeBetween('+1 day', '+1 week'),
            ];
        }
        for ($i = 0; $i < $incompleteCount; $i++) {
            $specs[] = [
                'title' => 'Incomplete task '.($i + 1),
                'description' => null,
                'priority' => fake()->randomElement(TaskPriority::cases()),
                'complexity' => fake()->randomElement(TaskComplexity::cases()),
                'duration' => null,
                'start_datetime' => null,
                'end_datetime' => null,
            ];
        }

        if ($this->level === self::LEVEL_NIGHTMARE) {
            $specs[] = [
                'title' => 'Impossible 5h due in 2h',
                'description' => '5h work, deadline in 2 hours',
                'priority' => TaskPriority::Urgent,
                'complexity' => TaskComplexity::Complex,
                'duration' => 300,
                'start_datetime' => $now->copy(),
                'end_datetime' => $now->copy()->addHours(2),
            ];
        }

        return $specs;
    }

    /**
     * @param  array<int, Event>  $events
     * @return array<int, RecurringEvent>
     */
    private function createRecurringEvents(array $events): array
    {
        $recurring = [];
        foreach (array_slice($events, 0, 3) as $event) {
            $start = $event->start_datetime ?? now();
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
            $start = $task->start_datetime ?? now();
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
        if (count($tasks) > 1) {
            $recorder->record($tasks[1], $user, ActivityLogAction::FieldUpdated, [
                'field' => 'priority',
                'from' => TaskPriority::Medium->value,
                'to' => TaskPriority::High->value,
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
