<?php

namespace Database\Seeders;

use App\Enums\EventStatus;
use App\Enums\TaskComplexity;
use App\Enums\TaskPriority;
use App\Enums\TaskRecurrenceType;
use App\Enums\TaskSourceType;
use App\Enums\TaskStatus;
use App\Models\Event;
use App\Models\Project;
use App\Models\SchoolClass;
use App\Models\Task;
use App\Models\Teacher;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;

class AndrewJuanRealisticSeeder extends Seeder
{
    private const TARGET_EMAIL = 'andrew.juan.cvt@eac.edu.ph';

    private const TIMEZONE = 'Asia/Manila';

    private const BRIGHTSPACE_LINK = 'https://eac.brightspace.com/d2l/lms/dropbox/user/folder_submit_files.d2l?db=220208&grpid=0&isprv=0&bp=0&ou=112348';

    public function run(): void
    {
        $user = User::query()->where('email', self::TARGET_EMAIL)->first();

        if (! $user instanceof User) {
            throw new RuntimeException('Cannot seed realistic student data because the target user does not exist: '.self::TARGET_EMAIL);
        }

        $courseProjects = $this->seedProjects($user);
        $events = $this->seedEvents($user);
        $schoolClasses = $this->seedSchoolClasses($user);
        $this->seedDailyAndAcademicTasks($user, $courseProjects, $events, $schoolClasses);
        $this->seedBrightspaceTasks($user, $courseProjects, $schoolClasses);
    }

    /**
     * @return array<string, Project>
     */
    private function seedProjects(User $user): array
    {
        $semesterStart = $this->at(-21)->startOfDay();
        $semesterEnd = $this->at(100, 23, 59)->endOfMinute();

        $courses = [
            'dsa' => ['name' => 'Data Structures and Algorithms', 'description' => 'Core algorithms course with coding labs and weekly problem sets.'],
            'dbms' => ['name' => 'Database Management Systems', 'description' => 'Relational modeling, SQL optimization, and practical database implementation.'],
            'websys' => ['name' => 'Web Systems and Technologies', 'description' => 'Frontend/backend integration, deployment, and secure web development practices.'],
            'hci' => ['name' => 'Human-Computer Interaction', 'description' => 'User research, wireframing, and usability testing for interactive systems.'],
            'discrete' => ['name' => 'Discrete Structures', 'description' => 'Logic, proofs, sets, and graph theory foundations for computing.'],
        ];

        $projects = [];

        foreach ($courses as $key => $course) {
            $projects[$key] = Project::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'name' => $course['name'],
                ],
                [
                    'description' => $course['description'],
                    'start_datetime' => $semesterStart,
                    'end_datetime' => $semesterEnd,
                ],
            );
        }

        return $projects;
    }

    /**
     * @return array<string, Event>
     */
    private function seedEvents(User $user): array
    {
        $events = [
            'dsa_lecture' => [
                'title' => 'DSA Lecture',
                'description' => 'Topic: recursion trees and divide-and-conquer runtime analysis.',
                'start' => $this->fromNow(60),
                'end' => $this->fromNow(150),
            ],
            'dbms_lab' => [
                'title' => 'DBMS Laboratory',
                'description' => 'Hands-on SQL joins, normalization checks, and indexing exercises.',
                'start' => $this->at(1, 13, 0),
                'end' => $this->at(1, 15, 0),
            ],
            'websys_class' => [
                'title' => 'Web Systems Class',
                'description' => 'Sprint planning and API integration walkthrough for team project.',
                'start' => $this->at(2, 10, 0),
                'end' => $this->at(2, 11, 30),
            ],
            'hci_discussion' => [
                'title' => 'HCI Discussion',
                'description' => 'Usability heuristics and critique of mobile interface submissions.',
                'start' => $this->at(3, 14, 0),
                'end' => $this->at(3, 15, 30),
            ],
            'discrete_class' => [
                'title' => 'Discrete Structures Class',
                'description' => 'Graph traversal problems and proof practice with peer checks.',
                'start' => $this->at(4, 9, 0),
                'end' => $this->at(4, 10, 30),
            ],
            'org_meeting' => [
                'title' => 'CCS Student Org Weekly Meeting',
                'description' => 'Project updates, outreach planning, and committee assignments.',
                'start' => $this->at(4, 17, 30),
                'end' => $this->at(4, 18, 30),
            ],
            'faculty_consultation' => [
                'title' => 'Faculty Consultation - Web Systems',
                'description' => 'Progress check on authentication flow and deployment blockers.',
                'start' => $this->at(5, 11, 0),
                'end' => $this->at(5, 11, 45),
            ],
            'midterm_review' => [
                'title' => 'Midterm Review Session',
                'description' => 'Consolidated review for DSA and Discrete Structures midterms.',
                'start' => $this->at(6, 15, 0),
                'end' => $this->at(6, 17, 0),
            ],
        ];

        $seededEvents = [];

        foreach ($events as $key => $event) {
            $seededEvents[$key] = Event::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'title' => $event['title'],
                    'start_datetime' => $event['start'],
                ],
                [
                    'description' => $event['description'],
                    'end_datetime' => $event['end'],
                    'all_day' => false,
                    'status' => EventStatus::Scheduled,
                ],
            );
        }

        return $seededEvents;
    }

    /**
     * @param  array<string, Project>  $courseProjects
     * @param  array<string, Event>  $events
     * @param  array<string, SchoolClass>  $schoolClasses
     */
    private function seedDailyAndAcademicTasks(User $user, array $courseProjects, array $events, array $schoolClasses): void
    {
        $tasks = [
            [
                'title' => 'Sweep and tidy dorm room before evening study',
                'description' => 'Keep desk and floor clean to avoid distractions during coding blocks.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Medium,
                'complexity' => TaskComplexity::Simple,
                'start_datetime' => $this->fromNow(90),
                'end_datetime' => $this->fromNow(120),
                'duration' => 30,
                'project_id' => null,
                'event_id' => null,
                'school_class_id' => null,
            ],
            [
                'title' => 'Laundry and fold uniforms',
                'description' => 'Wash class uniforms and prep clothes for the next three school days.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Medium,
                'complexity' => TaskComplexity::Simple,
                'start_datetime' => $this->at(4, 8, 0),
                'end_datetime' => $this->at(4, 10, 0),
                'duration' => 120,
                'project_id' => null,
                'event_id' => null,
                'school_class_id' => null,
            ],
            [
                'title' => 'Plan weekly allowance and expenses',
                'description' => 'Track transportation, food, and printing costs for this week.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Low,
                'complexity' => TaskComplexity::Simple,
                'start_datetime' => $this->fromNow(180),
                'end_datetime' => $this->fromNow(210),
                'duration' => 30,
                'project_id' => null,
                'event_id' => null,
                'school_class_id' => null,
            ],
            [
                'title' => 'Implement linked list lab exercises',
                'description' => 'Complete insertion, deletion, and traversal operations with test cases.',
                'status' => TaskStatus::Doing,
                'priority' => TaskPriority::High,
                'complexity' => TaskComplexity::Complex,
                'start_datetime' => $this->at(0, 19, 0),
                'end_datetime' => $this->at(1, 23, 0),
                'duration' => 240,
                'project_id' => $courseProjects['dsa']->id,
                'event_id' => $events['dsa_lecture']->id,
                'school_class_id' => $schoolClasses['dsa']->id,
            ],
            [
                'title' => 'Create ERD for enrollment module',
                'description' => 'Model entities and relationships before converting to normalized tables.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::High,
                'complexity' => TaskComplexity::Moderate,
                'start_datetime' => $this->at(0, 16, 0),
                'end_datetime' => $this->at(2, 21, 0),
                'duration' => 150,
                'project_id' => $courseProjects['dbms']->id,
                'event_id' => $events['dbms_lab']->id,
                'school_class_id' => $schoolClasses['dbms']->id,
            ],
            [
                'title' => 'Prepare API integration demo for web systems',
                'description' => 'Finalize endpoint contract and rehearse presentation flow with teammate.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Urgent,
                'complexity' => TaskComplexity::Complex,
                'start_datetime' => $this->at(2, 18, 30),
                'end_datetime' => $this->at(4, 9, 0),
                'duration' => 210,
                'project_id' => $courseProjects['websys']->id,
                'event_id' => $events['websys_class']->id,
                'school_class_id' => $schoolClasses['websys']->id,
            ],
            [
                'title' => 'Revise usability test script',
                'description' => 'Polish interview prompts and success metrics for HCI usability run.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Medium,
                'complexity' => TaskComplexity::Moderate,
                'start_datetime' => $this->at(2, 19, 0),
                'end_datetime' => $this->at(3, 12, 0),
                'duration' => 90,
                'project_id' => $courseProjects['hci']->id,
                'event_id' => $events['hci_discussion']->id,
                'school_class_id' => $schoolClasses['hci']->id,
            ],
            [
                'title' => 'Solve 25 discrete structures practice items',
                'description' => 'Focus on proof by induction and graph connectivity examples.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::High,
                'complexity' => TaskComplexity::Moderate,
                'start_datetime' => $this->at(3, 19, 30),
                'end_datetime' => $this->at(5, 14, 0),
                'duration' => 180,
                'project_id' => $courseProjects['discrete']->id,
                'event_id' => $events['discrete_class']->id,
                'school_class_id' => $schoolClasses['discrete']->id,
            ],
            [
                'title' => 'Basketball conditioning with classmates',
                'description' => 'One-hour run at the court for cardio and stress reset.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Low,
                'complexity' => TaskComplexity::Simple,
                'start_datetime' => $this->at(4, 17, 0),
                'end_datetime' => $this->at(4, 18, 15),
                'duration' => 75,
                'project_id' => null,
                'event_id' => null,
                'school_class_id' => null,
            ],
            [
                'title' => 'Practice guitar for campus acoustic night',
                'description' => 'Run through two songs and clean up chord transitions.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Low,
                'complexity' => TaskComplexity::Simple,
                'start_datetime' => $this->at(5, 19, 30),
                'end_datetime' => $this->at(5, 20, 30),
                'duration' => 60,
                'project_id' => null,
                'event_id' => null,
                'school_class_id' => null,
            ],
        ];

        foreach ($tasks as $task) {
            Task::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'title' => $task['title'],
                    'start_datetime' => $task['start_datetime'],
                ],
                [
                    'description' => $task['description'],
                    'status' => $task['status'],
                    'priority' => $task['priority'],
                    'complexity' => $task['complexity'],
                    'duration' => $task['duration'],
                    'end_datetime' => $task['end_datetime'],
                    'project_id' => $task['project_id'],
                    'event_id' => $task['event_id'],
                    'school_class_id' => $task['school_class_id'],
                    'source_type' => TaskSourceType::Manual,
                    'source_id' => null,
                    'source_url' => null,
                    'teacher_name' => null,
                    'subject_name' => null,
                ],
            );
        }
    }

    /**
     * @param  array<string, Project>  $courseProjects
     * @param  array<string, SchoolClass>  $schoolClasses
     */
    private function seedBrightspaceTasks(User $user, array $courseProjects, array $schoolClasses): void
    {
        $brightspaceTasks = [
            [
                'source_id' => 'BS-IT-DSA-220208',
                'title' => 'Brightspace: DSA Problem Set 3 Submission',
                'description' => 'Upload PDF solution and C++ source files before section cutoff.',
                'teacher_name' => 'Prof. Maria Santos',
                'subject_name' => 'Data Structures and Algorithms',
                'priority' => TaskPriority::High,
                'complexity' => TaskComplexity::Complex,
                'project_id' => $courseProjects['dsa']->id,
                'school_class_id' => $schoolClasses['dsa']->id,
                'start_datetime' => $this->at(0, 12, 0),
                'end_datetime' => $this->at(1, 23, 59),
                'duration' => 180,
            ],
            [
                'source_id' => 'BS-IT-DBMS-220208',
                'title' => 'Brightspace: DBMS SQL Optimization Lab',
                'description' => 'Submit query tuning report with before/after execution plans.',
                'teacher_name' => 'Prof. Noel Ramirez',
                'subject_name' => 'Database Management Systems',
                'priority' => TaskPriority::High,
                'complexity' => TaskComplexity::Moderate,
                'project_id' => $courseProjects['dbms']->id,
                'school_class_id' => $schoolClasses['dbms']->id,
                'start_datetime' => $this->at(0, 10, 0),
                'end_datetime' => $this->at(3, 23, 59),
                'duration' => 150,
            ],
            [
                'source_id' => 'BS-IT-WEB-220208',
                'title' => 'Brightspace: Web Systems Sprint Retrospective',
                'description' => 'Post retrospective insights and attach sprint board screenshots.',
                'teacher_name' => 'Engr. Carlo Fernandez',
                'subject_name' => 'Web Systems and Technologies',
                'priority' => TaskPriority::Medium,
                'complexity' => TaskComplexity::Moderate,
                'project_id' => $courseProjects['websys']->id,
                'school_class_id' => $schoolClasses['websys']->id,
                'start_datetime' => $this->at(1, 9, 0),
                'end_datetime' => $this->at(4, 18, 0),
                'duration' => 120,
            ],
            [
                'source_id' => 'BS-IT-HCI-220208',
                'title' => 'Brightspace: HCI Persona and Journey Map',
                'description' => 'Upload final persona sheet and customer journey map presentation.',
                'teacher_name' => 'Prof. Lea Mendoza',
                'subject_name' => 'Human-Computer Interaction',
                'priority' => TaskPriority::Medium,
                'complexity' => TaskComplexity::Moderate,
                'project_id' => $courseProjects['hci']->id,
                'school_class_id' => $schoolClasses['hci']->id,
                'start_datetime' => $this->at(2, 8, 0),
                'end_datetime' => $this->at(6, 17, 0),
                'duration' => 120,
            ],
        ];

        foreach ($brightspaceTasks as $task) {
            Task::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'source_type' => TaskSourceType::Brightspace,
                    'source_id' => $task['source_id'],
                ],
                [
                    'title' => $task['title'],
                    'description' => $task['description'],
                    'teacher_name' => $task['teacher_name'],
                    'subject_name' => $task['subject_name'],
                    'status' => TaskStatus::ToDo,
                    'priority' => $task['priority'],
                    'complexity' => $task['complexity'],
                    'source_url' => self::BRIGHTSPACE_LINK,
                    'duration' => $task['duration'],
                    'start_datetime' => $task['start_datetime'],
                    'end_datetime' => $task['end_datetime'],
                    'project_id' => $task['project_id'],
                    'event_id' => null,
                    'school_class_id' => $task['school_class_id'],
                    'calendar_feed_id' => null,
                ],
            );
        }
    }

    /**
     * @return array<string, SchoolClass>
     */
    private function seedSchoolClasses(User $user): array
    {
        $recurrenceWindowEnd = $this->at(100, 23, 59)->endOfMinute();

        $classes = [
            'dsa' => [
                'subject_name' => 'Data Structures and Algorithms',
                'teacher_name' => 'Prof. Maria Santos',
                'start_datetime' => $this->fromNow(75),
                'end_datetime' => $this->fromNow(165),
            ],
            'dbms' => [
                'subject_name' => 'Database Management Systems',
                'teacher_name' => 'Prof. Noel Ramirez',
                'start_datetime' => $this->at(1, 13, 0),
                'end_datetime' => $this->at(1, 15, 0),
                'recurring_days_of_week' => [2, 4], // Tue, Thu
            ],
            'websys' => [
                'subject_name' => 'Web Systems and Technologies',
                'teacher_name' => 'Engr. Carlo Fernandez',
                'start_datetime' => $this->at(2, 10, 0),
                'end_datetime' => $this->at(2, 11, 30),
            ],
            'hci' => [
                'subject_name' => 'Human-Computer Interaction',
                'teacher_name' => 'Prof. Lea Mendoza',
                'start_datetime' => $this->at(3, 14, 0),
                'end_datetime' => $this->at(3, 15, 30),
                'recurring_days_of_week' => [4], // Thu
            ],
            'discrete' => [
                'subject_name' => 'Discrete Structures',
                'teacher_name' => 'Prof. Raymond Castillo',
                'start_datetime' => $this->at(4, 9, 0),
                'end_datetime' => $this->at(4, 10, 30),
                'recurring_days_of_week' => [1, 5], // Mon, Fri
            ],
        ];

        $seededClasses = [];

        foreach ($classes as $key => $class) {
            $teacher = Teacher::firstOrCreateByDisplayName($user->id, $class['teacher_name']);

            $seededClasses[$key] = SchoolClass::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'subject_name' => $class['subject_name'],
                    'start_datetime' => $class['start_datetime'],
                ],
                [
                    'teacher_id' => $teacher->id,
                    'start_time' => $class['start_datetime']->format('H:i:s'),
                    'end_time' => $class['end_datetime']->format('H:i:s'),
                    'end_datetime' => $class['end_datetime'],
                ],
            );

            $daysOfWeek = $class['recurring_days_of_week'] ?? null;
            if (is_array($daysOfWeek) && $daysOfWeek !== []) {
                $seededClasses[$key]->recurringSchoolClass()->updateOrCreate(
                    ['school_class_id' => $seededClasses[$key]->id],
                    [
                        'recurrence_type' => TaskRecurrenceType::Weekly,
                        'interval' => 1,
                        'start_datetime' => $class['start_datetime'],
                        'end_datetime' => $recurrenceWindowEnd,
                        'days_of_week' => json_encode(array_values($daysOfWeek)),
                    ],
                );
            } else {
                $seededClasses[$key]->recurringSchoolClass()?->delete();
            }
        }

        return $seededClasses;
    }

    private function at(int $dayOffset, int $hour = 0, int $minute = 0): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)
            ->startOfDay()
            ->addDays($dayOffset)
            ->setTime($hour, $minute);
    }

    private function fromNow(int $minutes): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)
            ->addMinutes($minutes)
            ->setSecond(0);
    }
}
