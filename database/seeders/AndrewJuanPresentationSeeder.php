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
use App\Models\Tag;
use App\Models\Task;
use App\Models\Teacher;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Append-only, idempotent demo dataset for professor presentation (anchor: May 5, 2026, Asia/Manila).
 *
 * Run manually: php artisan db:seed --class=AndrewJuanPresentationSeeder
 */
class AndrewJuanPresentationSeeder extends Seeder
{
    public const TARGET_EMAIL = 'andrew.juan.cvt@eac.edu.ph';

    private const TIMEZONE = 'Asia/Manila';

    /**
     * Stable names for tests; no user-visible demo prefix.
     *
     * @var list<string>
     */
    public const SEEDED_PROJECT_NAMES = [
        'Capstone prototype sprint',
        'Systems analysis deliverables',
        'Spring org fair logistics',
        'Dorm and weekly life admin',
        'Finals survival pack',
    ];

    /**
     * @var list<string>
     */
    public const SEEDED_EVENT_TITLES = [
        'Quiet reading block (library)',
        'Org fair volunteer sync',
        'Peer tutoring (DSA walkthrough)',
        'Campus fair booth shift',
        'Capstone team stand-up',
        'Org committee debrief',
        'Evening midterm review session',
        'Faculty consultation (Web Systems)',
        'All-day study blackout (no meetings)',
        'Pick-up basketball',
    ];

    /**
     * @var list<string>
     */
    public const SEEDED_CLASS_SUBJECT_NAMES = [
        'Discrete Structures',
        'Data Structures and Algorithms',
        'Database Systems Laboratory',
        'Web Systems and Technologies',
        'Human-Computer Interaction Studio',
    ];

    private const BRIGHTSPACE_LINK = 'https://eac.brightspace.com/d2l/lms/dropbox/user/folder_submit_files.d2l?db=220208&grpid=0&isprv=0&bp=0&ou=112348';

    public function run(): void
    {
        $user = User::query()->where('email', self::TARGET_EMAIL)->first();

        if (! $user instanceof User) {
            throw new RuntimeException('Cannot seed presentation data because the target user does not exist: '.self::TARGET_EMAIL);
        }

        DB::transaction(function () use ($user): void {
            $may = fn (int $day, int $hour = 0, int $minute = 0): CarbonImmutable => CarbonImmutable::create(2026, 5, $day, $hour, $minute, 0, self::TIMEZONE);

            $recurrenceEnd = $may(31, 23, 59)->endOfMinute();

            $tagsByName = $this->seedTags($user);
            $projects = $this->seedProjects($user, $may);
            $schoolClasses = $this->seedSchoolClasses($user, $may, $recurrenceEnd);
            $events = $this->seedEvents($user, $may);

            $taskSpecs = $this->taskSpecs($projects, $schoolClasses, $events, $may);
            $createdTasks = [];

            foreach ($taskSpecs as $spec) {
                $task = Task::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'source_type' => $spec['source_type'],
                        'source_id' => $spec['source_id'],
                    ],
                    [
                        'title' => $spec['title'],
                        'description' => $spec['description'],
                        'teacher_name' => $spec['teacher_name'] ?? null,
                        'subject_name' => $spec['subject_name'] ?? null,
                        'status' => $spec['status'],
                        'priority' => $spec['priority'],
                        'complexity' => $spec['complexity'],
                        'duration' => $spec['duration'],
                        'start_datetime' => $spec['start_datetime'] ?? null,
                        'end_datetime' => $spec['end_datetime'] ?? null,
                        'project_id' => $spec['project_id'] ?? null,
                        'event_id' => $spec['event_id'] ?? null,
                        'school_class_id' => $spec['school_class_id'] ?? null,
                        'source_url' => $spec['source_url'] ?? null,
                        'calendar_feed_id' => null,
                        'completed_at' => null,
                    ]
                );

                if (! empty($spec['recurring'])) {
                    $task->recurringTask()->updateOrCreate(
                        ['task_id' => $task->id],
                        [
                            'recurrence_type' => $spec['recurring']['recurrence_type'],
                            'interval' => $spec['recurring']['interval'],
                            'start_datetime' => $spec['recurring']['start_datetime'],
                            'end_datetime' => $spec['recurring']['end_datetime'],
                            'days_of_week' => $spec['recurring']['days_of_week'],
                        ]
                    );
                } else {
                    $task->recurringTask()?->delete();
                }

                $tagNames = $spec['tags'] ?? [];
                if ($tagNames !== []) {
                    $ids = [];
                    foreach ($tagNames as $tagName) {
                        $tag = $tagsByName[$tagName] ?? null;
                        if ($tag instanceof Tag) {
                            $ids[] = $tag->id;
                        }
                    }
                    $task->tags()->sync(array_values(array_unique($ids)));
                } else {
                    $task->tags()->sync([]);
                }

                $createdTasks[] = $task;
            }

            unset($createdTasks);
        });
    }

    /**
     * @return array<string, Tag>
     */
    private function seedTags(User $user): array
    {
        $names = ['school', 'chores', 'capstone', 'exam-prep', 'brightspace'];
        $tags = [];
        foreach ($names as $name) {
            $tags[$name] = Tag::query()->firstOrCreate(
                ['user_id' => $user->id, 'name' => $name],
            );
        }

        return $tags;
    }

    /**
     * @return array<string, Project>
     */
    private function seedProjects(User $user, callable $may): array
    {
        $defs = [
            'capstone' => [
                'name' => 'Capstone prototype sprint',
                'description' => 'Two-week push to stabilize the capstone demo build, tighten scope, and align with adviser feedback before the dry run.',
                'start' => $may(1, 8, 0),
                'end' => $may(18, 23, 59),
            ],
            'systems' => [
                'name' => 'Systems analysis deliverables',
                'description' => 'Coursework bundle for requirements, use cases, and lightweight architecture notes due before the department review window.',
                'start' => $may(2, 9, 0),
                'end' => $may(12, 23, 59),
            ],
            'org' => [
                'name' => 'Spring org fair logistics',
                'description' => 'Volunteer shifts, booth materials, and sponsor follow-ups for the student org’s May fair participation.',
                'start' => $may(3, 10, 0),
                'end' => $may(14, 20, 0),
            ],
            'dorm' => [
                'name' => 'Dorm and weekly life admin',
                'description' => 'Recurring chores, errands, and small maintenance tasks that keep the week runnable around heavy school blocks.',
                'start' => $may(1, 6, 0),
                'end' => $may(25, 22, 0),
            ],
            'finals' => [
                'name' => 'Finals survival pack',
                'description' => 'Consolidated review goals, mock exams, and buffer tasks for the last two weeks of the semester.',
                'start' => $may(6, 7, 0),
                'end' => $may(22, 23, 0),
            ],
        ];

        $projects = [];
        foreach ($defs as $key => $def) {
            $projects[$key] = Project::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'name' => $def['name'],
                ],
                [
                    'description' => $def['description'],
                    'start_datetime' => $def['start'],
                    'end_datetime' => $def['end'],
                ]
            );
        }

        return $projects;
    }

    /**
     * @return array<string, SchoolClass>
     */
    private function seedSchoolClasses(User $user, callable $may, CarbonImmutable $recurrenceEnd): array
    {
        $classes = [
            'discrete' => [
                'subject_name' => 'Discrete Structures',
                'teacher_name' => 'Prof. Demo Castillo',
                'start_datetime' => $may(4, 7, 30),
                'end_datetime' => $may(4, 9, 0),
                'recurring_days_of_week' => [CarbonImmutable::MONDAY, CarbonImmutable::FRIDAY],
            ],
            'dsa' => [
                'subject_name' => 'Data Structures and Algorithms',
                'teacher_name' => 'Prof. Demo Santos',
                'start_datetime' => $may(5, 9, 0),
                'end_datetime' => $may(5, 10, 30),
                'recurring_days_of_week' => [CarbonImmutable::TUESDAY, CarbonImmutable::THURSDAY],
            ],
            'dbms' => [
                'subject_name' => 'Database Systems Laboratory',
                'teacher_name' => 'Prof. Demo Ramirez',
                'start_datetime' => $may(5, 13, 0),
                'end_datetime' => $may(5, 15, 0),
                'recurring_days_of_week' => [CarbonImmutable::TUESDAY, CarbonImmutable::THURSDAY],
            ],
            'websys' => [
                'subject_name' => 'Web Systems and Technologies',
                'teacher_name' => 'Engr. Demo Fernandez',
                'start_datetime' => $may(7, 10, 0),
                'end_datetime' => $may(7, 11, 30),
                'recurring_days_of_week' => [CarbonImmutable::THURSDAY],
            ],
            'hci' => [
                'subject_name' => 'Human-Computer Interaction Studio',
                'teacher_name' => 'Prof. Demo Mendoza',
                'start_datetime' => $may(8, 14, 0),
                'end_datetime' => $may(8, 15, 30),
                'recurring_days_of_week' => [CarbonImmutable::FRIDAY],
            ],
        ];

        $seeded = [];

        foreach ($classes as $key => $class) {
            $teacher = Teacher::firstOrCreateByDisplayName($user->id, $class['teacher_name']);

            $seeded[$key] = SchoolClass::query()->updateOrCreate(
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
                ]
            );

            $days = $class['recurring_days_of_week'] ?? null;
            if (is_array($days) && $days !== []) {
                $seeded[$key]->recurringSchoolClass()->updateOrCreate(
                    ['school_class_id' => $seeded[$key]->id],
                    [
                        'recurrence_type' => TaskRecurrenceType::Weekly,
                        'interval' => 1,
                        'start_datetime' => $class['start_datetime'],
                        'end_datetime' => $recurrenceEnd,
                        'days_of_week' => json_encode(array_values($days)),
                    ]
                );
            } else {
                $seeded[$key]->recurringSchoolClass()?->delete();
            }
        }

        return $seeded;
    }

    /**
     * @return array<string, Event>
     */
    private function seedEvents(User $user, callable $may): array
    {
        $defs = [
            'morning_block' => [
                'title' => 'Quiet reading block (library)',
                'description' => 'Phone on focus mode. Skim assigned chapters and jot questions before afternoon labs.',
                'start' => $may(5, 6, 45),
                'end' => $may(5, 7, 45),
                'all_day' => false,
            ],
            'org_sync' => [
                'title' => 'Org fair volunteer sync',
                'description' => 'Quick Zoom to confirm booth setup time, transport of materials, and who covers the first shift.',
                'start' => $may(5, 11, 30),
                'end' => $may(5, 12, 15),
                'all_day' => false,
            ],
            'peer_tutoring' => [
                'title' => 'Peer tutoring (DSA walkthrough)',
                'description' => 'Whiteboard session on trees and heaps with a classmate; bring problem set printouts.',
                'start' => $may(5, 16, 0),
                'end' => $may(5, 17, 15),
                'all_day' => false,
            ],
            'fair_shift' => [
                'title' => 'Campus fair booth shift',
                'description' => 'On-site booth duty: demos, sign-up sheet, and answering visitor questions.',
                'start' => $may(6, 10, 0),
                'end' => $may(6, 14, 0),
                'all_day' => false,
            ],
            'standup' => [
                'title' => 'Capstone team stand-up',
                'description' => '15-minute unblocker on integration bugs and demo script timing.',
                'start' => $may(6, 16, 0),
                'end' => $may(6, 16, 45),
                'all_day' => false,
            ],
            'committee' => [
                'title' => 'Org committee debrief',
                'description' => 'Post-shift notes, expense tally, and follow-up tasks for next meeting.',
                'start' => $may(6, 17, 0),
                'end' => $may(6, 17, 45),
                'all_day' => false,
            ],
            'midterm_review' => [
                'title' => 'Evening midterm review session',
                'description' => 'Open review hosted by seniors; bring toughest problem sets and past quizzes.',
                'start' => $may(7, 18, 30),
                'end' => $may(7, 20, 30),
                'all_day' => false,
            ],
            'consultation' => [
                'title' => 'Faculty consultation (Web Systems)',
                'description' => 'Discuss auth edge cases and deployment checklist before sprint freeze.',
                'start' => $may(8, 11, 0),
                'end' => $may(8, 11, 45),
                'all_day' => false,
            ],
            'study_blackout' => [
                'title' => 'All-day study blackout (no meetings)',
                'description' => 'Guarded deep-work day: catch up on readings and capstone polish without accepting new commitments.',
                'start' => $may(9, 0, 0),
                'end' => $may(9, 23, 59),
                'all_day' => true,
            ],
            'basketball' => [
                'title' => 'Pick-up basketball',
                'description' => 'Light cardio and social reset before finals crunch week.',
                'start' => $may(10, 17, 0),
                'end' => $may(10, 18, 30),
                'all_day' => false,
            ],
        ];

        $events = [];

        foreach ($defs as $key => $event) {
            $events[$key] = Event::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'title' => $event['title'],
                    'start_datetime' => $event['start'],
                ],
                [
                    'description' => $event['description'],
                    'end_datetime' => $event['end'],
                    'all_day' => $event['all_day'],
                    'status' => EventStatus::Scheduled,
                ]
            );
        }

        return $events;
    }

    /**
     * @param  array<string, Project>  $projects
     * @param  array<string, SchoolClass>  $schoolClasses
     * @param  array<string, Event>  $events
     * @return list<array<string, mixed>>
     */
    private function taskSpecs(array $projects, array $schoolClasses, array $events, callable $may): array
    {
        $p = static fn (string $key): ?int => $projects[$key]->id ?? null;
        $c = static fn (string $key): ?int => $schoolClasses[$key]->id ?? null;
        $e = static fn (string $key): ?int => $events[$key]->id ?? null;

        $weekEnd = $may(31, 23, 0);

        return [
            [
                'source_id' => 'pres-demo-capstone-integration',
                'source_type' => TaskSourceType::Manual,
                'title' => 'Wire capstone modules into end-to-end demo flow',
                'description' => 'Connect auth, core CRUD, and reporting screens; fix the worst console errors so the adviser walkthrough feels intentional.',
                'status' => TaskStatus::Doing,
                'priority' => TaskPriority::High,
                'complexity' => TaskComplexity::Complex,
                'duration' => 180,
                'start_datetime' => $may(5, 20, 0),
                'end_datetime' => $may(7, 23, 0),
                'project_id' => $p('capstone'),
                'event_id' => null,
                'school_class_id' => null,
                'tags' => ['capstone', 'school'],
            ],
            [
                'source_id' => 'pres-demo-capstone-slides',
                'source_type' => TaskSourceType::Manual,
                'title' => 'Draft capstone defense slide outline',
                'description' => 'Story arc: problem, approach, results, limitations. Keep bullets tight so you can rehearse under time.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Urgent,
                'complexity' => TaskComplexity::Moderate,
                'duration' => 120,
                'start_datetime' => $may(6, 9, 0),
                'end_datetime' => $may(8, 12, 0),
                'project_id' => $p('capstone'),
                'event_id' => $e('standup'),
                'school_class_id' => null,
                'tags' => ['capstone', 'exam-prep'],
            ],
            [
                'source_id' => 'pres-demo-sys-use-cases',
                'source_type' => TaskSourceType::Manual,
                'title' => 'Finish use case diagram revisions',
                'description' => 'Incorporate lab feedback on actors and includes; export a clean PDF for submission.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::High,
                'complexity' => TaskComplexity::Moderate,
                'duration' => 90,
                'start_datetime' => $may(5, 18, 0),
                'end_datetime' => $may(9, 21, 0),
                'project_id' => $p('systems'),
                'event_id' => null,
                'school_class_id' => null,
                'tags' => ['school'],
            ],
            [
                'source_id' => 'pres-demo-sys-arch-notes',
                'source_type' => TaskSourceType::Manual,
                'title' => 'Lightweight architecture note for systems project',
                'description' => 'One page: major components, data flow, and risks. Enough to defend choices without overbuilding diagrams.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Medium,
                'complexity' => TaskComplexity::Moderate,
                'duration' => 75,
                'start_datetime' => $may(7, 15, 0),
                'end_datetime' => $may(11, 22, 0),
                'project_id' => $p('systems'),
                'event_id' => null,
                'school_class_id' => null,
                'tags' => ['school', 'exam-prep'],
            ],
            [
                'source_id' => 'pres-demo-org-printables',
                'source_type' => TaskSourceType::Manual,
                'title' => 'Print org fair handouts and QR signup',
                'description' => 'Proofread once, print on campus, and pack extras in a folder so the booth looks organized.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Medium,
                'complexity' => TaskComplexity::Simple,
                'duration' => 60,
                'start_datetime' => $may(5, 14, 0),
                'end_datetime' => $may(6, 9, 30),
                'project_id' => $p('org'),
                'event_id' => $e('fair_shift'),
                'school_class_id' => null,
                'tags' => ['school'],
            ],
            [
                'source_id' => 'pres-demo-org-sponsor-email',
                'source_type' => TaskSourceType::Manual,
                'title' => 'Send sponsor thank-you and photo recap',
                'description' => 'Short email with two best booth photos and a bullet list of turnout highlights.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Low,
                'complexity' => TaskComplexity::Simple,
                'duration' => 45,
                'start_datetime' => $may(7, 10, 0),
                'end_datetime' => $may(10, 18, 0),
                'project_id' => $p('org'),
                'event_id' => $e('committee'),
                'school_class_id' => null,
                'tags' => ['school'],
            ],
            [
                'source_id' => 'pres-demo-dorm-laundry',
                'source_type' => TaskSourceType::Manual,
                'title' => 'Laundry and fold uniforms',
                'description' => 'Wash uniforms and hang dry delicates so you are not scrambling the morning of lab days.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Medium,
                'complexity' => TaskComplexity::Simple,
                'duration' => 120,
                'start_datetime' => $may(6, 8, 0),
                'end_datetime' => $may(6, 11, 0),
                'project_id' => $p('dorm'),
                'event_id' => null,
                'school_class_id' => null,
                'tags' => ['chores'],
                'recurring' => [
                    'recurrence_type' => TaskRecurrenceType::Weekly,
                    'interval' => 1,
                    'start_datetime' => $may(6, 8, 0),
                    'end_datetime' => $weekEnd,
                    'days_of_week' => json_encode([CarbonImmutable::TUESDAY, CarbonImmutable::THURSDAY]),
                ],
            ],
            [
                'source_id' => 'pres-demo-dorm-groceries',
                'source_type' => TaskSourceType::Manual,
                'title' => 'Grocery run for dorm snacks and breakfast',
                'description' => 'Restock oatmeal, bread, and coffee; keep receipt for shared expense tracking.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Medium,
                'complexity' => TaskComplexity::Simple,
                'duration' => 75,
                'start_datetime' => $may(8, 9, 0),
                'end_datetime' => $may(8, 11, 0),
                'project_id' => $p('dorm'),
                'event_id' => null,
                'school_class_id' => null,
                'tags' => ['chores'],
                'recurring' => [
                    'recurrence_type' => TaskRecurrenceType::Weekly,
                    'interval' => 1,
                    'start_datetime' => $may(8, 9, 0),
                    'end_datetime' => $weekEnd,
                    'days_of_week' => json_encode([CarbonImmutable::FRIDAY]),
                ],
            ],
            [
                'source_id' => 'pres-demo-dorm-desk-reset',
                'source_type' => TaskSourceType::Manual,
                'title' => 'Reset desk and cable management',
                'description' => 'Clear clutter, label chargers, and make space for handwritten scratch work during exams.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Low,
                'complexity' => TaskComplexity::Simple,
                'duration' => 45,
                'start_datetime' => $may(9, 10, 0),
                'end_datetime' => $may(9, 11, 30),
                'project_id' => $p('dorm'),
                'event_id' => $e('study_blackout'),
                'school_class_id' => null,
                'tags' => ['chores'],
            ],
            [
                'source_id' => 'pres-demo-finals-mock-exam',
                'source_type' => TaskSourceType::Manual,
                'title' => 'Timed mock exam (mixed topics)',
                'description' => 'Simulate exam conditions: no notes for first pass, then review mistakes with solutions.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::High,
                'complexity' => TaskComplexity::Complex,
                'duration' => 120,
                'start_datetime' => $may(10, 9, 0),
                'end_datetime' => $may(10, 13, 0),
                'project_id' => $p('finals'),
                'event_id' => null,
                'school_class_id' => null,
                'tags' => ['exam-prep', 'school'],
            ],
            [
                'source_id' => 'pres-demo-finals-cheatsheet',
                'source_type' => TaskSourceType::Manual,
                'title' => 'Build one-page formula cheat sheet',
                'description' => 'Only what you actually use in problem sets; test each formula with one worked example.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Medium,
                'complexity' => TaskComplexity::Moderate,
                'duration' => 90,
                'start_datetime' => $may(11, 19, 0),
                'end_datetime' => $may(14, 21, 0),
                'project_id' => $p('finals'),
                'event_id' => $e('midterm_review'),
                'school_class_id' => null,
                'tags' => ['exam-prep'],
            ],
            [
                'source_id' => 'pres-demo-overdue-reading',
                'source_type' => TaskSourceType::Manual,
                'title' => 'Catch up on discrete reading (short sections)',
                'description' => 'Finish the two sections you skipped; prioritize examples over proofs for time efficiency.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Medium,
                'complexity' => TaskComplexity::Moderate,
                'duration' => 90,
                'start_datetime' => $may(2, 16, 0),
                'end_datetime' => $may(3, 23, 0),
                'project_id' => null,
                'event_id' => null,
                'school_class_id' => $c('discrete'),
                'teacher_name' => null,
                'subject_name' => null,
                'tags' => ['school', 'exam-prep'],
            ],
            [
                'source_id' => 'pres-demo-overdue-quiz',
                'source_type' => TaskSourceType::Manual,
                'title' => 'Online quiz attempt (practice mode)',
                'description' => 'Two attempts max; screenshot score for study log. Focus on missed conceptual questions.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::High,
                'complexity' => TaskComplexity::Simple,
                'duration' => 45,
                'start_datetime' => $may(4, 10, 0),
                'end_datetime' => $may(4, 23, 30),
                'project_id' => null,
                'event_id' => null,
                'school_class_id' => $c('dsa'),
                'teacher_name' => null,
                'subject_name' => null,
                'tags' => ['school', 'exam-prep'],
            ],
            [
                'source_id' => 'pres-demo-no-date-resume',
                'source_type' => TaskSourceType::Manual,
                'title' => 'Refresh résumé projects section',
                'description' => 'Add capstone and org fair leadership bullets when you have final wording; no hard deadline yet.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Low,
                'complexity' => TaskComplexity::Moderate,
                'duration' => 60,
                'start_datetime' => null,
                'end_datetime' => null,
                'project_id' => $p('finals'),
                'event_id' => null,
                'school_class_id' => null,
                'tags' => ['school'],
            ],
            [
                'source_id' => 'pres-demo-no-date-portfolio',
                'source_type' => TaskSourceType::Manual,
                'title' => 'Sketch portfolio site structure',
                'description' => 'Low priority backlog: decide pages and pick three flagship projects to feature first.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Low,
                'complexity' => TaskComplexity::Simple,
                'duration' => 45,
                'start_datetime' => null,
                'end_datetime' => null,
                'project_id' => $p('capstone'),
                'event_id' => null,
                'school_class_id' => null,
                'tags' => ['capstone'],
            ],
            [
                'source_id' => 'pres-demo-no-date-networking',
                'source_type' => TaskSourceType::Manual,
                'title' => 'List alumni contacts to message after finals',
                'description' => 'Keep names and programs in a note; send messages only once grades are stable.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Low,
                'complexity' => TaskComplexity::Simple,
                'duration' => 30,
                'start_datetime' => null,
                'end_datetime' => null,
                'project_id' => null,
                'event_id' => null,
                'school_class_id' => null,
                'tags' => ['school'],
            ],
            [
                'source_id' => 'pres-demo-dsa-lab-prep',
                'source_type' => TaskSourceType::Manual,
                'title' => 'Prep notebook for DSA lab (structures cheat sheet)',
                'description' => 'Write down ADT operations and common pitfalls so you can implement faster during supervised lab.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::High,
                'complexity' => TaskComplexity::Moderate,
                'duration' => 60,
                'start_datetime' => $may(5, 7, 0),
                'end_datetime' => $may(5, 11, 30),
                'project_id' => null,
                'event_id' => $e('morning_block'),
                'school_class_id' => $c('dsa'),
                'teacher_name' => null,
                'subject_name' => null,
                'tags' => ['school', 'exam-prep'],
            ],
            [
                'source_id' => 'pres-demo-dbms-lab-report',
                'source_type' => TaskSourceType::Manual,
                'title' => 'Draft DBMS lab report sections (methods + results)',
                'description' => 'Capture query plans and explain indexing choices; leave intro for last.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::High,
                'complexity' => TaskComplexity::Complex,
                'duration' => 150,
                'start_datetime' => $may(5, 15, 30),
                'end_datetime' => $may(8, 22, 0),
                'project_id' => $p('systems'),
                'event_id' => null,
                'school_class_id' => $c('dbms'),
                'teacher_name' => null,
                'subject_name' => null,
                'tags' => ['school'],
            ],
            [
                'source_id' => 'pres-demo-websys-api-hardening',
                'source_type' => TaskSourceType::Manual,
                'title' => 'Harden Web Systems API validation and errors',
                'description' => 'Align status codes with spec, add consistent error payload, and log unexpected exceptions.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Urgent,
                'complexity' => TaskComplexity::Complex,
                'duration' => 180,
                'start_datetime' => $may(6, 13, 0),
                'end_datetime' => $may(9, 17, 0),
                'project_id' => $p('capstone'),
                'event_id' => $e('consultation'),
                'school_class_id' => $c('websys'),
                'teacher_name' => null,
                'subject_name' => null,
                'tags' => ['school', 'capstone'],
            ],
            [
                'source_id' => 'pres-demo-hci-usability-run',
                'source_type' => TaskSourceType::Manual,
                'title' => 'Run HCI usability sessions with classmates',
                'description' => 'Three short sessions, consent script ready, take notes on task failures and quotes.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Medium,
                'complexity' => TaskComplexity::Moderate,
                'duration' => 120,
                'start_datetime' => $may(8, 16, 0),
                'end_datetime' => $may(12, 19, 0),
                'project_id' => $p('systems'),
                'event_id' => null,
                'school_class_id' => $c('hci'),
                'teacher_name' => null,
                'subject_name' => null,
                'tags' => ['school'],
            ],
            [
                'source_id' => 'pres-demo-peer-review-code',
                'source_type' => TaskSourceType::Manual,
                'title' => 'Peer review teammate PR (routing + tests)',
                'description' => 'Leave actionable comments; run branch locally and note edge cases around auth middleware.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Medium,
                'complexity' => TaskComplexity::Moderate,
                'duration' => 90,
                'start_datetime' => $may(7, 21, 0),
                'end_datetime' => $may(9, 23, 0),
                'project_id' => $p('capstone'),
                'event_id' => null,
                'school_class_id' => null,
                'tags' => ['school', 'capstone'],
            ],
            [
                'source_id' => 'pres-demo-morning-journal',
                'source_type' => TaskSourceType::Manual,
                'title' => 'Five-minute morning journal before classes',
                'description' => 'One win, one worry, one priority for the day. Keeps overwhelm visible but bounded.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Low,
                'complexity' => TaskComplexity::Simple,
                'duration' => 10,
                'start_datetime' => $may(5, 6, 15),
                'end_datetime' => $may(5, 22, 0),
                'project_id' => $p('dorm'),
                'event_id' => null,
                'school_class_id' => null,
                'tags' => ['chores'],
                'recurring' => [
                    'recurrence_type' => TaskRecurrenceType::Daily,
                    'interval' => 1,
                    'start_datetime' => $may(5, 6, 15),
                    'end_datetime' => $weekEnd,
                    'days_of_week' => null,
                ],
            ],
            [
                'source_id' => 'pres-demo-bs-dsa-problem-set',
                'source_type' => TaskSourceType::Brightspace,
                'title' => 'Brightspace: DSA problem set (trees and heaps)',
                'description' => 'Submit PDF write-up and zipped source. Double-check filename rules before uploading.',
                'teacher_name' => 'Prof. Demo Santos',
                'subject_name' => 'Data Structures and Algorithms',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::High,
                'complexity' => TaskComplexity::Complex,
                'duration' => 200,
                'start_datetime' => $may(5, 12, 0),
                'end_datetime' => $may(8, 23, 59),
                'project_id' => null,
                'event_id' => null,
                'school_class_id' => $c('dsa'),
                'source_url' => self::BRIGHTSPACE_LINK,
                'tags' => ['brightspace', 'school', 'exam-prep'],
            ],
            [
                'source_id' => 'pres-demo-bs-dbms-lab-submit',
                'source_type' => TaskSourceType::Brightspace,
                'title' => 'Brightspace: DBMS indexing lab submission',
                'description' => 'Attach before/after explain analyze screenshots and a short interpretation paragraph.',
                'teacher_name' => 'Prof. Demo Ramirez',
                'subject_name' => 'Database Systems Laboratory',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::High,
                'complexity' => TaskComplexity::Moderate,
                'duration' => 140,
                'start_datetime' => $may(5, 8, 0),
                'end_datetime' => $may(10, 23, 59),
                'project_id' => $p('systems'),
                'event_id' => null,
                'school_class_id' => $c('dbms'),
                'source_url' => self::BRIGHTSPACE_LINK,
                'tags' => ['brightspace', 'school'],
            ],
            [
                'source_id' => 'pres-demo-bs-websys-sprint',
                'source_type' => TaskSourceType::Brightspace,
                'title' => 'Brightspace: Web Systems sprint reflection',
                'description' => 'What shipped, what blocked, and one measurable improvement for next sprint.',
                'teacher_name' => 'Engr. Demo Fernandez',
                'subject_name' => 'Web Systems and Technologies',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Medium,
                'complexity' => TaskComplexity::Simple,
                'duration' => 60,
                'start_datetime' => $may(6, 8, 0),
                'end_datetime' => $may(11, 18, 0),
                'project_id' => null,
                'event_id' => null,
                'school_class_id' => $c('websys'),
                'source_url' => self::BRIGHTSPACE_LINK,
                'tags' => ['brightspace', 'school'],
            ],
            [
                'source_id' => 'pres-demo-bs-hci-deliverable',
                'source_type' => TaskSourceType::Brightspace,
                'title' => 'Brightspace: HCI usability findings memo',
                'description' => 'Summarize top five issues with severity tags and one recommended fix each.',
                'teacher_name' => 'Prof. Demo Mendoza',
                'subject_name' => 'Human-Computer Interaction Studio',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Medium,
                'complexity' => TaskComplexity::Moderate,
                'duration' => 90,
                'start_datetime' => $may(7, 9, 0),
                'end_datetime' => $may(13, 17, 0),
                'project_id' => $p('systems'),
                'event_id' => null,
                'school_class_id' => $c('hci'),
                'source_url' => self::BRIGHTSPACE_LINK,
                'tags' => ['brightspace', 'school'],
            ],
            [
                'source_id' => 'pres-demo-today-budget',
                'source_type' => TaskSourceType::Manual,
                'title' => 'Log weekly spending and split dorm bills',
                'description' => 'Update spreadsheet, reconcile GCash/BPI transfers, and message roommates if anything is off.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Medium,
                'complexity' => TaskComplexity::Simple,
                'duration' => 40,
                'start_datetime' => $may(5, 19, 30),
                'end_datetime' => $may(5, 21, 0),
                'project_id' => $p('dorm'),
                'event_id' => null,
                'school_class_id' => null,
                'tags' => ['chores'],
            ],
            [
                'source_id' => 'pres-demo-today-email-adviser',
                'source_type' => TaskSourceType::Manual,
                'title' => 'Email capstone adviser with demo agenda',
                'description' => 'Attach draft slide outline and list three questions about evaluation rubric interpretation.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::High,
                'complexity' => TaskComplexity::Simple,
                'duration' => 25,
                'start_datetime' => $may(5, 17, 45),
                'end_datetime' => $may(5, 18, 30),
                'project_id' => $p('capstone'),
                'event_id' => null,
                'school_class_id' => null,
                'tags' => ['capstone', 'school'],
            ],
            [
                'source_id' => 'pres-demo-chores-meal-prep',
                'source_type' => TaskSourceType::Manual,
                'title' => 'Meal prep for two long lab days',
                'description' => 'Cook simple carb + protein boxes so you are not buying expensive campus meals twice in a row.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Medium,
                'complexity' => TaskComplexity::Simple,
                'duration' => 90,
                'start_datetime' => $may(4, 17, 0),
                'end_datetime' => $may(5, 21, 0),
                'project_id' => $p('dorm'),
                'event_id' => null,
                'school_class_id' => null,
                'tags' => ['chores'],
            ],
            [
                'source_id' => 'pres-demo-chores-clean-mini-fridge',
                'source_type' => TaskSourceType::Manual,
                'title' => 'Clean mini-fridge and toss expired items',
                'description' => 'Quick wipe-down; prevents smell during hot weeks and makes space for meal prep.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Low,
                'complexity' => TaskComplexity::Simple,
                'duration' => 35,
                'start_datetime' => $may(12, 16, 0),
                'end_datetime' => $may(12, 17, 0),
                'project_id' => $p('dorm'),
                'event_id' => null,
                'school_class_id' => null,
                'tags' => ['chores'],
            ],
            [
                'source_id' => 'pres-demo-exam-drill-discrete',
                'source_type' => TaskSourceType::Manual,
                'title' => 'Discrete structures drill set (graphs)',
                'description' => 'Focus on connectivity, spanning trees, and one proof template you can reuse on exams.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::High,
                'complexity' => TaskComplexity::Complex,
                'duration' => 120,
                'start_datetime' => $may(10, 14, 0),
                'end_datetime' => $may(15, 21, 0),
                'project_id' => $p('finals'),
                'event_id' => null,
                'school_class_id' => $c('discrete'),
                'teacher_name' => null,
                'subject_name' => null,
                'tags' => ['exam-prep', 'school'],
            ],
            [
                'source_id' => 'pres-demo-websys-readme',
                'source_type' => TaskSourceType::Manual,
                'title' => 'Write deployment README for team project',
                'description' => 'Environment variables, migration order, and smoke-test checklist for whoever deploys next.',
                'status' => TaskStatus::ToDo,
                'priority' => TaskPriority::Medium,
                'complexity' => TaskComplexity::Moderate,
                'duration' => 55,
                'start_datetime' => $may(11, 20, 0),
                'end_datetime' => $may(14, 22, 0),
                'project_id' => $p('capstone'),
                'event_id' => null,
                'school_class_id' => $c('websys'),
                'teacher_name' => null,
                'subject_name' => null,
                'tags' => ['school'],
            ],
        ];
    }
}
