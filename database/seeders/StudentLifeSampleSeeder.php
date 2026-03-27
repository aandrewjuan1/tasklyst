<?php

namespace Database\Seeders;

use App\Enums\EventRecurrenceType;
use App\Enums\EventStatus;
use App\Enums\TaskComplexity;
use App\Enums\TaskPriority;
use App\Enums\TaskRecurrenceType;
use App\Enums\TaskSourceType;
use App\Enums\TaskStatus;
use App\Models\Event;
use App\Models\Project;
use App\Models\RecurringEvent;
use App\Models\RecurringTask;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskInstance;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class StudentLifeSampleSeeder extends Seeder
{
    private const TARGET_EMAIL = 'andrew.juan.cvt@eac.edu.ph';

    private const DUE_DATE_FLOOR = '2026-04-10';

    /**
     * @var array<int, array<string, mixed>>
     */
    private const PROJECTS = [
        [
            'name' => 'CS 220 Final Project',
            'description' => 'End-of-term data structures project (implementation + report).',
        ],
        [
            'name' => 'ENG 105 Comparative Essay',
            'description' => 'Multi-draft essay comparing two authors, with peer review.',
        ],
        [
            'name' => 'ITEL 210 Web App Capstone',
            'description' => 'Semester-long web application built in teams of three.',
        ],
    ];

    /**
     * @var array<int, string>
     */
    private const TAGS = [
        'Exam',
        'Homework',
        'Reading',
        'Health',
        'Household',
        'Career',
    ];

    /**
     * @var array<int, array<string, mixed>>
     */
    private const STRESS_TEST_TASKS = [
        [
            'title' => 'Impossible 5h study block before quiz',
            'description' => 'Review all CS 220 + MATH 201 notes in 5 hours before tonight’s quiz.',
            'duration' => 300,
            'priority' => TaskPriority::Urgent,
            'complexity' => TaskComplexity::Complex,
            'start_offset_minutes' => 0,
            'end_offset_minutes' => 120,
            'allow_overdue' => true,
        ],
        [
            'title' => 'Finish CS 220 report and slides',
            'description' => 'Complete implementation report and 10-slide deck before project demo.',
            'duration' => 240,
            'priority' => TaskPriority::High,
            'complexity' => TaskComplexity::Complex,
            'days_from_now' => 1,
        ],
    ];

    /**
     * @var array<int, array<string, mixed>>
     */
    public const BRIGHTSPACE_TASKS = [
        // ITCS 101 – Intro to Programming
        [
            'title' => 'ITCS 101 – Lab 3: Loops',
            'description' => 'Hands-on lab on for/while loops. Submit zipped source files via Brightspace.',
            'subject_name' => 'ITCS 101 – Intro to Programming',
            'teacher_name' => 'Prof. Miguel Santos',
            'status' => 'done',
            'priority' => 'high',
            'complexity' => 'moderate',
            'duration' => 120,
            'source_id' => 'BS-ITCS101-LAB3',
            'start_days_from_now' => -10,
            'start_time' => '09:00',
            'end_days_from_now' => -10,
            'end_time' => '11:00',
            'completed' => true,
        ],
        [
            'title' => 'ITCS 101 – Quiz 2: Conditions',
            'description' => 'Online Brightspace quiz on if / else and switch, 20 minutes.',
            'subject_name' => 'ITCS 101 – Intro to Programming',
            'teacher_name' => 'Prof. Miguel Santos',
            'status' => 'done',
            'priority' => 'medium',
            'complexity' => 'simple',
            'duration' => 30,
            'source_id' => 'BS-ITCS101-QZ2',
            'start_days_from_now' => -7,
            'start_time' => '13:00',
            'end_days_from_now' => 0,
            'end_time' => '23:59',
            'completed' => true,
        ],
        [
            'title' => 'ITCS 101 – Programming Exercise: Functions',
            'description' => 'Solve 4 function decomposition problems. Upload .cpp files.',
            'subject_name' => 'ITCS 101 – Intro to Programming',
            'teacher_name' => 'Prof. Miguel Santos',
            'status' => 'doing',
            'priority' => 'high',
            'complexity' => 'moderate',
            'duration' => 180,
            'source_id' => 'BS-ITCS101-FUNC-EX',
            'start_days_from_now' => -2,
            'start_time' => '19:00',
            'end_days_from_now' => 1,
            'end_time' => '23:59',
            'completed' => false,
        ],
        [
            'title' => 'ITCS 101 – Midterm Project Checkpoint',
            'description' => 'Submit project proposal PDF and initial repo link.',
            'subject_name' => 'ITCS 101 – Intro to Programming',
            'teacher_name' => 'Prof. Miguel Santos',
            'status' => 'to_do',
            'priority' => 'urgent',
            'complexity' => 'complex',
            'duration' => 240,
            'source_id' => 'BS-ITCS101-MID-CHK',
            'start_days_from_now' => 1,
            'start_time' => '16:00',
            'end_days_from_now' => 3,
            'end_time' => '23:59',
            'completed' => false,
        ],

        // MATH 201 – Discrete Mathematics
        [
            'title' => 'MATH 201 – Problem Set 4: Relations',
            'description' => 'Solve problems on equivalence relations and partial orders.',
            'subject_name' => 'MATH 201 – Discrete Mathematics',
            'teacher_name' => 'Dr. Liza Romero',
            'status' => 'to_do',
            'priority' => 'medium',
            'complexity' => 'moderate',
            'duration' => 150,
            'source_id' => 'BS-MATH201-PS4',
            'start_days_from_now' => -1,
            'start_time' => '18:00',
            'end_days_from_now' => 2,
            'end_time' => '23:00',
            'completed' => false,
        ],
        [
            'title' => 'MATH 201 – Quiz 3: Graph Theory',
            'description' => 'In-class quiz covering basic graph terminology and degree sequences.',
            'subject_name' => 'MATH 201 – Discrete Mathematics',
            'teacher_name' => 'Dr. Liza Romero',
            'status' => 'to_do',
            'priority' => 'high',
            'complexity' => 'moderate',
            'duration' => 30,
            'source_id' => 'BS-MATH201-QZ3',
            'start_days_from_now' => 3,
            'start_time' => '09:30',
            'end_days_from_now' => 3,
            'end_time' => '10:00',
            'completed' => false,
        ],
        [
            'title' => 'MATH 201 – Reading: Trees & Traversals',
            'description' => 'Read textbook section on trees before next lecture.',
            'subject_name' => 'MATH 201 – Discrete Mathematics',
            'teacher_name' => 'Dr. Liza Romero',
            'status' => 'to_do',
            'priority' => 'low',
            'complexity' => 'simple',
            'duration' => 45,
            'source_id' => 'BS-MATH201-READ-TREES',
            'start_days_from_now' => 2,
            'start_time' => '20:00',
            'end_days_from_now' => 4,
            'end_time' => '22:00',
            'completed' => false,
        ],
        [
            'title' => 'MATH 201 – Take-home Exam 1 Submission',
            'description' => 'Upload scanned solutions in a single PDF file.',
            'subject_name' => 'MATH 201 – Discrete Mathematics',
            'teacher_name' => 'Dr. Liza Romero',
            'status' => 'done',
            'priority' => 'urgent',
            'complexity' => 'complex',
            'duration' => 300,
            'source_id' => 'BS-MATH201-EX1',
            'start_days_from_now' => -5,
            'start_time' => '08:00',
            'end_days_from_now' => -3,
            'end_time' => '23:59',
            'completed' => true,
        ],

        // CS 220 – Data Structures
        [
            'title' => 'CS 220 – Lab 5: Linked Lists',
            'description' => 'Implement singly and doubly linked list operations.',
            'subject_name' => 'CS 220 – Data Structures',
            'teacher_name' => 'Engr. Paolo Reyes',
            'status' => 'doing',
            'priority' => 'high',
            'complexity' => 'complex',
            'duration' => 210,
            'source_id' => 'BS-CS220-LAB5',
            'start_days_from_now' => 0,
            'start_time' => '14:00',
            'end_days_from_now' => 2,
            'end_time' => '23:59',
            'completed' => false,
        ],
        [
            'title' => 'CS 220 – Online Quiz: Big-O Notation',
            'description' => 'Time complexity multiple-choice quiz, one attempt only.',
            'subject_name' => 'CS 220 – Data Structures',
            'teacher_name' => 'Engr. Paolo Reyes',
            'status' => 'to_do',
            'priority' => 'medium',
            'complexity' => 'moderate',
            'duration' => 20,
            'source_id' => 'BS-CS220-QZ-BIGO',
            'start_days_from_now' => 4,
            'start_time' => '19:00',
            'end_days_from_now' => 4,
            'end_time' => '19:30',
            'completed' => false,
        ],
        [
            'title' => 'CS 220 – Project Milestone 2: Dynamic Arrays',
            'description' => 'Submit design document and initial implementation of dynamic array module.',
            'subject_name' => 'CS 220 – Data Structures',
            'teacher_name' => 'Engr. Paolo Reyes',
            'status' => 'to_do',
            'priority' => 'high',
            'complexity' => 'complex',
            'duration' => 240,
            'source_id' => 'BS-CS220-PROJ-M2',
            'start_days_from_now' => 5,
            'start_time' => '10:00',
            'end_days_from_now' => 7,
            'end_time' => '23:59',
            'completed' => false,
        ],
        [
            'title' => 'CS 220 – Lab Reflection: Debugging Session',
            'description' => 'Short reflection on bugs fixed during last lab.',
            'subject_name' => 'CS 220 – Data Structures',
            'teacher_name' => 'Engr. Paolo Reyes',
            'status' => 'to_do',
            'priority' => 'low',
            'complexity' => 'simple',
            'duration' => 30,
            'source_id' => 'BS-CS220-REF-DBG',
            'start_days_from_now' => -3,
            'start_time' => '21:00',
            'end_days_from_now' => 0,
            'end_time' => '23:00',
            'completed' => false,
        ],

        // ENG 105 – Academic Writing
        [
            'title' => 'ENG 105 – Draft 1: Comparative Essay',
            'description' => 'Upload first draft for peer review, 800–1000 words.',
            'subject_name' => 'ENG 105 – Academic Writing',
            'teacher_name' => 'Prof. Karen Villanueva',
            'status' => 'done',
            'priority' => 'medium',
            'complexity' => 'moderate',
            'duration' => 180,
            'source_id' => 'BS-ENG105-ESSAY-D1',
            'start_days_from_now' => -8,
            'start_time' => '10:00',
            'end_days_from_now' => 0,
            'end_time' => '23:59',
            'completed' => true,
        ],
        [
            'title' => 'ENG 105 – Draft 2: Comparative Essay',
            'description' => 'Revise essay based on feedback and resubmit.',
            'subject_name' => 'ENG 105 – Academic Writing',
            'teacher_name' => 'Prof. Karen Villanueva',
            'status' => 'to_do',
            'priority' => 'high',
            'complexity' => 'moderate',
            'duration' => 150,
            'source_id' => 'BS-ENG105-ESSAY-D2',
            'start_days_from_now' => 2,
            'start_time' => '15:00',
            'end_days_from_now' => 5,
            'end_time' => '23:59',
            'completed' => false,
        ],
        [
            'title' => 'ENG 105 – Reading Response #3',
            'description' => 'Short reflection post in Brightspace discussion board.',
            'subject_name' => 'ENG 105 – Academic Writing',
            'teacher_name' => 'Prof. Karen Villanueva',
            'status' => 'to_do',
            'priority' => 'low',
            'complexity' => 'simple',
            'duration' => 40,
            'source_id' => 'BS-ENG105-RR3',
            'start_days_from_now' => 1,
            'start_time' => '18:00',
            'end_days_from_now' => 3,
            'end_time' => '22:00',
            'completed' => false,
        ],
        [
            'title' => 'ENG 105 – Plagiarism Module Completion',
            'description' => 'Complete academic integrity module and pass the embedded quiz.',
            'subject_name' => 'ENG 105 – Academic Writing',
            'teacher_name' => 'Prof. Karen Villanueva',
            'status' => 'done',
            'priority' => 'medium',
            'complexity' => 'simple',
            'duration' => 60,
            'source_id' => 'BS-ENG105-PLAG-MOD',
            'start_days_from_now' => -4,
            'start_time' => '09:00',
            'end_days_from_now' => 0,
            'end_time' => '23:59',
            'completed' => true,
        ],

        // ITEL 210 – Web Development
        [
            'title' => 'ITEL 210 – Lab 2: Flexbox Layout',
            'description' => 'Recreate the given layout using pure CSS Flexbox.',
            'subject_name' => 'ITEL 210 – Web Development',
            'teacher_name' => 'Prof. Daniel Cruz',
            'status' => 'doing',
            'priority' => 'medium',
            'complexity' => 'moderate',
            'duration' => 120,
            'source_id' => 'BS-ITEL210-LAB2',
            'start_days_from_now' => 0,
            'start_time' => '20:00',
            'end_days_from_now' => 1,
            'end_time' => '23:59',
            'completed' => false,
        ],
        [
            'title' => 'ITEL 210 – Project Sprint 1 Demo',
            'description' => 'Record a 5-minute Loom demo of current web app progress.',
            'subject_name' => 'ITEL 210 – Web Development',
            'teacher_name' => 'Prof. Daniel Cruz',
            'status' => 'to_do',
            'priority' => 'high',
            'complexity' => 'complex',
            'duration' => 180,
            'source_id' => 'BS-ITEL210-PROJ-S1',
            'start_days_from_now' => 6,
            'start_time' => '14:00',
            'end_days_from_now' => 8,
            'end_time' => '23:59',
            'completed' => false,
        ],
        [
            'title' => 'ITEL 210 – Online Quiz: Semantic HTML',
            'description' => 'Auto-graded quiz on semantic elements and accessibility.',
            'subject_name' => 'ITEL 210 – Web Development',
            'teacher_name' => 'Prof. Daniel Cruz',
            'status' => 'to_do',
            'priority' => 'medium',
            'complexity' => 'simple',
            'duration' => 25,
            'source_id' => 'BS-ITEL210-QZ-HTML',
            'start_days_from_now' => 9,
            'start_time' => '09:00',
            'end_days_from_now' => 9,
            'end_time' => '09:30',
            'completed' => false,
        ],
        [
            'title' => 'ITEL 210 – Final Project Requirements Review',
            'description' => 'Read through final project rubric and post one clarification question.',
            'subject_name' => 'ITEL 210 – Web Development',
            'teacher_name' => 'Prof. Daniel Cruz',
            'status' => 'to_do',
            'priority' => 'low',
            'complexity' => 'simple',
            'duration' => 30,
            'source_id' => 'BS-ITEL210-FINAL-OVERVIEW',
            'start_days_from_now' => 14,
            'start_time' => '19:00',
            'end_days_from_now' => 28,
            'end_time' => '23:59',
            'completed' => false,
        ],
    ];

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
        $dueDateFloor = Carbon::parse(self::DUE_DATE_FLOOR, $now->getTimezone())->startOfDay();

        $this->seedBrightspaceTasks($user, $now, $dueDateFloor);
        $this->seedRecurringChores($user, $now, $dueDateFloor);
        $this->seedStudentTasks($user, $now, $dueDateFloor);
        $this->seedStressTestTasks($user, $now, $dueDateFloor);
        $projects = $this->seedProjects($user, $now, $dueDateFloor);
        $tags = $this->seedTags($user);
        $this->attachProjectsToTasks($user, $projects);
        $this->attachTagsToItems($user, $tags);
        $this->seedStudentEvents($user, $now);
        $this->seedRecurringEvents($user, $now);
    }

    private function seedBrightspaceTasks(User $user, Carbon $now, Carbon $dueDateFloor): void
    {
        foreach (self::BRIGHTSPACE_TASKS as $spec) {
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

            $end = $this->clampTaskDueDate(
                $end,
                $completedAt,
                $dueDateFloor,
                (string) ($spec['source_id'] ?? $spec['title'])
            );

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

    private function seedRecurringChores(User $user, Carbon $now, Carbon $dueDateFloor): void
    {
        foreach (self::RECURRING_CHORES as $index => $spec) {
            $start = $now->copy()->setTime(20, 0)->addMinutes($index * 5);
            $end = $start->copy()->addMinutes((int) $spec['duration']);
            $end = $this->clampTaskDueDate($end, null, $dueDateFloor, (string) $spec['title']);

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

    private function seedStudentTasks(User $user, Carbon $now, Carbon $dueDateFloor): void
    {
        foreach (self::STUDENT_TASKS as $spec) {
            $start = $now->copy()
                ->addDays((int) $spec['days_from_now'])
                ->setTime(10, 0);
            $end = $start->copy()->addHours(2);
            $end = $this->clampTaskDueDate($end, null, $dueDateFloor, (string) $spec['title']);

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

    private function seedStressTestTasks(User $user, Carbon $now, Carbon $dueDateFloor): void
    {
        foreach (self::STRESS_TEST_TASKS as $spec) {
            if (isset($spec['start_offset_minutes'], $spec['end_offset_minutes'])) {
                $start = $now->copy()->addMinutes((int) $spec['start_offset_minutes']);
                $end = $now->copy()->addMinutes((int) $spec['end_offset_minutes']);
            } else {
                $start = $now->copy()
                    ->addDays((int) ($spec['days_from_now'] ?? 0))
                    ->setTime(18, 0);
                $end = $start->copy()->addHours(4);
            }

            $allowOverdue = (bool) ($spec['allow_overdue'] ?? false);
            if ($allowOverdue) {
                // Keep exactly one overdue active task for prioritize-flow testing.
                $end = $now->copy()->subDay()->setTime(9, 0);
                $start = $end->copy()->subHours(2);
            } else {
                $end = $this->clampTaskDueDate($end, null, $dueDateFloor, (string) $spec['title']);
            }

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

    /**
     * @return array<int, Project>
     */
    private function seedProjects(User $user, Carbon $now, Carbon $dueDateFloor): array
    {
        $projects = [];

        foreach (self::PROJECTS as $index => $spec) {
            $start = $now->copy()->addDays($index)->setTime(9, 0);
            $end = $start->copy()->addWeeks(3);
            $end = $this->clampTaskDueDate($end, null, $dueDateFloor, (string) $spec['name']);

            $projects[] = Project::create([
                'user_id' => $user->id,
                'name' => $spec['name'],
                'description' => $spec['description'],
                'start_datetime' => $start,
                'end_datetime' => $end,
            ]);
        }

        return $projects;
    }

    /**
     * @return array<int, Tag>
     */
    private function seedTags(User $user): array
    {
        $existingTags = Tag::query()->forUser($user->id)->get()->keyBy(fn (Tag $tag) => mb_strtolower($tag->name));
        $tags = [];

        foreach (self::TAGS as $name) {
            $key = mb_strtolower($name);
            if ($existingTags->has($key)) {
                $tags[] = $existingTags->get($key);

                continue;
            }

            $tags[] = Tag::create([
                'name' => $name,
                'user_id' => $user->id,
            ]);
        }

        return $tags;
    }

    /**
     * @param  array<int, Project>  $projects
     */
    private function attachProjectsToTasks(User $user, array $projects): void
    {
        if ($projects === []) {
            return;
        }

        $csProject = $projects[0] ?? null;
        $engProject = $projects[1] ?? null;

        if ($csProject !== null) {
            Task::query()
                ->where('user_id', $user->id)
                ->where('subject_name', 'CS 220 – Data Structures')
                ->update(['project_id' => $csProject->id]);
        }

        if ($engProject !== null) {
            Task::query()
                ->where('user_id', $user->id)
                ->where('subject_name', 'ENG 105 – Academic Writing')
                ->update(['project_id' => $engProject->id]);
        }
    }

    /**
     * @param  array<int, Tag>  $tags
     */
    private function attachTagsToItems(User $user, array $tags): void
    {
        if ($tags === []) {
            return;
        }

        $tagByName = collect($tags)->keyBy(fn (Tag $tag) => mb_strtolower($tag->name));

        $examTag = $tagByName->get('exam');
        $homeworkTag = $tagByName->get('homework');
        $readingTag = $tagByName->get('reading');
        $healthTag = $tagByName->get('health');
        $householdTag = $tagByName->get('household');
        $careerTag = $tagByName->get('career');

        // Brightspace exams/quizzes.
        if ($examTag !== null) {
            Task::query()
                ->where('user_id', $user->id)
                ->whereIn('title', [
                    'ITCS 101 – Quiz 2: Conditions',
                    'MATH 201 – Quiz 3: Graph Theory',
                    'MATH 201 – Take-home Exam 1 Submission',
                ])
                ->get()
                ->each(fn (Task $task) => $task->tags()->syncWithoutDetaching([$examTag->id]));
        }

        // Homework / labs / assignments.
        if ($homeworkTag !== null) {
            Task::query()
                ->where('user_id', $user->id)
                ->whereIn('title', [
                    'ITCS 101 – Lab 3: Loops',
                    'ITCS 101 – Programming Exercise: Functions',
                    'CS 220 – Lab 5: Linked Lists',
                    'CS 220 – Lab Reflection: Debugging Session',
                    'ITEL 210 – Lab 2: Flexbox Layout',
                ])
                ->get()
                ->each(fn (Task $task) => $task->tags()->syncWithoutDetaching([$homeworkTag->id]));
        }

        // Reading-related work.
        if ($readingTag !== null) {
            Task::query()
                ->where('user_id', $user->id)
                ->whereIn('title', [
                    'MATH 201 – Reading: Trees & Traversals',
                    'ENG 105 – Reading Response #3',
                    'Library research for history essay',
                ])
                ->get()
                ->each(fn (Task $task) => $task->tags()->syncWithoutDetaching([$readingTag->id]));
        }

        // Health and household for chores.
        if ($healthTag !== null) {
            Task::query()
                ->where('user_id', $user->id)
                ->whereIn('title', [
                    'Walk 10k steps',
                ])
                ->get()
                ->each(fn (Task $task) => $task->tags()->syncWithoutDetaching([$healthTag->id]));
        }

        if ($householdTag !== null) {
            Task::query()
                ->where('user_id', $user->id)
                ->whereIn('title', [
                    'Wash dishes after dinner',
                    'Prepare tomorrow’s school bag',
                ])
                ->get()
                ->each(fn (Task $task) => $task->tags()->syncWithoutDetaching([$householdTag->id]));
        }

        // Career-oriented tasks.
        if ($careerTag !== null) {
            Task::query()
                ->where('user_id', $user->id)
                ->whereIn('title', [
                    'Update CV and LinkedIn profile',
                    'Practice coding interview problems',
                ])
                ->get()
                ->each(fn (Task $task) => $task->tags()->syncWithoutDetaching([$careerTag->id]));
        }

        // Tag events as exam / academic / social.
        Event::query()
            ->where('user_id', $user->id)
            ->where('title', 'Math exam review session')
            ->get()
            ->each(function (Event $event) use ($examTag, $readingTag): void {
                $ids = collect([$examTag?->id, $readingTag?->id])->filter()->all();
                if ($ids !== []) {
                    $event->tags()->syncWithoutDetaching($ids);
                }
            });

        Event::query()
            ->where('user_id', $user->id)
            ->where('title', 'CS group project meetup')
            ->get()
            ->each(function (Event $event) use ($homeworkTag): void {
                if ($homeworkTag !== null) {
                    $event->tags()->syncWithoutDetaching([$homeworkTag->id]);
                }
            });

        Event::query()
            ->where('user_id', $user->id)
            ->where('title', 'Campus club orientation night')
            ->get()
            ->each(function (Event $event): void {
                // Left untagged or could attach a future "Social" tag here.
            });
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

    private function seedRecurringEvents(User $user, Carbon $now): void
    {
        $event = Event::query()
            ->where('user_id', $user->id)
            ->where('title', 'Campus club orientation night')
            ->first();

        if ($event === null) {
            return;
        }

        RecurringEvent::create([
            'event_id' => $event->id,
            'recurrence_type' => EventRecurrenceType::Weekly,
            'interval' => 1,
            'days_of_week' => json_encode([4]), // Friday
            'start_datetime' => $event->start_datetime,
            'end_datetime' => $event->start_datetime?->copy()->addMonths(2),
        ]);
    }

    private function clampTaskDueDate(?Carbon $end, ?Carbon $completedAt, Carbon $dueDateFloor, string $entropyKey): ?Carbon
    {
        if ($end === null) {
            return null;
        }

        if ($completedAt !== null) {
            return $end;
        }

        if ($end->greaterThanOrEqualTo($dueDateFloor)) {
            return $end;
        }

        $jitterDays = $this->stableJitterDays($entropyKey, 21);

        return $dueDateFloor->copy()
            ->addDays($jitterDays)
            ->setTime($end->hour, $end->minute, $end->second);
    }

    private function stableJitterDays(string $key, int $range): int
    {
        if ($range <= 0) {
            return 0;
        }

        $hash = crc32($key);

        return (int) (abs($hash) % $range);
    }
}
