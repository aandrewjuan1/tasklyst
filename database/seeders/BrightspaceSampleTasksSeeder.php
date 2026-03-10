<?php

namespace Database\Seeders;

use App\Enums\TaskComplexity;
use App\Enums\TaskPriority;
use App\Enums\TaskSourceType;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class BrightspaceSampleTasksSeeder extends Seeder
{
    private const TARGET_EMAIL = 'andrew.juan.cvt@eac.edu.ph';

    /**
     * @var array<int, array<string, mixed>>
     */
    public const TASKS = [
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

    public function run(): void
    {
        $user = User::where('email', self::TARGET_EMAIL)->first();

        if ($user === null) {
            throw new \RuntimeException(
                'BrightspaceSampleTasksSeeder: seed user not found. Ensure a user with email '.self::TARGET_EMAIL.' exists (e.g. sign up first).'
            );
        }

        $now = Carbon::now();

        foreach (self::TASKS as $spec) {
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
}
