<?php

namespace Database\Seeders;

use App\Enums\ActivityLogAction;
use App\Enums\CollaborationPermission;
use App\Enums\EventRecurrenceType;
use App\Enums\EventStatus;
use App\Enums\FocusModeType;
use App\Enums\FocusSessionType;
use App\Enums\LlmToolCallStatus;
use App\Enums\MessageRole;
use App\Enums\ReminderStatus;
use App\Enums\ReminderType;
use App\Enums\TaskComplexity;
use App\Enums\TaskPriority;
use App\Enums\TaskRecurrenceType;
use App\Enums\TaskSourceType;
use App\Enums\TaskStatus;
use App\Models\ActivityLog;
use App\Models\CalendarFeed;
use App\Models\Collaboration;
use App\Models\CollaborationInvitation;
use App\Models\Comment;
use App\Models\Event;
use App\Models\EventException;
use App\Models\EventInstance;
use App\Models\FocusSession;
use App\Models\LlmToolCall;
use App\Models\Project;
use App\Models\RecurringEvent;
use App\Models\RecurringTask;
use App\Models\Reminder;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskAssistantMessage;
use App\Models\TaskAssistantThread;
use App\Models\TaskException;
use App\Models\TaskInstance;
use App\Models\User;
use App\Notifications\AssistantActionRequiredNotification;
use App\Notifications\AssistantToolCallFailedNotification;
use App\Notifications\CalendarFeedRecoveredNotification;
use App\Notifications\CalendarFeedStaleSyncNotification;
use App\Notifications\CalendarFeedSyncFailedNotification;
use App\Notifications\CollaborationInvitationReceivedNotification;
use App\Notifications\CollaborationInvitationRespondedForOwnerNotification;
use App\Notifications\CollaborationInviteExpiringNotification;
use App\Notifications\CollaboratorActivityOnItemNotification;
use App\Notifications\DailyDueSummaryNotification;
use App\Notifications\EventStartSoonNotification;
use App\Notifications\FocusDriftWeeklyNotification;
use App\Notifications\FocusSessionCompletedNotification;
use App\Notifications\ProjectDeadlineRiskNotification;
use App\Notifications\RecurrenceAnomalyNotification;
use App\Notifications\TaskDueSoonNotification;
use App\Notifications\TaskOverdueNotification;
use App\Notifications\TaskStalledNotification;
use App\Services\Reminders\ReminderDispatcherService;
use App\Services\Reminders\ReminderSchedulerService;
use Database\Factories\ReminderFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class StudentLifeSampleSeeder extends Seeder
{
    private const TARGET_EMAIL = 'andrew.juan.cvt@eac.edu.ph';

    /**
     * Demo user task kept overdue on purpose (prioritize / overdue flows).
     */
    public const INTENTIONAL_OVERDUE_STRESS_TASK_TITLE = 'Impossible 5h study block before quiz';

    /**
     * Minimum whole-day offset from local “today” (start of day) for open tasks, chores, projects, and events.
     */
    public const MIN_OPEN_SCHEDULE_LEAD_DAYS = 3;

    /**
     * Spread added on top of {@see MIN_OPEN_SCHEDULE_LEAD_DAYS}: jitter is in [0, OPEN_SCHEDULE_LEAD_SPREAD_DAYS), so bases land on distinct days from +3 through +5.
     */
    private const OPEN_SCHEDULE_LEAD_SPREAD_DAYS = 3;

    public const BRIGHTSPACE_PLACEHOLDER_SOURCE_URL = 'https://eac.brightspace.com/d2l/lms/dropbox/user/folder_submit_files.d2l?db=220208&grpid=0&isprv=0&bp=0&ou=112348';

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
            'title' => self::INTENTIONAL_OVERDUE_STRESS_TASK_TITLE,
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
        $dueDateFloor = $now->copy()
            ->setTimezone($now->getTimezone())
            ->startOfDay()
            ->addDays(self::MIN_OPEN_SCHEDULE_LEAD_DAYS);

        $this->seedBrightspaceTasks($user, $now, $dueDateFloor);
        $this->seedRecurringChores($user, $now, $dueDateFloor);
        $this->seedStudentTasks($user, $now, $dueDateFloor);
        $this->seedStressTestTasks($user, $now, $dueDateFloor);
        $this->seedWorkspaceVisibilityAnchors($user, $now);
        $this->seedDashboardStabilityAnchors($user, $now);
        $projects = $this->seedProjects($user, $now, $dueDateFloor);
        $tags = $this->seedTags($user);
        $this->attachProjectsToTasks($user, $projects);
        $this->attachTagsToItems($user, $tags);
        $this->seedStudentEvents($user, $now);
        $this->seedRecurringEvents($user, $now);

        $context = $this->seedModuleFixtures($user, $now);
        $this->seedRemindersAndNotifications($user, $context, $now);
        $this->invalidateDashboardCacheForUser($user, $now);
    }

    /**
     * @return array<string, mixed>
     */
    private function seedModuleFixtures(User $user, Carbon $now): array
    {
        $referenceTask = Task::query()
            ->where('user_id', $user->id)
            ->where('title', 'Library research for history essay')
            ->first() ?? Task::query()->where('user_id', $user->id)->first();
        $referenceEvent = Event::query()
            ->where('user_id', $user->id)
            ->where('title', 'CS group project meetup')
            ->first() ?? Event::query()->where('user_id', $user->id)->first();
        $referenceProject = Project::query()->where('user_id', $user->id)->first();
        $recurringTask = RecurringTask::query()->whereHas('task', fn ($q) => $q->where('user_id', $user->id))->first();

        $collaborator = User::query()->firstOrCreate(
            ['email' => 'sample.collaborator@tasklyst.test'],
            [
                'name' => 'Sample Collaborator',
                'workos_id' => 'seed-workos-sample-collaborator',
                'avatar' => 'https://picsum.photos/seed/sample-collaborator/200/200',
            ]
        );

        if ($referenceTask !== null) {
            Collaboration::query()->firstOrCreate([
                'collaboratable_type' => $referenceTask->getMorphClass(),
                'collaboratable_id' => $referenceTask->id,
                'user_id' => $collaborator->id,
            ], [
                'permission' => CollaborationPermission::Edit,
            ]);
        }

        if ($referenceProject !== null) {
            Collaboration::query()->firstOrCreate([
                'collaboratable_type' => $referenceProject->getMorphClass(),
                'collaboratable_id' => $referenceProject->id,
                'user_id' => $collaborator->id,
            ], [
                'permission' => CollaborationPermission::View,
            ]);
        }

        $pendingInvitation = null;
        if ($referenceTask !== null) {
            $pendingInvitation = CollaborationInvitation::query()->firstOrCreate([
                'collaboratable_type' => $referenceTask->getMorphClass(),
                'collaboratable_id' => $referenceTask->id,
                'invitee_email' => 'pending.invitee@tasklyst.test',
                'status' => 'pending',
            ], [
                'inviter_id' => $user->id,
                'invitee_user_id' => null,
                'permission' => CollaborationPermission::Edit,
                'expires_at' => $now->copy()->addDays(2),
            ]);
        }

        if ($referenceTask !== null) {
            CollaborationInvitation::query()->firstOrCreate([
                'collaboratable_type' => $referenceTask->getMorphClass(),
                'collaboratable_id' => $referenceTask->id,
                'invitee_email' => 'accepted.invitee@tasklyst.test',
                'status' => 'accepted',
            ], [
                'inviter_id' => $user->id,
                'invitee_user_id' => null,
                'permission' => CollaborationPermission::View,
                'expires_at' => $now->copy()->subDay(),
            ]);
        }

        $healthyFeed = CalendarFeed::query()->firstOrCreate([
            'user_id' => $user->id,
            'feed_url' => 'https://feeds.tasklyst.test/brightspace-main.ics',
        ], [
            'name' => 'Brightspace Main Calendar',
            'source' => 'brightspace',
            'sync_enabled' => true,
            'last_synced_at' => $now->copy()->subMinutes(25),
        ]);
        $failedFeed = CalendarFeed::query()->firstOrCreate([
            'user_id' => $user->id,
            'feed_url' => 'https://feeds.tasklyst.test/brightspace-failed.ics',
        ], [
            'name' => 'Brightspace Sync Retry',
            'source' => 'brightspace',
            'sync_enabled' => true,
            'last_synced_at' => $now->copy()->subDays(3),
        ]);
        $staleFeed = CalendarFeed::query()->firstOrCreate([
            'user_id' => $user->id,
            'feed_url' => 'https://feeds.tasklyst.test/brightspace-stale.ics',
        ], [
            'name' => 'Brightspace Stale Feed',
            'source' => 'brightspace',
            'sync_enabled' => true,
            'last_synced_at' => $now->copy()->subHours(14),
        ]);

        if ($referenceTask !== null && $referenceTask->source_type === TaskSourceType::Brightspace) {
            $referenceTask->forceFill([
                'calendar_feed_id' => $healthyFeed->id,
            ])->save();
        }

        $completedFocusSession = null;
        if ($referenceTask !== null) {
            $completedFocusSession = FocusSession::query()->create([
                'user_id' => $user->id,
                'focusable_type' => $referenceTask->getMorphClass(),
                'focusable_id' => $referenceTask->id,
                'type' => FocusSessionType::Work,
                'focus_mode_type' => FocusModeType::Pomodoro,
                'sequence_number' => 1,
                'duration_seconds' => 1500,
                'completed' => true,
                'started_at' => $now->copy()->subHours(4),
                'ended_at' => $now->copy()->subHours(3)->subMinutes(35),
                'paused_seconds' => 180,
                'paused_at' => null,
                'payload' => ['source' => 'student-life-seeder'],
            ]);

            FocusSession::query()->create([
                'user_id' => $user->id,
                'focusable_type' => $referenceTask->getMorphClass(),
                'focusable_id' => $referenceTask->id,
                'type' => FocusSessionType::Work,
                'focus_mode_type' => FocusModeType::Sprint,
                'sequence_number' => 2,
                'duration_seconds' => 1200,
                'completed' => false,
                'started_at' => $now->copy()->subMinutes(45),
                'ended_at' => null,
                'paused_seconds' => 90,
                'paused_at' => $now->copy()->subMinutes(8),
                'payload' => ['source' => 'student-life-seeder'],
            ]);
        }

        $thread = TaskAssistantThread::query()->create([
            'user_id' => $user->id,
            'title' => 'Plan this week around exams and deadlines',
            'metadata' => ['seeded' => true],
        ]);
        $userMessage = TaskAssistantMessage::query()->create([
            'thread_id' => $thread->id,
            'role' => MessageRole::User,
            'content' => 'Help me reorder my next tasks by urgency and estimate effort.',
            'tool_calls' => null,
            'metadata' => ['seeded' => true],
        ]);
        TaskAssistantMessage::query()->create([
            'thread_id' => $thread->id,
            'role' => MessageRole::Assistant,
            'content' => 'I can propose a plan and explain what should move first.',
            'tool_calls' => [
                ['name' => 'prioritize_tasks', 'status' => 'failed'],
            ],
            'metadata' => ['seeded' => true],
        ]);

        $failedToolCall = LlmToolCall::query()->create([
            'thread_id' => $thread->id,
            'message_id' => $userMessage->id,
            'tool_name' => 'prioritize_tasks',
            'params_json' => ['window_days' => 7],
            'result_json' => ['error' => 'Timeout while evaluating dependencies'],
            'status' => LlmToolCallStatus::Failed,
            'operation_token' => 'seeded-op-'.(string) $now->getTimestamp(),
            'user_id' => $user->id,
        ]);

        if ($referenceTask !== null) {
            Comment::query()->create([
                'commentable_type' => $referenceTask->getMorphClass(),
                'commentable_id' => $referenceTask->id,
                'user_id' => $user->id,
                'content' => 'Need to finish references before drafting the final section.',
                'is_edited' => false,
                'edited_at' => null,
                'is_pinned' => true,
            ]);
        }

        if ($referenceEvent !== null) {
            Comment::query()->create([
                'commentable_type' => $referenceEvent->getMorphClass(),
                'commentable_id' => $referenceEvent->id,
                'user_id' => $collaborator->id,
                'content' => 'I can bring the updated mockups to this meetup.',
                'is_edited' => false,
                'edited_at' => null,
                'is_pinned' => false,
            ]);
        }

        if ($referenceTask !== null) {
            ActivityLog::query()->create([
                'loggable_type' => $referenceTask->getMorphClass(),
                'loggable_id' => $referenceTask->id,
                'user_id' => $user->id,
                'action' => ActivityLogAction::FieldUpdated,
                'payload' => [
                    'field' => 'priority',
                    'from' => TaskPriority::Medium->value,
                    'to' => TaskPriority::High->value,
                ],
            ]);
        }

        if ($referenceProject !== null) {
            ActivityLog::query()->create([
                'loggable_type' => $referenceProject->getMorphClass(),
                'loggable_id' => $referenceProject->id,
                'user_id' => $user->id,
                'action' => ActivityLogAction::CollaboratorInvited,
                'payload' => [
                    'invitee_email' => 'pending.invitee@tasklyst.test',
                    'permission' => CollaborationPermission::Edit->value,
                ],
            ]);
        }

        if ($recurringTask !== null) {
            TaskException::query()->firstOrCreate([
                'recurring_task_id' => $recurringTask->id,
                'exception_date' => $now->copy()->addDays(4)->startOfDay(),
            ], [
                'is_deleted' => true,
                'replacement_instance_id' => null,
                'reason' => 'Class suspension for local holiday',
                'created_by' => $user->id,
            ]);
        }

        return [
            'task' => $referenceTask,
            'event' => $referenceEvent,
            'project' => $referenceProject,
            'pending_invitation' => $pendingInvitation,
            'healthy_feed' => $healthyFeed,
            'failed_feed' => $failedFeed,
            'stale_feed' => $staleFeed,
            'completed_focus_session' => $completedFocusSession,
            'assistant_thread' => $thread,
            'failed_tool_call' => $failedToolCall,
            'recurring_task' => $recurringTask,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function seedRemindersAndNotifications(User $user, array $context, Carbon $now): void
    {
        $scheduler = app(ReminderSchedulerService::class);
        $dispatcher = app(ReminderDispatcherService::class);

        Task::query()
            ->where('user_id', $user->id)
            ->whereNull('completed_at')
            ->each(static function (Task $task) use ($scheduler): void {
                $scheduler->syncTaskReminders($task);
            });

        Event::query()
            ->where('user_id', $user->id)
            ->where('status', EventStatus::Scheduled)
            ->each(static function (Event $event) use ($scheduler): void {
                $scheduler->syncEventReminders($event);
            });

        $limit = (int) config('reminders.dispatch.default_limit', 200);
        $dispatcher->dispatchDue(max(1, $limit));

        $completedLabTask = Task::query()
            ->where('user_id', $user->id)
            ->where('title', 'ITCS 101 – Lab 3: Loops')
            ->first();

        if ($completedLabTask !== null) {
            ReminderFactory::new()
                ->forUserTask($user, $completedLabTask, ReminderType::TaskDueSoon)
                ->sent()
                ->create();
        }

        $this->seedComprehensiveReminders($user, $context, $now);
        $this->seedComprehensiveNotifications($user, $context, $now);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function seedComprehensiveReminders(User $user, array $context, Carbon $now): void
    {
        $task = $context['task'] ?? Task::query()->where('user_id', $user->id)->first();
        $event = $context['event'] ?? Event::query()->where('user_id', $user->id)->first();
        $project = $context['project'] ?? Project::query()->where('user_id', $user->id)->first();
        $invitation = $context['pending_invitation'] ?? CollaborationInvitation::query()->where('inviter_id', $user->id)->first();
        $healthyFeed = $context['healthy_feed'] ?? CalendarFeed::query()->where('user_id', $user->id)->first();
        $failedFeed = $context['failed_feed'] ?? $healthyFeed;
        $staleFeed = $context['stale_feed'] ?? $healthyFeed;
        $focusSession = $context['completed_focus_session'] ?? FocusSession::query()->where('user_id', $user->id)->first();
        $assistantThread = $context['assistant_thread'] ?? TaskAssistantThread::query()->where('user_id', $user->id)->first();
        $failedToolCall = $context['failed_tool_call'] ?? LlmToolCall::query()->where('user_id', $user->id)->first();
        $recurringTask = $context['recurring_task'] ?? RecurringTask::query()->whereHas('task', fn ($q) => $q->where('user_id', $user->id))->first();

        if (! $task instanceof Task || ! $event instanceof Event || ! $project instanceof Project || ! $invitation instanceof CollaborationInvitation || ! $healthyFeed instanceof CalendarFeed || ! $failedFeed instanceof CalendarFeed || ! $staleFeed instanceof CalendarFeed || ! $focusSession instanceof FocusSession || ! $assistantThread instanceof TaskAssistantThread || ! $failedToolCall instanceof LlmToolCall || ! $recurringTask instanceof RecurringTask) {
            return;
        }

        $remindableByType = [
            ReminderType::TaskDueSoon->value => $task,
            ReminderType::TaskOverdue->value => $task,
            ReminderType::EventStartSoon->value => $event,
            ReminderType::CollaborationInviteReceived->value => $invitation,
            ReminderType::DailyDueSummary->value => $project,
            ReminderType::TaskStalled->value => $task,
            ReminderType::ProjectDeadlineRisk->value => $project,
            ReminderType::RecurrenceAnomaly->value => $recurringTask,
            ReminderType::CollaborationInviteExpiring->value => $invitation,
            ReminderType::CalendarFeedSyncFailed->value => $failedFeed,
            ReminderType::CalendarFeedRecovered->value => $healthyFeed,
            ReminderType::CalendarFeedStaleSync->value => $staleFeed,
            ReminderType::FocusSessionCompleted->value => $focusSession,
            ReminderType::FocusDriftWeekly->value => $project,
            ReminderType::AssistantActionRequired->value => $assistantThread,
            ReminderType::AssistantToolCallFailed->value => $failedToolCall,
        ];

        foreach (ReminderType::cases() as $index => $type) {
            $remindable = $remindableByType[$type->value] ?? $task;
            $scheduledAt = $now->copy()->subHours(36)->addMinutes($index * 23);
            $status = match ($index % 3) {
                0 => ReminderStatus::Pending,
                1 => ReminderStatus::Sent,
                default => ReminderStatus::Cancelled,
            };

            $snoozedUntil = null;
            if ($type === ReminderType::FocusDriftWeekly) {
                $status = ReminderStatus::Pending;
                $snoozedUntil = $now->copy()->addHours(4);
            }

            if ($type === ReminderType::AssistantActionRequired) {
                $status = ReminderStatus::Pending;
                $scheduledAt = $now->copy()->addMinutes(45);
            }

            $sentAt = $status === ReminderStatus::Sent ? $scheduledAt->copy()->addMinutes(5) : null;
            $cancelledAt = $status === ReminderStatus::Cancelled ? $scheduledAt->copy()->addMinutes(8) : null;

            Reminder::query()->updateOrCreate([
                'user_id' => $user->id,
                'remindable_type' => $remindable->getMorphClass(),
                'remindable_id' => $remindable->getKey(),
                'type' => $type,
                'scheduled_at' => $scheduledAt,
                'status' => $status,
            ], [
                'sent_at' => $sentAt,
                'cancelled_at' => $cancelledAt,
                'snoozed_until' => $snoozedUntil,
                'payload' => $this->reminderPayloadForType(
                    type: $type,
                    task: $task,
                    event: $event,
                    project: $project,
                    invitation: $invitation,
                    feed: $remindable instanceof CalendarFeed ? $remindable : $healthyFeed,
                    focusSession: $focusSession,
                    assistantThread: $assistantThread,
                    toolCall: $failedToolCall,
                    recurringTask: $recurringTask,
                    now: $now
                ),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function seedComprehensiveNotifications(User $user, array $context, Carbon $now): void
    {
        $task = $context['task'] ?? Task::query()->where('user_id', $user->id)->first();
        $event = $context['event'] ?? Event::query()->where('user_id', $user->id)->first();
        $project = $context['project'] ?? Project::query()->where('user_id', $user->id)->first();
        $invitation = $context['pending_invitation'] ?? CollaborationInvitation::query()->where('inviter_id', $user->id)->first();
        $healthyFeed = $context['healthy_feed'] ?? CalendarFeed::query()->where('user_id', $user->id)->first();
        $failedFeed = $context['failed_feed'] ?? $healthyFeed;
        $staleFeed = $context['stale_feed'] ?? $healthyFeed;
        $focusSession = $context['completed_focus_session'] ?? FocusSession::query()->where('user_id', $user->id)->first();
        $assistantThread = $context['assistant_thread'] ?? TaskAssistantThread::query()->where('user_id', $user->id)->first();
        $failedToolCall = $context['failed_tool_call'] ?? LlmToolCall::query()->where('user_id', $user->id)->first();
        $recurringTask = $context['recurring_task'] ?? RecurringTask::query()->whereHas('task', fn ($q) => $q->where('user_id', $user->id))->first();

        if (! $task instanceof Task || ! $event instanceof Event || ! $project instanceof Project || ! $invitation instanceof CollaborationInvitation || ! $healthyFeed instanceof CalendarFeed || ! $failedFeed instanceof CalendarFeed || ! $staleFeed instanceof CalendarFeed || ! $focusSession instanceof FocusSession || ! $assistantThread instanceof TaskAssistantThread || ! $failedToolCall instanceof LlmToolCall || ! $recurringTask instanceof RecurringTask) {
            return;
        }

        $user->notify(new TaskDueSoonNotification(
            taskId: (int) $task->id,
            taskTitle: (string) $task->title,
            dueAtIso: $task->end_datetime?->toIso8601String(),
            offsetMinutes: 60,
        ));
        $user->notify(new TaskOverdueNotification(
            taskId: (int) $task->id,
            taskTitle: (string) $task->title,
            dueAtIso: $task->end_datetime?->toIso8601String(),
        ));
        $user->notify(new EventStartSoonNotification(
            eventId: (int) $event->id,
            eventTitle: (string) $event->title,
            startAtIso: $event->start_datetime?->toIso8601String(),
            offsetMinutes: 30,
        ));
        $user->notify(new CollaborationInvitationReceivedNotification(
            invitationId: (int) $invitation->id,
            inviteeEmail: (string) $invitation->invitee_email,
            collaboratableType: (string) $invitation->collaboratable_type,
            collaboratableId: (int) $invitation->collaboratable_id,
            permission: $invitation->permission?->value,
        ));
        $user->notify(new DailyDueSummaryNotification(
            date: $now->toDateString(),
            tasksDueTodayCount: 4,
            eventsTodayCount: 2,
            overdueTasksCount: 1,
        ));
        $user->notify(new TaskStalledNotification(
            taskId: (int) $task->id,
            taskTitle: (string) $task->title,
            hoursStalled: 18,
        ));
        $user->notify(new ProjectDeadlineRiskNotification(
            projectId: (int) $project->id,
            projectName: (string) $project->name,
            projectEndAt: $project->end_datetime?->toIso8601String(),
            openTasksCount: 6,
        ));
        $user->notify(new RecurrenceAnomalyNotification(
            recurringKind: 'task',
            entityId: (int) $task->id,
            entityTitle: (string) $task->title,
            exceptionsCount: 2,
            windowDays: 14,
        ));
        $user->notify(new CollaborationInviteExpiringNotification(
            invitationId: (int) $invitation->id,
            inviteeEmail: (string) $invitation->invitee_email,
            expiresAtIso: $invitation->expires_at?->toIso8601String(),
        ));
        $user->notify(new CalendarFeedSyncFailedNotification(
            feedId: (int) $failedFeed->id,
            feedName: (string) ($failedFeed->name ?? 'Calendar Feed'),
            reason: 'HTTP 503 from feed provider',
        ));
        $user->notify(new CalendarFeedRecoveredNotification(
            feedId: (int) $healthyFeed->id,
            feedName: (string) ($healthyFeed->name ?? 'Calendar Feed'),
        ));
        $user->notify(new CalendarFeedStaleSyncNotification(
            feedId: (int) $staleFeed->id,
            feedName: (string) ($staleFeed->name ?? 'Calendar Feed'),
            lastSyncedAt: $staleFeed->last_synced_at?->toIso8601String(),
            staleHours: 6,
        ));
        $user->notify(new FocusSessionCompletedNotification(
            focusSessionId: (int) $focusSession->id,
            taskId: (int) $task->id,
            durationSeconds: 1500,
        ));
        $user->notify(new FocusDriftWeeklyNotification(
            weekStart: $now->copy()->startOfWeek()->toDateString(),
            weekEnd: $now->copy()->endOfWeek()->toDateString(),
            plannedSeconds: 18000,
            completedSeconds: 9300,
        ));
        $user->notify(new AssistantActionRequiredNotification(
            threadId: (int) $assistantThread->id,
            threadTitle: (string) ($assistantThread->title ?? 'Assistant Thread'),
            pendingProposalsCount: 2,
        ));
        $user->notify(new AssistantToolCallFailedNotification(
            toolCallId: (int) $failedToolCall->id,
            toolName: (string) $failedToolCall->tool_name,
            operationToken: $failedToolCall->operation_token,
            threadId: (int) $assistantThread->id,
            messageId: $failedToolCall->message_id,
            error: data_get($failedToolCall->result_json, 'error'),
        ));
        $user->notify(new CollaboratorActivityOnItemNotification(
            title: 'Collaborator updated shared task',
            message: 'A collaborator changed details on your shared item.',
            workspaceParams: ['itemType' => 'task', 'itemId' => $task->id],
            meta: ['source' => 'student-life-seeder'],
        ));
        $user->notify(new CollaborationInvitationRespondedForOwnerNotification(
            accepted: true,
            inviteeDisplay: 'Sample Collaborator',
            itemTitle: (string) $task->title,
            workspaceParams: ['itemType' => 'task', 'itemId' => $task->id],
            meta: ['invitation_id' => $invitation->id],
        ));

        $notifications = $user->notifications()->latest('created_at')->get()->values();
        $notifications->each(function ($notification, int $index) use ($now): void {
            $notification->forceFill([
                'created_at' => $now->copy()->subMinutes(($index + 1) * 11),
                'updated_at' => $now->copy()->subMinutes(($index + 1) * 11),
            ])->save();
        });
        $notifications
            ->filter(fn ($notification, int $index) => $index % 3 === 0)
            ->each(function ($notification) use ($now): void {
                $notification->forceFill([
                    'read_at' => $now->copy()->subMinutes(5),
                ])->save();
            });
    }

    private function reminderPayloadForType(
        ReminderType $type,
        Task $task,
        Event $event,
        Project $project,
        CollaborationInvitation $invitation,
        CalendarFeed $feed,
        FocusSession $focusSession,
        TaskAssistantThread $assistantThread,
        LlmToolCall $toolCall,
        RecurringTask $recurringTask,
        Carbon $now
    ): array {
        return match ($type) {
            ReminderType::TaskDueSoon => [
                'task_id' => $task->id,
                'task_title' => $task->title,
                'due_at' => $task->end_datetime?->toIso8601String(),
                'offset_minutes' => 60,
            ],
            ReminderType::TaskOverdue => [
                'task_id' => $task->id,
                'task_title' => $task->title,
                'due_at' => $task->end_datetime?->toIso8601String(),
            ],
            ReminderType::EventStartSoon => [
                'event_id' => $event->id,
                'event_title' => $event->title,
                'start_at' => $event->start_datetime?->toIso8601String(),
                'offset_minutes' => 15,
            ],
            ReminderType::CollaborationInviteReceived => [
                'invitation_id' => $invitation->id,
                'invitee_email' => $invitation->invitee_email,
                'collaboratable_type' => $invitation->collaboratable_type,
                'collaboratable_id' => $invitation->collaboratable_id,
            ],
            ReminderType::DailyDueSummary => [
                'date' => $now->toDateString(),
                'tasks_due_today_count' => 4,
                'events_today_count' => 2,
                'overdue_tasks_count' => 1,
            ],
            ReminderType::TaskStalled => [
                'task_id' => $task->id,
                'task_title' => $task->title,
                'hours_stalled' => 18,
            ],
            ReminderType::ProjectDeadlineRisk => [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'project_end_at' => $project->end_datetime?->toIso8601String(),
                'open_tasks_count' => 6,
            ],
            ReminderType::RecurrenceAnomaly => [
                'recurring_kind' => 'task',
                'recurring_task_id' => $recurringTask->id,
                'entity_id' => $task->id,
                'entity_title' => $task->title,
                'exceptions_count' => 2,
                'window_days' => 14,
            ],
            ReminderType::CollaborationInviteExpiring => [
                'invitation_id' => $invitation->id,
                'invitee_email' => $invitation->invitee_email,
                'expires_at' => $invitation->expires_at?->toIso8601String(),
            ],
            ReminderType::CalendarFeedSyncFailed => [
                'feed_id' => $feed->id,
                'feed_name' => $feed->name,
                'reason' => 'HTTP 503 from feed provider',
            ],
            ReminderType::CalendarFeedRecovered => [
                'feed_id' => $feed->id,
                'feed_name' => $feed->name,
            ],
            ReminderType::CalendarFeedStaleSync => [
                'feed_id' => $feed->id,
                'feed_name' => $feed->name,
                'last_synced_at' => $feed->last_synced_at?->toIso8601String(),
                'stale_hours' => 6,
            ],
            ReminderType::FocusSessionCompleted => [
                'focus_session_id' => $focusSession->id,
                'task_id' => $task->id,
                'duration_seconds' => $focusSession->duration_seconds,
            ],
            ReminderType::FocusDriftWeekly => [
                'week_start' => $now->copy()->startOfWeek()->toDateString(),
                'week_end' => $now->copy()->endOfWeek()->toDateString(),
                'planned_seconds' => 18000,
                'completed_seconds' => 9300,
            ],
            ReminderType::AssistantActionRequired => [
                'thread_id' => $assistantThread->id,
                'thread_title' => $assistantThread->title,
                'pending_proposals_count' => 2,
            ],
            ReminderType::AssistantToolCallFailed => [
                'tool_call_id' => $toolCall->id,
                'tool_name' => $toolCall->tool_name,
                'operation_token' => $toolCall->operation_token,
                'error' => data_get($toolCall->result_json, 'error'),
            ],
        };
    }

    private function seedBrightspaceTasks(User $user, Carbon $now, Carbon $dueDateFloor): void
    {
        foreach (self::BRIGHTSPACE_TASKS as $spec) {
            $base = $this->brightspaceScheduleBase($now, $spec);

            $start = null;
            if (isset($spec['start_days_from_now'], $spec['start_time'])) {
                [$startHour, $startMinute] = explode(':', $spec['start_time']);
                $start = $base->copy()
                    ->addDays((int) $spec['start_days_from_now'])
                    ->setTime((int) $startHour, (int) $startMinute);
            }

            $end = null;
            if (isset($spec['end_days_from_now'], $spec['end_time'])) {
                [$endHour, $endMinute] = explode(':', $spec['end_time']);
                $end = $base->copy()
                    ->addDays((int) $spec['end_days_from_now'])
                    ->setTime((int) $endHour, (int) $endMinute);
            }

            if ($start !== null && $end !== null && $end->lessThan($start) && empty($spec['completed'])) {
                $end = $start->copy()->addMinutes(max(30, (int) $spec['duration']));
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
                'source_url' => self::BRIGHTSPACE_PLACEHOLDER_SOURCE_URL,
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
            $dayBase = $index < 2
                ? $now->copy()->startOfDay()->addDays($index)
                : $this->openScheduleBase($now, (string) $spec['title']);
            $start = $dayBase->copy()->setTime(20, 0)->addMinutes($index * 5);
            $end = $start->copy()->addMinutes((int) $spec['duration']);

            if ($index >= 2) {
                $end = $this->clampTaskDueDate($end, null, $dueDateFloor, (string) $spec['title']);
            }

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

            for ($i = -1; $i < 3; $i++) {
                $instanceDate = $dayBase->copy()->addDays($i)->startOfDay();
                $instanceStatus = TaskStatus::ToDo;
                $completedAt = null;

                if ($index === 0 && $i === 0) {
                    $instanceStatus = TaskStatus::Doing;
                } elseif ($i === 0) {
                    $instanceStatus = TaskStatus::Done;
                    $completedAt = $instanceDate->copy()->setTime(21, 30);
                }

                TaskInstance::create([
                    'recurring_task_id' => $recurring->id,
                    'task_id' => $task->id,
                    'instance_date' => $instanceDate,
                    'status' => $instanceStatus,
                    'completed_at' => $completedAt,
                ]);
            }
        }
    }

    private function seedStudentTasks(User $user, Carbon $now, Carbon $dueDateFloor): void
    {
        foreach (self::STUDENT_TASKS as $spec) {
            $base = $this->openScheduleBase($now, (string) $spec['title']);
            $start = $base->copy()
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

    private function seedWorkspaceVisibilityAnchors(User $user, Carbon $now): void
    {
        Task::query()->create([
            'user_id' => $user->id,
            'title' => 'Workspace visibility anchor: active doing task',
            'description' => 'Keeps workspace Doing filter populated for today after seeding.',
            'teacher_name' => null,
            'subject_name' => null,
            'status' => TaskStatus::Doing,
            'priority' => TaskPriority::Medium,
            'complexity' => TaskComplexity::Simple,
            'source_type' => TaskSourceType::Manual,
            'source_id' => null,
            'source_url' => null,
            'duration' => 90,
            'start_datetime' => $now->copy()->subHours(2),
            'end_datetime' => $now->copy()->addHours(6),
            'project_id' => null,
            'event_id' => null,
            'calendar_feed_id' => null,
            'completed_at' => null,
        ]);
    }

    private function seedDashboardStabilityAnchors(User $user, Carbon $now): void
    {
        $today = $now->copy()->startOfDay();

        Task::query()->create([
            'user_id' => $user->id,
            'title' => 'Dashboard anchor: due today task',
            'description' => 'Keeps Due Today and Urgent cards populated after seeding.',
            'teacher_name' => null,
            'subject_name' => null,
            'status' => TaskStatus::ToDo,
            'priority' => TaskPriority::High,
            'complexity' => TaskComplexity::Moderate,
            'source_type' => TaskSourceType::Manual,
            'source_id' => null,
            'source_url' => null,
            'duration' => 75,
            'start_datetime' => $today->copy()->setTime(13, 0),
            'end_datetime' => $today->copy()->setTime(17, 0),
            'project_id' => null,
            'event_id' => null,
            'calendar_feed_id' => null,
            'completed_at' => null,
        ]);

        Event::query()->create([
            'user_id' => $user->id,
            'title' => 'Dashboard anchor: today event',
            'description' => 'Keeps Today Events card populated for selected date.',
            'start_datetime' => $today->copy()->setTime(15, 30),
            'end_datetime' => $today->copy()->setTime(16, 30),
            'all_day' => false,
            'status' => EventStatus::Scheduled,
        ]);
    }

    private function invalidateDashboardCacheForUser(User $user, Carbon $now): void
    {
        $userId = (int) $user->id;
        $selectedDate = $now->toDateString();

        foreach (['daily', 'weekly', 'monthly'] as $preset) {
            Cache::forget(sprintf('dashboard:trend-analytics:%d:%s:%s', $userId, $preset, $now->format('YmdHi')));
            Cache::forget(sprintf('dashboard:trend-analytics:%d:%s:%s', $userId, $preset, $now->copy()->subMinute()->format('YmdHi')));
        }

        Cache::forget(sprintf('dashboard:urgent-now:%d', $userId));
        Cache::forget(sprintf('dashboard:metric:project-health:%d:%s', $userId, $selectedDate));
        Cache::forget(sprintf('dashboard:metric:focus-throughput:%d:%s', $userId, $selectedDate));
    }

    private function seedStressTestTasks(User $user, Carbon $now, Carbon $dueDateFloor): void
    {
        foreach (self::STRESS_TEST_TASKS as $spec) {
            if (isset($spec['start_offset_minutes'], $spec['end_offset_minutes'])) {
                $start = $now->copy()->addMinutes((int) $spec['start_offset_minutes']);
                $end = $now->copy()->addMinutes((int) $spec['end_offset_minutes']);
            } else {
                $base = $this->openScheduleBase($now, (string) $spec['title']);
                $start = $base->copy()
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
            $base = $this->openScheduleBase($now, (string) $spec['name']);
            $start = $base->copy()->addDays($index)->setTime(9, 0);
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

            $base = $this->openScheduleBase($now, (string) $spec['title']);

            $start = $base->copy()
                ->addDays((int) $spec['days_from_now'])
                ->setTime((int) $startHour, (int) $startMinute);
            $end = $base->copy()
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

        $recurringEvent = RecurringEvent::create([
            'event_id' => $event->id,
            'recurrence_type' => EventRecurrenceType::Weekly,
            'interval' => 1,
            'days_of_week' => json_encode([4]), // Friday
            'start_datetime' => $event->start_datetime,
            'end_datetime' => $event->start_datetime?->copy()->addMonths(2),
        ]);

        for ($i = 0; $i < 4; $i++) {
            $instanceDate = $event->start_datetime?->copy()->addWeeks($i)->startOfDay();
            if ($instanceDate === null) {
                continue;
            }

            EventInstance::query()->firstOrCreate([
                'recurring_event_id' => $recurringEvent->id,
                'instance_date' => $instanceDate,
            ], [
                'event_id' => $event->id,
                'status' => EventStatus::Scheduled,
                'cancelled' => false,
                'completed_at' => null,
            ]);
        }

        $secondInstance = EventInstance::query()
            ->where('recurring_event_id', $recurringEvent->id)
            ->orderBy('instance_date')
            ->skip(1)
            ->first();

        if ($secondInstance instanceof EventInstance) {
            $secondInstance->forceFill([
                'cancelled' => true,
            ])->save();
        }

        if ($event->start_datetime !== null) {
            EventException::query()->firstOrCreate([
                'recurring_event_id' => $recurringEvent->id,
                'exception_date' => $event->start_datetime->copy()->addWeeks(2)->startOfDay(),
            ], [
                'is_deleted' => true,
                'replacement_instance_id' => null,
                'reason' => 'Campus facility maintenance',
                'created_by' => $user->id,
            ]);
        }
    }

    /**
     * Start of calendar day between {@see MIN_OPEN_SCHEDULE_LEAD_DAYS} and +5 from local “today”, stable per seed key.
     */
    private function openScheduleBase(Carbon $now, string $entropyKey): Carbon
    {
        $jitter = $this->stableJitterDays($entropyKey, self::OPEN_SCHEDULE_LEAD_SPREAD_DAYS);

        return $now->copy()
            ->setTimezone($now->getTimezone())
            ->startOfDay()
            ->addDays(self::MIN_OPEN_SCHEDULE_LEAD_DAYS + $jitter);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function brightspaceScheduleBase(Carbon $now, array $spec): Carbon
    {
        if (! empty($spec['completed'])) {
            return $now->copy();
        }

        return $this->openScheduleBase($now, (string) ($spec['source_id'] ?? $spec['title']));
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
