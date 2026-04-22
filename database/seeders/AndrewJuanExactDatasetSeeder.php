<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AndrewJuanExactDatasetSeeder extends Seeder
{
    private const TARGET_EMAIL = 'andrew.juan.cvt@eac.edu.ph';

    public function run(): void
    {
        DB::transaction(function (): void {
            DB::table('users')->updateOrInsert(
                ['email' => self::TARGET_EMAIL],
                [
                    'name' => 'ANDREW JUAN',
                    'email_verified_at' => null,
                    'workos_id' => 'seed-andrew-juan-cvt-eac-edu-ph',
                    'remember_token' => null,
                    'avatar' => '',
                    'calendar_import_past_months' => null,
                    'timezone' => null,
                    'schedule_preferences' => null,
                    'created_at' => '2026-04-22 22:59:44',
                    'updated_at' => '2026-04-22 22:59:59',
                ]
            );

            $userId = (int) DB::table('users')
                ->where('email', self::TARGET_EMAIL)
                ->value('id');

            DB::table('tasks')->where('user_id', $userId)->delete();
            DB::table('events')->where('user_id', $userId)->delete();
            DB::table('projects')->where('user_id', $userId)->delete();
            DB::table('school_classes')->where('user_id', $userId)->delete();
            DB::table('teachers')->where('user_id', $userId)->delete();

            DB::table('teachers')->updateOrInsert(
                ['user_id' => $userId, 'name_normalized' => 'mhel'],
                [
                    'name' => 'MHEL',
                    'created_at' => '2026-04-22 23:42:50',
                    'updated_at' => '2026-04-22 23:42:50',
                ]
            );

            $teacherId = (int) DB::table('teachers')
                ->where('user_id', $userId)
                ->where('name_normalized', 'mhel')
                ->value('id');

            DB::table('school_classes')->updateOrInsert(
                ['user_id' => $userId, 'subject_name' => 'ELECTIVE 3', 'start_time' => '07:00:00'],
                [
                    'teacher_id' => $teacherId,
                    'start_datetime' => null,
                    'end_datetime' => null,
                    'end_time' => '10:00:00',
                    'created_at' => '2026-04-22 23:42:50',
                    'updated_at' => '2026-04-22 23:42:50',
                    'deleted_at' => null,
                ]
            );

            $classId = (int) DB::table('school_classes')
                ->where('user_id', $userId)
                ->where('subject_name', 'ELECTIVE 3')
                ->where('start_time', '07:00:00')
                ->value('id');

            DB::table('recurring_school_classes')->updateOrInsert(
                ['school_class_id' => $classId],
                [
                    'recurrence_type' => 'weekly',
                    'interval' => 1,
                    'start_datetime' => null,
                    'end_datetime' => null,
                    'days_of_week' => '[3]',
                    'created_at' => '2026-04-22 23:42:50',
                    'updated_at' => '2026-04-22 23:42:50',
                ]
            );

            $tasks = [
                [
                    'title' => 'USER MANAGEMENT SYSTEM USING PHP AND FETCH API - ACTIVITY -',
                    'description' => null,
                    'status' => 'to_do',
                    'priority' => 'medium',
                    'complexity' => 'moderate',
                    'duration' => null,
                    'start_datetime' => null,
                    'end_datetime' => '2026-04-30 23:01:00',
                    'project_id' => null,
                    'event_id' => null,
                    'completed_at' => null,
                    'source_type' => 'brightspace',
                    'source_id' => '6606-1252387@eac.brightspace.com',
                    'calendar_feed_id' => null,
                    'source_url' => 'https://eac.brightspace.com/d2l/le/calendar/144202/event/1252387/detailsview?ou=144202#1252387',
                    'teacher_name' => 'IDDEQUITO',
                    'subject_name' => 'Software Engineering 2 LAB (UCOS 4-1)',
                    'school_class_id' => null,
                    'created_at' => '2026-04-22 23:00:05',
                    'updated_at' => '2026-04-23 00:17:29',
                    'deleted_at' => null,
                ],
                [
                    'title' => 'JOB APPLICANTS IN IT DASHBOARD - ACTIVITY -',
                    'description' => null,
                    'status' => 'to_do',
                    'priority' => 'medium',
                    'complexity' => 'moderate',
                    'duration' => 60,
                    'start_datetime' => null,
                    'end_datetime' => '2026-04-26 19:01:00',
                    'project_id' => null,
                    'event_id' => null,
                    'completed_at' => null,
                    'source_type' => 'brightspace',
                    'source_id' => '6606-1253351@eac.brightspace.com',
                    'calendar_feed_id' => null,
                    'source_url' => 'https://eac.brightspace.com/d2l/le/calendar/144193/event/1253351/detailsview?ou=144193#1253351',
                    'teacher_name' => 'IDDEQUITO',
                    'subject_name' => 'Professional Elective 2 (UCOS 4-1)',
                    'school_class_id' => null,
                    'created_at' => '2026-04-22 23:00:05',
                    'updated_at' => '2026-04-23 00:30:18',
                    'deleted_at' => null,
                ],
                [
                    'title' => 'DATA DRIVEN DECISION FOR YOUR BUSINESS - MIDTERM EXAM -',
                    'description' => null,
                    'status' => 'to_do',
                    'priority' => 'high',
                    'complexity' => 'complex',
                    'duration' => null,
                    'start_datetime' => null,
                    'end_datetime' => '2026-05-25 23:01:00',
                    'project_id' => null,
                    'event_id' => null,
                    'completed_at' => null,
                    'source_type' => 'brightspace',
                    'source_id' => '6606-1255595@eac.brightspace.com',
                    'calendar_feed_id' => null,
                    'source_url' => 'https://eac.brightspace.com/d2l/le/calendar/144186/event/1255595/detailsview?ou=144186#1255595',
                    'teacher_name' => 'IDDEQUITO',
                    'subject_name' => 'Data Analysis for Computer Science (UCOS 4-1)',
                    'school_class_id' => null,
                    'created_at' => '2026-04-22 23:00:05',
                    'updated_at' => '2026-04-23 00:17:51',
                    'deleted_at' => null,
                ],
                [
                    'title' => 'HOW TO MAXIMIZE SALES IN DVD RENTAL SHOP? - MIDTERM EXAM',
                    'description' => null,
                    'status' => 'to_do',
                    'priority' => 'high',
                    'complexity' => 'complex',
                    'duration' => null,
                    'start_datetime' => null,
                    'end_datetime' => '2026-05-01 23:09:00',
                    'project_id' => null,
                    'event_id' => null,
                    'completed_at' => null,
                    'source_type' => 'brightspace',
                    'source_id' => '6606-1255818@eac.brightspace.com',
                    'calendar_feed_id' => null,
                    'source_url' => 'https://eac.brightspace.com/d2l/le/calendar/144193/event/1255818/detailsview?ou=144193#1255818',
                    'teacher_name' => 'IDDEQUITO',
                    'subject_name' => 'Professional Elective 2 (UCOS 4-1)',
                    'school_class_id' => null,
                    'created_at' => '2026-04-22 23:00:05',
                    'updated_at' => '2026-04-23 00:17:32',
                    'deleted_at' => null,
                ],
                [
                    'title' => 'ORDER MANAGEMENT SYSTEM - MIDTERM EXAM PROJECT -',
                    'description' => null,
                    'status' => 'to_do',
                    'priority' => 'high',
                    'complexity' => 'complex',
                    'duration' => null,
                    'start_datetime' => null,
                    'end_datetime' => '2026-04-29 23:10:00',
                    'project_id' => null,
                    'event_id' => null,
                    'completed_at' => null,
                    'source_type' => 'brightspace',
                    'source_id' => '6606-1254137@eac.brightspace.com',
                    'calendar_feed_id' => null,
                    'source_url' => 'https://eac.brightspace.com/d2l/le/calendar/144202/event/1254137/detailsview?ou=144202#1254137',
                    'teacher_name' => 'IDDEQUITO',
                    'subject_name' => 'Software Engineering 2 LAB (UCOS 4-1)',
                    'school_class_id' => null,
                    'created_at' => '2026-04-22 23:00:05',
                    'updated_at' => '2026-04-23 00:17:27',
                    'deleted_at' => null,
                ],
                [
                    'title' => 'MEAT SALES WITH TABLEAU - ACTIVITY - Due',
                    'description' => null,
                    'status' => 'to_do',
                    'priority' => 'medium',
                    'complexity' => 'moderate',
                    'duration' => null,
                    'start_datetime' => null,
                    'end_datetime' => '2026-04-30 23:11:00',
                    'project_id' => null,
                    'event_id' => null,
                    'completed_at' => null,
                    'source_type' => 'brightspace',
                    'source_id' => '6606-1264615@eac.brightspace.com',
                    'calendar_feed_id' => null,
                    'source_url' => 'https://eac.brightspace.com/d2l/le/calendar/144193/event/1264615/detailsview?ou=144193#1264615',
                    'teacher_name' => 'IDDEQUITO',
                    'subject_name' => 'Professional Elective 2 (UCOS 4-1)',
                    'school_class_id' => null,
                    'created_at' => '2026-04-22 23:00:05',
                    'updated_at' => '2026-04-22 23:11:13',
                    'deleted_at' => null,
                ],
                [
                    'title' => 'TIME SERIES, KPI CARDS, HISTOGRAMS - ACTIVITY -',
                    'description' => null,
                    'status' => 'to_do',
                    'priority' => 'medium',
                    'complexity' => 'moderate',
                    'duration' => null,
                    'start_datetime' => null,
                    'end_datetime' => '2026-04-29 23:10:00',
                    'project_id' => null,
                    'event_id' => null,
                    'completed_at' => null,
                    'source_type' => 'brightspace',
                    'source_id' => '6606-1267572@eac.brightspace.com',
                    'calendar_feed_id' => null,
                    'source_url' => 'https://eac.brightspace.com/d2l/le/calendar/144193/event/1267572/detailsview?ou=144193#1267572',
                    'teacher_name' => 'IDDEQUITO',
                    'subject_name' => 'Professional Elective 2 (UCOS 4-1)',
                    'school_class_id' => null,
                    'created_at' => '2026-04-22 23:00:05',
                    'updated_at' => '2026-04-23 00:17:25',
                    'deleted_at' => null,
                ],
                [
                    'title' => 'ALPINEJS MIGRATION - ACTIVITY - Due',
                    'description' => null,
                    'status' => 'to_do',
                    'priority' => 'medium',
                    'complexity' => 'moderate',
                    'duration' => null,
                    'start_datetime' => null,
                    'end_datetime' => '2026-06-01 23:09:00',
                    'project_id' => null,
                    'event_id' => null,
                    'completed_at' => null,
                    'source_type' => 'brightspace',
                    'source_id' => '6606-1269864@eac.brightspace.com',
                    'calendar_feed_id' => null,
                    'source_url' => 'https://eac.brightspace.com/d2l/le/calendar/144202/event/1269864/detailsview?ou=144202#1269864',
                    'teacher_name' => 'IDDEQUITO',
                    'subject_name' => 'Software Engineering 2 LAB (UCOS 4-1)',
                    'school_class_id' => null,
                    'created_at' => '2026-04-22 23:00:05',
                    'updated_at' => '2026-04-22 23:09:32',
                    'deleted_at' => null,
                ],
                [
                    'title' => 'CREATING CALCULATED FIELDS - ACTIVITY -',
                    'description' => null,
                    'status' => 'to_do',
                    'priority' => 'medium',
                    'complexity' => 'moderate',
                    'duration' => 60,
                    'start_datetime' => null,
                    'end_datetime' => '2026-04-24 23:09:00',
                    'project_id' => null,
                    'event_id' => null,
                    'completed_at' => null,
                    'source_type' => 'brightspace',
                    'source_id' => '6606-1270541@eac.brightspace.com',
                    'calendar_feed_id' => null,
                    'source_url' => 'https://eac.brightspace.com/d2l/le/calendar/144193/event/1270541/detailsview?ou=144193#1270541',
                    'teacher_name' => 'IDDEQUITO',
                    'subject_name' => 'Professional Elective 2 (UCOS 4-1)',
                    'school_class_id' => null,
                    'created_at' => '2026-04-22 23:00:05',
                    'updated_at' => '2026-04-23 00:30:10',
                    'deleted_at' => null,
                ],
                [
                    'title' => 'ONLINE STORES ANALYSIS - ACTIVITY -',
                    'description' => null,
                    'status' => 'to_do',
                    'priority' => 'high',
                    'complexity' => 'complex',
                    'duration' => null,
                    'start_datetime' => null,
                    'end_datetime' => '2026-06-11 23:10:00',
                    'project_id' => null,
                    'event_id' => null,
                    'completed_at' => null,
                    'source_type' => 'brightspace',
                    'source_id' => '6606-1272696@eac.brightspace.com',
                    'calendar_feed_id' => null,
                    'source_url' => 'https://eac.brightspace.com/d2l/le/calendar/144193/event/1272696/detailsview?ou=144193#1272696',
                    'teacher_name' => 'IDDEQUITO',
                    'subject_name' => 'Professional Elective 2 (UCOS 4-1)',
                    'school_class_id' => null,
                    'created_at' => '2026-04-22 23:00:05',
                    'updated_at' => '2026-04-23 00:17:53',
                    'deleted_at' => null,
                ],
                [
                    'title' => 'IS THE DIFFERENCE REALLY SIGNIFICANT? - FINAL EXAM -',
                    'description' => null,
                    'status' => 'to_do',
                    'priority' => 'high',
                    'complexity' => 'complex',
                    'duration' => 60,
                    'start_datetime' => null,
                    'end_datetime' => '2026-04-25 23:10:00',
                    'project_id' => null,
                    'event_id' => null,
                    'completed_at' => null,
                    'source_type' => 'brightspace',
                    'source_id' => '6606-1270622@eac.brightspace.com',
                    'calendar_feed_id' => null,
                    'source_url' => 'https://eac.brightspace.com/d2l/le/calendar/144186/event/1270622/detailsview?ou=144186#1270622',
                    'teacher_name' => 'IDDEQUITO',
                    'subject_name' => 'Data Analysis for Computer Science (UCOS 4-1)',
                    'school_class_id' => null,
                    'created_at' => '2026-04-22 23:00:05',
                    'updated_at' => '2026-04-23 00:30:14',
                    'deleted_at' => null,
                ],
                [
                    'title' => 'STATIC AND DYNAMIC RESUME WEBSITE-  FINAL EXAM PROJECT',
                    'description' => null,
                    'status' => 'to_do',
                    'priority' => 'high',
                    'complexity' => 'complex',
                    'duration' => null,
                    'start_datetime' => null,
                    'end_datetime' => '2026-05-06 23:11:00',
                    'project_id' => null,
                    'event_id' => null,
                    'completed_at' => null,
                    'source_type' => 'brightspace',
                    'source_id' => '6606-1271220@eac.brightspace.com',
                    'calendar_feed_id' => null,
                    'source_url' => 'https://eac.brightspace.com/d2l/le/calendar/144202/event/1271220/detailsview?ou=144202#1271220',
                    'teacher_name' => 'IDDEQUITO',
                    'subject_name' => 'Software Engineering 2 LAB (UCOS 4-1)',
                    'school_class_id' => null,
                    'created_at' => '2026-04-22 23:00:05',
                    'updated_at' => '2026-04-23 00:17:46',
                    'deleted_at' => null,
                ],
                [
                    'title' => 'RUN 5KM',
                    'description' => null,
                    'status' => 'to_do',
                    'priority' => 'medium',
                    'complexity' => 'moderate',
                    'duration' => null,
                    'start_datetime' => null,
                    'end_datetime' => null,
                    'project_id' => null,
                    'event_id' => null,
                    'completed_at' => null,
                    'source_type' => null,
                    'source_id' => null,
                    'calendar_feed_id' => null,
                    'source_url' => null,
                    'teacher_name' => null,
                    'subject_name' => null,
                    'school_class_id' => null,
                    'created_at' => '2026-04-22 23:00:59',
                    'updated_at' => '2026-04-22 23:41:43',
                    'deleted_at' => '2026-04-22 23:41:43',
                ],
                [
                    'title' => '5KM RUN DAILY',
                    'description' => null,
                    'status' => 'to_do',
                    'priority' => 'medium',
                    'complexity' => 'moderate',
                    'duration' => 120,
                    'start_datetime' => null,
                    'end_datetime' => null,
                    'project_id' => null,
                    'event_id' => null,
                    'completed_at' => null,
                    'source_type' => null,
                    'source_id' => null,
                    'calendar_feed_id' => null,
                    'source_url' => null,
                    'teacher_name' => null,
                    'subject_name' => null,
                    'school_class_id' => null,
                    'created_at' => '2026-04-22 23:41:04',
                    'updated_at' => '2026-04-22 23:41:50',
                    'deleted_at' => null,
                ],
            ];

            foreach ($tasks as $task) {
                DB::table('tasks')->updateOrInsert(
                    ['user_id' => $userId, 'title' => $task['title'], 'source_id' => $task['source_id']],
                    $task + ['user_id' => $userId]
                );
            }

            $dailyRunTaskId = DB::table('tasks')
                ->where('user_id', $userId)
                ->where('title', '5KM RUN DAILY')
                ->value('id');

            if ($dailyRunTaskId !== null) {
                DB::table('recurring_tasks')->updateOrInsert(
                    ['task_id' => $dailyRunTaskId],
                    [
                        'recurrence_type' => 'daily',
                        'interval' => 1,
                        'start_datetime' => null,
                        'end_datetime' => null,
                        'days_of_week' => null,
                        'created_at' => '2026-04-22 23:41:50',
                        'updated_at' => '2026-04-22 23:41:50',
                    ]
                );
            }
        });
    }
}
