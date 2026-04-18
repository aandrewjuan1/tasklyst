<?php

namespace Database\Seeders;

use App\Enums\EventStatus;
use App\Enums\TaskComplexity;
use App\Enums\TaskPriority;
use App\Enums\TaskSourceType;
use App\Enums\TaskStatus;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;

class AndrewJuanRealisticSeeder extends Seeder
{
    private const TARGET_EMAIL = 'andrew.juan.cvt@eac.edu.ph';

    private const BRIGHTSPACE_LINK = 'https://eac.brightspace.com/d2l/lms/dropbox/user/folder_submit_files.d2l?db=220208&grpid=0&isprv=0&bp=0&ou=112348';

    public function run(): void
    {
        $user = User::query()->where('email', self::TARGET_EMAIL)->first();

        if (! $user instanceof User) {
            throw new RuntimeException('Cannot seed realistic student data because the target user does not exist: '.self::TARGET_EMAIL);
        }

        $courseProjects = $this->seedProjects($user);
        $events = $this->seedEvents($user);
        $this->seedDailyAndAcademicTasks($user, $courseProjects, $events);
        $this->seedBrightspaceTasks($user, $courseProjects);
    }

    /**
     * @return array<string, Project>
     */
    private function seedProjects(User $user): array
    {
        $semesterStart = CarbonImmutable::create(2026, 6, 15, 0, 0, 0, 'Asia/Manila');
        $semesterEnd = CarbonImmutable::create(2026, 10, 25, 23, 59, 0, 'Asia/Manila');

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
                'start' => CarbonImmutable::create(2026, 8, 17, 8, 0, 0, 'Asia/Manila'),
                'end' => CarbonImmutable::create(2026, 8, 17, 9, 30, 0, 'Asia/Manila'),
            ],
            'dbms_lab' => [
                'title' => 'DBMS Laboratory',
                'description' => 'Hands-on SQL joins, normalization checks, and indexing exercises.',
                'start' => CarbonImmutable::create(2026, 8, 18, 13, 0, 0, 'Asia/Manila'),
                'end' => CarbonImmutable::create(2026, 8, 18, 15, 0, 0, 'Asia/Manila'),
            ],
            'websys_class' => [
                'title' => 'Web Systems Class',
                'description' => 'Sprint planning and API integration walkthrough for team project.',
                'start' => CarbonImmutable::create(2026, 8, 19, 10, 0, 0, 'Asia/Manila'),
                'end' => CarbonImmutable::create(2026, 8, 19, 11, 30, 0, 'Asia/Manila'),
            ],
            'hci_discussion' => [
                'title' => 'HCI Discussion',
                'description' => 'Usability heuristics and critique of mobile interface submissions.',
                'start' => CarbonImmutable::create(2026, 8, 20, 14, 0, 0, 'Asia/Manila'),
                'end' => CarbonImmutable::create(2026, 8, 20, 15, 30, 0, 'Asia/Manila'),
            ],
            'discrete_class' => [
                'title' => 'Discrete Structures Class',
                'description' => 'Graph traversal problems and proof practice with peer checks.',
                'start' => CarbonImmutable::create(2026, 8, 21, 9, 0, 0, 'Asia/Manila'),
                'end' => CarbonImmutable::create(2026, 8, 21, 10, 30, 0, 'Asia/Manila'),
            ],
            'org_meeting' => [
                'title' => 'CCS Student Org Weekly Meeting',
                'description' => 'Project updates, outreach planning, and committee assignments.',
                'start' => CarbonImmutable::create(2026, 8, 21, 17, 30, 0, 'Asia/Manila'),
                'end' => CarbonImmutable::create(2026, 8, 21, 18, 30, 0, 'Asia/Manila'),
            ],
            'faculty_consultation' => [
                'title' => 'Faculty Consultation - Web Systems',
                'description' => 'Progress check on authentication flow and deployment blockers.',
                'start' => CarbonImmutable::create(2026, 8, 22, 11, 0, 0, 'Asia/Manila'),
                'end' => CarbonImmutable::create(2026, 8, 22, 11, 45, 0, 'Asia/Manila'),
            ],
            'midterm_review' => [
                'title' => 'Midterm Review Session',
                'description' => 'Consolidated review for DSA and Discrete Structures midterms.',
                'start' => CarbonImmutable::create(2026, 8, 23, 15, 0, 0, 'Asia/Manila'),
                'end' => CarbonImmutable::create(2026, 8, 23, 17, 0, 0, 'Asia/Manila'),
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
     */
    private function seedDailyAndAcademicTasks(User $user, array $courseProjects, array $events): void
    {
        $tasks = [
            [
                'title' => 'Sweep and tidy dorm room before evening study',
                'description' => 'Keep desk and floor clean to avoid distractions during coding blocks.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Medium,
                'complexity' => TaskComplexity::Simple,
                'start_datetime' => CarbonImmutable::create(2026, 8, 18, 18, 0, 0, 'Asia/Manila'),
                'end_datetime' => CarbonImmutable::create(2026, 8, 18, 18, 30, 0, 'Asia/Manila'),
                'duration' => 30,
                'project_id' => null,
                'event_id' => null,
            ],
            [
                'title' => 'Laundry and fold uniforms',
                'description' => 'Wash class uniforms and prep clothes for the next three school days.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Medium,
                'complexity' => TaskComplexity::Simple,
                'start_datetime' => CarbonImmutable::create(2026, 8, 22, 8, 0, 0, 'Asia/Manila'),
                'end_datetime' => CarbonImmutable::create(2026, 8, 22, 10, 0, 0, 'Asia/Manila'),
                'duration' => 120,
                'project_id' => null,
                'event_id' => null,
            ],
            [
                'title' => 'Plan weekly allowance and expenses',
                'description' => 'Track transportation, food, and printing costs for this week.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Low,
                'complexity' => TaskComplexity::Simple,
                'start_datetime' => CarbonImmutable::create(2026, 8, 18, 20, 30, 0, 'Asia/Manila'),
                'end_datetime' => CarbonImmutable::create(2026, 8, 18, 21, 0, 0, 'Asia/Manila'),
                'duration' => 30,
                'project_id' => null,
                'event_id' => null,
            ],
            [
                'title' => 'Implement linked list lab exercises',
                'description' => 'Complete insertion, deletion, and traversal operations with test cases.',
                'status' => TaskStatus::Doing,
                'priority' => TaskPriority::High,
                'complexity' => TaskComplexity::Complex,
                'start_datetime' => CarbonImmutable::create(2026, 8, 17, 19, 0, 0, 'Asia/Manila'),
                'end_datetime' => CarbonImmutable::create(2026, 8, 19, 23, 0, 0, 'Asia/Manila'),
                'duration' => 240,
                'project_id' => $courseProjects['dsa']->id,
                'event_id' => $events['dsa_lecture']->id,
            ],
            [
                'title' => 'Create ERD for enrollment module',
                'description' => 'Model entities and relationships before converting to normalized tables.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::High,
                'complexity' => TaskComplexity::Moderate,
                'start_datetime' => CarbonImmutable::create(2026, 8, 18, 16, 0, 0, 'Asia/Manila'),
                'end_datetime' => CarbonImmutable::create(2026, 8, 20, 21, 0, 0, 'Asia/Manila'),
                'duration' => 150,
                'project_id' => $courseProjects['dbms']->id,
                'event_id' => $events['dbms_lab']->id,
            ],
            [
                'title' => 'Prepare API integration demo for web systems',
                'description' => 'Finalize endpoint contract and rehearse presentation flow with teammate.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Urgent,
                'complexity' => TaskComplexity::Complex,
                'start_datetime' => CarbonImmutable::create(2026, 8, 20, 18, 30, 0, 'Asia/Manila'),
                'end_datetime' => CarbonImmutable::create(2026, 8, 22, 9, 0, 0, 'Asia/Manila'),
                'duration' => 210,
                'project_id' => $courseProjects['websys']->id,
                'event_id' => $events['websys_class']->id,
            ],
            [
                'title' => 'Revise usability test script',
                'description' => 'Polish interview prompts and success metrics for HCI usability run.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Medium,
                'complexity' => TaskComplexity::Moderate,
                'start_datetime' => CarbonImmutable::create(2026, 8, 20, 19, 0, 0, 'Asia/Manila'),
                'end_datetime' => CarbonImmutable::create(2026, 8, 21, 12, 0, 0, 'Asia/Manila'),
                'duration' => 90,
                'project_id' => $courseProjects['hci']->id,
                'event_id' => $events['hci_discussion']->id,
            ],
            [
                'title' => 'Solve 25 discrete structures practice items',
                'description' => 'Focus on proof by induction and graph connectivity examples.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::High,
                'complexity' => TaskComplexity::Moderate,
                'start_datetime' => CarbonImmutable::create(2026, 8, 21, 19, 30, 0, 'Asia/Manila'),
                'end_datetime' => CarbonImmutable::create(2026, 8, 23, 14, 0, 0, 'Asia/Manila'),
                'duration' => 180,
                'project_id' => $courseProjects['discrete']->id,
                'event_id' => $events['discrete_class']->id,
            ],
            [
                'title' => 'Basketball conditioning with classmates',
                'description' => 'One-hour run at the court for cardio and stress reset.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Low,
                'complexity' => TaskComplexity::Simple,
                'start_datetime' => CarbonImmutable::create(2026, 8, 22, 17, 0, 0, 'Asia/Manila'),
                'end_datetime' => CarbonImmutable::create(2026, 8, 22, 18, 15, 0, 'Asia/Manila'),
                'duration' => 75,
                'project_id' => null,
                'event_id' => null,
            ],
            [
                'title' => 'Practice guitar for campus acoustic night',
                'description' => 'Run through two songs and clean up chord transitions.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Low,
                'complexity' => TaskComplexity::Simple,
                'start_datetime' => CarbonImmutable::create(2026, 8, 23, 19, 30, 0, 'Asia/Manila'),
                'end_datetime' => CarbonImmutable::create(2026, 8, 23, 20, 30, 0, 'Asia/Manila'),
                'duration' => 60,
                'project_id' => null,
                'event_id' => null,
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
     */
    private function seedBrightspaceTasks(User $user, array $courseProjects): void
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
                'start_datetime' => CarbonImmutable::create(2026, 8, 17, 12, 0, 0, 'Asia/Manila'),
                'end_datetime' => CarbonImmutable::create(2026, 8, 19, 23, 59, 0, 'Asia/Manila'),
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
                'start_datetime' => CarbonImmutable::create(2026, 8, 18, 10, 0, 0, 'Asia/Manila'),
                'end_datetime' => CarbonImmutable::create(2026, 8, 21, 23, 59, 0, 'Asia/Manila'),
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
                'start_datetime' => CarbonImmutable::create(2026, 8, 19, 9, 0, 0, 'Asia/Manila'),
                'end_datetime' => CarbonImmutable::create(2026, 8, 22, 18, 0, 0, 'Asia/Manila'),
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
                'start_datetime' => CarbonImmutable::create(2026, 8, 20, 8, 0, 0, 'Asia/Manila'),
                'end_datetime' => CarbonImmutable::create(2026, 8, 24, 17, 0, 0, 'Asia/Manila'),
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
                    'calendar_feed_id' => null,
                ],
            );
        }
    }
}
