<?php

namespace Database\Seeders;

use App\Enums\EventStatus;
use App\Enums\TaskComplexity;
use App\Enums\TaskPriority;
use App\Enums\TaskRecurrenceType;
use App\Enums\TaskSourceType;
use App\Enums\TaskStatus;
use App\Models\Event;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\TaskInstance;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class StudentLifeSampleSeeder extends Seeder
{
    private const TARGET_EMAIL = 'andrew.juan.cvt@eac.edu.ph';

    /**
     * @var array<int, array<string, mixed>>
     */
    private const RECURRING_CHORES = [
        [
            'title' => 'Wash dishes after dinner',
            'description' => 'Clear the sink and wipe the counter every night.',
            'duration' => 20,
            'priority' => TaskPriority::Low,
        ],
        [
            'title' => 'Walk 10k steps',
            'description' => 'Reach at least 10,000 steps today.',
            'duration' => 45,
            'priority' => TaskPriority::Medium,
        ],
        [
            'title' => 'Review today’s lecture notes',
            'description' => 'Skim and summarize key ideas from today’s classes.',
            'duration' => 30,
            'priority' => TaskPriority::Medium,
        ],
        [
            'title' => 'Practice drawing for 20 minutes',
            'description' => 'Sketch anything you like; focus on line confidence.',
            'duration' => 20,
            'priority' => TaskPriority::Low,
        ],
        [
            'title' => 'Prepare tomorrow’s school bag',
            'description' => 'Pack laptop, chargers, notebooks, and ID for tomorrow.',
            'duration' => 15,
            'priority' => TaskPriority::Medium,
        ],
    ];

    /**
     * @var array<int, array<string, mixed>>
     */
    private const STUDENT_TASKS = [
        [
            'title' => 'Library research for history essay',
            'description' => 'Find three journal articles and save citations.',
            'duration' => 90,
            'priority' => TaskPriority::High,
            'complexity' => TaskComplexity::Moderate,
            'days_from_now' => 2,
        ],
        [
            'title' => 'Group project planning slides',
            'description' => 'Draft agenda and 5 slides for project kickoff.',
            'duration' => 120,
            'priority' => TaskPriority::High,
            'complexity' => TaskComplexity::Complex,
            'days_from_now' => 3,
        ],
        [
            'title' => 'Update CV and LinkedIn profile',
            'description' => 'Add latest projects and clean up formatting.',
            'duration' => 60,
            'priority' => TaskPriority::Medium,
            'complexity' => TaskComplexity::Moderate,
            'days_from_now' => 5,
        ],
        [
            'title' => 'Practice coding interview problems',
            'description' => 'Solve 3 array and 2 string problems.',
            'duration' => 90,
            'priority' => TaskPriority::High,
            'complexity' => TaskComplexity::Complex,
            'days_from_now' => 1,
        ],
        [
            'title' => 'Clean up notes and upload to drive',
            'description' => 'Organize class notes and sync to cloud storage.',
            'duration' => 45,
            'priority' => TaskPriority::Medium,
            'complexity' => TaskComplexity::Simple,
            'days_from_now' => 0,
        ],
    ];

    /**
     * @var array<int, array<string, mixed>>
     */
    private const EVENTS = [
        [
            'title' => 'Math exam review session',
            'description' => 'Instructor-led review for upcoming midterm.',
            'days_from_now' => 3,
            'start_time' => '16:00',
            'end_time' => '18:00',
        ],
        [
            'title' => 'CS group project meetup',
            'description' => 'Meet teammates in the lab to align on deliverables.',
            'days_from_now' => 1,
            'start_time' => '14:30',
            'end_time' => '16:00',
        ],
        [
            'title' => 'Campus club orientation night',
            'description' => 'Student organizations fair and orientation.',
            'days_from_now' => 5,
            'start_time' => '18:00',
            'end_time' => '20:30',
        ],
    ];

    public function run(): void
    {
        $user = User::where('email', self::TARGET_EMAIL)->first();

        if ($user === null) {
            throw new \RuntimeException(
                'StudentLifeSampleSeeder: seed user not found. Ensure a user with email '.self::TARGET_EMAIL.' exists (e.g. sign up first).'
            );
        }

        $now = Carbon::now();

        $this->seedBrightspaceTasks($user, $now);
        $this->seedRecurringChores($user, $now);
        $this->seedStudentTasks($user, $now);
        $this->seedStudentEvents($user, $now);
    }

    private function seedBrightspaceTasks(User $user, Carbon $now): void
    {
        foreach (BrightspaceSampleTasksSeeder::TASKS as $spec) {
            $start = null;
            if (isset($spec['start_days_from_now'], $spec['start_time'])) {
                [$startHour, $startMinute] = explode(':', $spec['start_time']);
                $start = $now->copy()
                    ->addDays((int) $spec['start_days_from_now'])
                    ->setTime((int) $startHour, (int) $startMinute);
            }

            $end = null;
            if (isset($spec['end_days_from_now'], $spec['end_time'])) {
                [$endHour, $endMinute] = explode(':', $spec['end_time']);
                $end = $now->copy()
                    ->addDays((int) $spec['end_days_from_now'])
                    ->setTime((int) $endHour, (int) $endMinute);
            }

            $completedAt = null;
            if (! empty($spec['completed']) && $end !== null) {
                $completedAt = $end->copy()->addMinutes(10);
            }

            Task::create([
                'user_id' => $user->id,
                'title' => $spec['title'],
                'description' => $spec['description'],
                'teacher_name' => $spec['teacher_name'],
                'subject_name' => $spec['subject_name'],
                'status' => TaskStatus::from($spec['status']),
                'priority' => TaskPriority::from($spec['priority']),
                'complexity' => TaskComplexity::from($spec['complexity']),
                'source_type' => TaskSourceType::Brightspace,
                'source_id' => $spec['source_id'],
                'source_url' => null,
                'duration' => $spec['duration'],
                'start_datetime' => $start,
                'end_datetime' => $end,
                'project_id' => null,
                'event_id' => null,
                'calendar_feed_id' => null,
                'completed_at' => $completedAt,
            ]);
        }
    }

    private function seedRecurringChores(User $user, Carbon $now): void
    {
        foreach (self::RECURRING_CHORES as $index => $spec) {
            $start = $now->copy()->setTime(20, 0)->addMinutes($index * 5);
            $end = $start->copy()->addMinutes((int) $spec['duration']);

            $task = Task::create([
                'user_id' => $user->id,
                'title' => $spec['title'],
                'description' => $spec['description'],
                'teacher_name' => null,
                'subject_name' => null,
                'status' => TaskStatus::ToDo,
                'priority' => $spec['priority'],
                'complexity' => TaskComplexity::Simple,
                'source_type' => TaskSourceType::Manual,
                'source_id' => null,
                'source_url' => null,
                'duration' => $spec['duration'],
                'start_datetime' => $start,
                'end_datetime' => $end,
                'project_id' => null,
                'event_id' => null,
                'calendar_feed_id' => null,
                'completed_at' => null,
            ]);

            $recurring = RecurringTask::create([
                'task_id' => $task->id,
                'recurrence_type' => TaskRecurrenceType::Daily,
                'interval' => 1,
                'start_datetime' => $start,
                'end_datetime' => $start->copy()->addMonths(1),
                'days_of_week' => json_encode([0, 1, 2, 3, 4, 5, 6]),
            ]);

            for ($i = 0; $i < 3; $i++) {
                $instanceDate = $now->copy()->addDays($i)->startOfDay();

                TaskInstance::create([
                    'recurring_task_id' => $recurring->id,
                    'task_id' => $task->id,
                    'instance_date' => $instanceDate,
                    'status' => TaskStatus::ToDo,
                    'completed_at' => null,
                ]);
            }
        }
    }

    private function seedStudentTasks(User $user, Carbon $now): void
    {
        foreach (self::STUDENT_TASKS as $spec) {
            $start = $now->copy()
                ->addDays((int) $spec['days_from_now'])
                ->setTime(10, 0);
            $end = $start->copy()->addHours(2);

            Task::create([
                'user_id' => $user->id,
                'title' => $spec['title'],
                'description' => $spec['description'],
                'teacher_name' => null,
                'subject_name' => null,
                'status' => TaskStatus::ToDo,
                'priority' => $spec['priority'],
                'complexity' => $spec['complexity'],
                'source_type' => TaskSourceType::Manual,
                'source_id' => null,
                'source_url' => null,
                'duration' => $spec['duration'],
                'start_datetime' => $start,
                'end_datetime' => $end,
                'project_id' => null,
                'event_id' => null,
                'calendar_feed_id' => null,
                'completed_at' => null,
            ]);
        }
    }

    private function seedStudentEvents(User $user, Carbon $now): void
    {
        foreach (self::EVENTS as $spec) {
            [$startHour, $startMinute] = explode(':', $spec['start_time']);
            [$endHour, $endMinute] = explode(':', $spec['end_time']);

            $start = $now->copy()
                ->addDays((int) $spec['days_from_now'])
                ->setTime((int) $startHour, (int) $startMinute);
            $end = $now->copy()
                ->addDays((int) $spec['days_from_now'])
                ->setTime((int) $endHour, (int) $endMinute);

            Event::create([
                'user_id' => $user->id,
                'title' => $spec['title'],
                'description' => $spec['description'],
                'start_datetime' => $start,
                'end_datetime' => $end,
                'all_day' => false,
                'status' => EventStatus::Scheduled,
            ]);
        }
    }
}
