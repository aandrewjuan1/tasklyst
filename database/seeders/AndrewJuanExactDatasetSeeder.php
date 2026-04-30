<?php

namespace Database\Seeders;

use App\Models\Task;
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

            $userId = (int) DB::table('users')->where('email', self::TARGET_EMAIL)->value('id');
            $existingTaskIds = DB::table('tasks')->where('user_id', $userId)->pluck('id');
            $existingClassIds = DB::table('school_classes')->where('user_id', $userId)->pluck('id');

            if ($existingTaskIds->isNotEmpty()) {
                DB::table('recurring_tasks')->whereIn('task_id', $existingTaskIds)->delete();
            }

            if ($existingClassIds->isNotEmpty()) {
                DB::table('recurring_school_classes')->whereIn('school_class_id', $existingClassIds)->delete();
            }

            DB::table('tasks')->where('user_id', $userId)->delete();
            DB::table('events')->where('user_id', $userId)->delete();
            DB::table('projects')->where('user_id', $userId)->delete();
            DB::table('school_classes')->where('user_id', $userId)->delete();
            DB::table('teachers')->where('user_id', $userId)->delete();
            DB::table('focus_sessions')->where('user_id', $userId)->delete();

            $teachers = [
                ['name' => 'SIBBALUCA, BRANDON G.', 'name_normalized' => 'sibbaluca, brandon g.', 'created_at' => '2026-04-29 17:56:02', 'updated_at' => '2026-04-29 17:56:02'],
                ['name' => 'ZAMORA, ISMAEL ESTEBAN', 'name_normalized' => 'zamora, ismael esteban', 'created_at' => '2026-04-29 17:56:21', 'updated_at' => '2026-04-29 17:56:21'],
                ['name' => 'DEQUITO, IVAN DUANE', 'name_normalized' => 'dequito, ivan duane', 'created_at' => '2026-04-29 22:11:29', 'updated_at' => '2026-04-29 22:11:29'],
                ['name' => 'PAN, BUENCARLO BALCE', 'name_normalized' => 'pan, buencarlo balce', 'created_at' => '2026-04-29 22:11:41', 'updated_at' => '2026-04-29 22:11:41'],
            ];

            foreach ($teachers as $teacher) {
                DB::table('teachers')->updateOrInsert(
                    ['user_id' => $userId, 'name_normalized' => $teacher['name_normalized']],
                    $teacher + ['user_id' => $userId]
                );
            }

            $teacherIdsByName = DB::table('teachers')->where('user_id', $userId)->pluck('id', 'name')->all();

            $schoolClasses = [
                ['subject_name' => 'PROFESSIONAL ELECTIVE 3', 'start_datetime' => null, 'end_datetime' => null, 'start_time' => '07:30:00', 'end_time' => '10:30:00', 'teacher_name' => 'ZAMORA, ISMAEL ESTEBAN', 'created_at' => '2026-04-22 23:42:50', 'updated_at' => '2026-04-29 22:11:53', 'deleted_at' => null],
                ['subject_name' => 'CS THESIS WRITING 2', 'start_datetime' => null, 'end_datetime' => null, 'start_time' => '07:00:00', 'end_time' => '10:00:00', 'teacher_name' => 'SIBBALUCA, BRANDON G.', 'created_at' => '2026-04-29 17:57:54', 'updated_at' => '2026-04-29 17:57:54', 'deleted_at' => null],
            ];

            foreach ($schoolClasses as $schoolClass) {
                DB::table('school_classes')->updateOrInsert(
                    ['user_id' => $userId, 'subject_name' => $schoolClass['subject_name'], 'start_time' => $schoolClass['start_time']],
                    [
                        'teacher_id' => $teacherIdsByName[$schoolClass['teacher_name']] ?? null,
                        'start_datetime' => $schoolClass['start_datetime'],
                        'end_datetime' => $schoolClass['end_datetime'],
                        'end_time' => $schoolClass['end_time'],
                        'created_at' => $schoolClass['created_at'],
                        'updated_at' => $schoolClass['updated_at'],
                        'deleted_at' => $schoolClass['deleted_at'],
                    ]
                );
            }

            $classIdsByKey = DB::table('school_classes')->where('user_id', $userId)->get(['id', 'subject_name', 'start_time'])->mapWithKeys(fn ($row): array => [$row->subject_name.'|'.$row->start_time => (int) $row->id])->all();

            $recurringSchoolClasses = [
                ['subject_name' => 'CS THESIS WRITING 2', 'start_time' => '07:00:00', 'recurrence_type' => 'weekly', 'interval' => 1, 'start_datetime' => null, 'end_datetime' => null, 'days_of_week' => '[6]', 'created_at' => '2026-04-29 17:57:54', 'updated_at' => '2026-04-29 17:57:54'],
                ['subject_name' => 'PROFESSIONAL ELECTIVE 3', 'start_time' => '07:30:00', 'recurrence_type' => 'weekly', 'interval' => 1, 'start_datetime' => null, 'end_datetime' => null, 'days_of_week' => '[3]', 'created_at' => '2026-04-29 17:58:00', 'updated_at' => '2026-04-29 17:58:00'],
            ];

            foreach ($recurringSchoolClasses as $recurringSchoolClass) {
                $classId = $classIdsByKey[$recurringSchoolClass['subject_name'].'|'.$recurringSchoolClass['start_time']] ?? null;
                if ($classId === null) {
                    continue;
                }

                DB::table('recurring_school_classes')->updateOrInsert(
                    ['school_class_id' => $classId],
                    [
                        'recurrence_type' => $recurringSchoolClass['recurrence_type'],
                        'interval' => $recurringSchoolClass['interval'],
                        'start_datetime' => $recurringSchoolClass['start_datetime'],
                        'end_datetime' => $recurringSchoolClass['end_datetime'],
                        'days_of_week' => $recurringSchoolClass['days_of_week'],
                        'created_at' => $recurringSchoolClass['created_at'],
                        'updated_at' => $recurringSchoolClass['updated_at'],
                    ]
                );
            }

            $tasks = [
                ['title' => 'USER MANAGEMENT SYSTEM USING PHP AND FETCH API - ACTIVITY -', 'description' => null, 'status' => 'to_do', 'priority' => 'medium', 'complexity' => 'moderate', 'duration' => null, 'start_datetime' => null, 'end_datetime' => '2026-05-02 23:01:00', 'project_id' => null, 'event_id' => null, 'completed_at' => null, 'source_type' => 'brightspace', 'source_id' => '6606-1252387@eac.brightspace.com', 'calendar_feed_id' => null, 'source_url' => 'https://eac.brightspace.com/d2l/le/calendar/144202/event/1252387/detailsview?ou=144202#1252387', 'teacher_name' => 'IDDEQUITO', 'subject_name' => 'Software Engineering 2 LAB (UCOS 4-1)', 'school_class_id' => null, 'created_at' => '2026-04-22 23:00:05', 'updated_at' => '2026-04-29 22:12:21', 'deleted_at' => null],
                ['title' => 'JOB APPLICANTS IN IT DASHBOARD - ACTIVITY -', 'description' => null, 'status' => 'to_do', 'priority' => 'medium', 'complexity' => 'moderate', 'duration' => 60, 'start_datetime' => null, 'end_datetime' => '2026-05-08 19:01:00', 'project_id' => null, 'event_id' => null, 'completed_at' => null, 'source_type' => 'brightspace', 'source_id' => '6606-1253351@eac.brightspace.com', 'calendar_feed_id' => null, 'source_url' => 'https://eac.brightspace.com/d2l/le/calendar/144193/event/1253351/detailsview?ou=144193#1253351', 'teacher_name' => 'IDDEQUITO', 'subject_name' => 'Professional Elective 2 (UCOS 4-1)', 'school_class_id' => null, 'created_at' => '2026-04-22 23:00:05', 'updated_at' => '2026-04-29 17:43:05', 'deleted_at' => null],
                ['title' => 'DATA DRIVEN DECISION FOR YOUR BUSINESS - MIDTERM EXAM -', 'description' => null, 'status' => 'to_do', 'priority' => 'high', 'complexity' => 'complex', 'duration' => null, 'start_datetime' => null, 'end_datetime' => '2026-05-25 23:01:00', 'project_id' => null, 'event_id' => null, 'completed_at' => null, 'source_type' => 'brightspace', 'source_id' => '6606-1255595@eac.brightspace.com', 'calendar_feed_id' => null, 'source_url' => 'https://eac.brightspace.com/d2l/le/calendar/144186/event/1255595/detailsview?ou=144186#1255595', 'teacher_name' => 'IDDEQUITO', 'subject_name' => 'Data Analysis for Computer Science (UCOS 4-1)', 'school_class_id' => null, 'created_at' => '2026-04-22 23:00:05', 'updated_at' => '2026-04-23 00:17:51', 'deleted_at' => null],
                ['title' => 'HOW TO MAXIMIZE SALES IN DVD RENTAL SHOP? - MIDTERM EXAM', 'description' => null, 'status' => 'to_do', 'priority' => 'high', 'complexity' => 'complex', 'duration' => null, 'start_datetime' => null, 'end_datetime' => '2026-05-01 23:09:00', 'project_id' => null, 'event_id' => null, 'completed_at' => null, 'source_type' => 'brightspace', 'source_id' => '6606-1255818@eac.brightspace.com', 'calendar_feed_id' => null, 'source_url' => 'https://eac.brightspace.com/d2l/le/calendar/144193/event/1255818/detailsview?ou=144193#1255818', 'teacher_name' => null, 'subject_name' => null, 'school_class_id' => null, 'created_at' => '2026-04-22 23:00:05', 'updated_at' => '2026-04-29 22:30:34', 'deleted_at' => null],
                ['title' => 'ORDER MANAGEMENT SYSTEM - MIDTERM EXAM PROJECT -', 'description' => null, 'status' => 'to_do', 'priority' => 'high', 'complexity' => 'complex', 'duration' => null, 'start_datetime' => null, 'end_datetime' => '2026-05-20 23:10:00', 'project_id' => null, 'event_id' => null, 'completed_at' => null, 'source_type' => 'brightspace', 'source_id' => '6606-1254137@eac.brightspace.com', 'calendar_feed_id' => null, 'source_url' => 'https://eac.brightspace.com/d2l/le/calendar/144202/event/1254137/detailsview?ou=144202#1254137', 'teacher_name' => 'IDDEQUITO', 'subject_name' => 'Software Engineering 2 LAB (UCOS 4-1)', 'school_class_id' => null, 'created_at' => '2026-04-22 23:00:05', 'updated_at' => '2026-04-29 22:12:29', 'deleted_at' => null],
                ['title' => 'MEAT SALES WITH TABLEAU - ACTIVITY - Due', 'description' => null, 'status' => 'to_do', 'priority' => 'medium', 'complexity' => 'moderate', 'duration' => null, 'start_datetime' => null, 'end_datetime' => '2026-05-03 23:11:00', 'project_id' => null, 'event_id' => null, 'completed_at' => null, 'source_type' => 'brightspace', 'source_id' => '6606-1264615@eac.brightspace.com', 'calendar_feed_id' => null, 'source_url' => 'https://eac.brightspace.com/d2l/le/calendar/144193/event/1264615/detailsview?ou=144193#1264615', 'teacher_name' => 'IDDEQUITO', 'subject_name' => 'Professional Elective 2 (UCOS 4-1)', 'school_class_id' => null, 'created_at' => '2026-04-22 23:00:05', 'updated_at' => '2026-04-29 22:12:35', 'deleted_at' => null],
                ['title' => 'TIME SERIES, KPI CARDS, HISTOGRAMS - ACTIVITY -', 'description' => null, 'status' => 'to_do', 'priority' => 'medium', 'complexity' => 'moderate', 'duration' => null, 'start_datetime' => null, 'end_datetime' => '2026-05-01 23:10:00', 'project_id' => null, 'event_id' => null, 'completed_at' => null, 'source_type' => 'brightspace', 'source_id' => '6606-1267572@eac.brightspace.com', 'calendar_feed_id' => null, 'source_url' => 'https://eac.brightspace.com/d2l/le/calendar/144193/event/1267572/detailsview?ou=144193#1267572', 'teacher_name' => 'IDDEQUITO', 'subject_name' => 'Professional Elective 2 (UCOS 4-1)', 'school_class_id' => null, 'created_at' => '2026-04-22 23:00:05', 'updated_at' => '2026-04-29 22:12:25', 'deleted_at' => null],
                ['title' => 'ALPINEJS MIGRATION - ACTIVITY - Due', 'description' => null, 'status' => 'to_do', 'priority' => 'medium', 'complexity' => 'moderate', 'duration' => null, 'start_datetime' => null, 'end_datetime' => '2026-05-28 23:09:00', 'project_id' => null, 'event_id' => null, 'completed_at' => null, 'source_type' => 'brightspace', 'source_id' => '6606-1269864@eac.brightspace.com', 'calendar_feed_id' => null, 'source_url' => 'https://eac.brightspace.com/d2l/le/calendar/144202/event/1269864/detailsview?ou=144202#1269864', 'teacher_name' => 'IDDEQUITO', 'subject_name' => 'Software Engineering 2 LAB (UCOS 4-1)', 'school_class_id' => null, 'created_at' => '2026-04-22 23:00:05', 'updated_at' => '2026-04-29 22:13:02', 'deleted_at' => null],
                ['title' => 'CREATING CALCULATED FIELDS - ACTIVITY -', 'description' => null, 'status' => 'to_do', 'priority' => 'medium', 'complexity' => 'moderate', 'duration' => 60, 'start_datetime' => null, 'end_datetime' => '2026-05-11 23:09:00', 'project_id' => null, 'event_id' => null, 'completed_at' => null, 'source_type' => 'brightspace', 'source_id' => '6606-1270541@eac.brightspace.com', 'calendar_feed_id' => null, 'source_url' => 'https://eac.brightspace.com/d2l/le/calendar/144193/event/1270541/detailsview?ou=144193#1270541', 'teacher_name' => 'IDDEQUITO', 'subject_name' => 'Professional Elective 2 (UCOS 4-1)', 'school_class_id' => null, 'created_at' => '2026-04-22 23:00:05', 'updated_at' => '2026-04-29 17:43:09', 'deleted_at' => null],
                ['title' => 'ONLINE STORES ANALYSIS - ACTIVITY -', 'description' => null, 'status' => 'to_do', 'priority' => 'high', 'complexity' => 'complex', 'duration' => null, 'start_datetime' => null, 'end_datetime' => '2026-05-23 23:10:00', 'project_id' => null, 'event_id' => null, 'completed_at' => null, 'source_type' => 'brightspace', 'source_id' => '6606-1272696@eac.brightspace.com', 'calendar_feed_id' => null, 'source_url' => 'https://eac.brightspace.com/d2l/le/calendar/144193/event/1272696/detailsview?ou=144193#1272696', 'teacher_name' => 'IDDEQUITO', 'subject_name' => 'Professional Elective 2 (UCOS 4-1)', 'school_class_id' => null, 'created_at' => '2026-04-22 23:00:05', 'updated_at' => '2026-04-29 22:12:43', 'deleted_at' => null],
                ['title' => 'IS THE DIFFERENCE REALLY SIGNIFICANT? - FINAL EXAM -', 'description' => null, 'status' => 'to_do', 'priority' => 'high', 'complexity' => 'complex', 'duration' => 60, 'start_datetime' => null, 'end_datetime' => '2026-05-06 23:10:00', 'project_id' => null, 'event_id' => null, 'completed_at' => null, 'source_type' => 'brightspace', 'source_id' => '6606-1270622@eac.brightspace.com', 'calendar_feed_id' => null, 'source_url' => 'https://eac.brightspace.com/d2l/le/calendar/144186/event/1270622/detailsview?ou=144186#1270622', 'teacher_name' => 'IDDEQUITO', 'subject_name' => 'Data Analysis for Computer Science (UCOS 4-1)', 'school_class_id' => null, 'created_at' => '2026-04-22 23:00:05', 'updated_at' => '2026-04-29 17:43:01', 'deleted_at' => null],
                ['title' => 'STATIC AND DYNAMIC RESUME WEBSITE-  FINAL EXAM PROJECT', 'description' => null, 'status' => 'to_do', 'priority' => 'high', 'complexity' => 'complex', 'duration' => null, 'start_datetime' => null, 'end_datetime' => '2026-05-06 23:11:00', 'project_id' => null, 'event_id' => null, 'completed_at' => null, 'source_type' => 'brightspace', 'source_id' => '6606-1271220@eac.brightspace.com', 'calendar_feed_id' => null, 'source_url' => 'https://eac.brightspace.com/d2l/le/calendar/144202/event/1271220/detailsview?ou=144202#1271220', 'teacher_name' => 'IDDEQUITO', 'subject_name' => 'Software Engineering 2 LAB (UCOS 4-1)', 'school_class_id' => null, 'created_at' => '2026-04-22 23:00:05', 'updated_at' => '2026-04-23 00:17:46', 'deleted_at' => null],
                ['title' => 'RUN 5KM', 'description' => null, 'status' => 'to_do', 'priority' => 'medium', 'complexity' => 'moderate', 'duration' => null, 'start_datetime' => null, 'end_datetime' => null, 'project_id' => null, 'event_id' => null, 'completed_at' => null, 'source_type' => null, 'source_id' => null, 'calendar_feed_id' => null, 'source_url' => null, 'teacher_name' => null, 'subject_name' => null, 'school_class_id' => null, 'created_at' => '2026-04-22 23:00:59', 'updated_at' => '2026-04-22 23:41:43', 'deleted_at' => '2026-04-22 23:41:43'],
                ['title' => '5KM RUN DAILY', 'description' => null, 'status' => 'to_do', 'priority' => 'medium', 'complexity' => 'moderate', 'duration' => 30, 'start_datetime' => '2026-04-29 17:00:00', 'end_datetime' => null, 'project_id' => null, 'event_id' => null, 'completed_at' => null, 'source_type' => null, 'source_id' => null, 'calendar_feed_id' => null, 'source_url' => null, 'teacher_name' => null, 'subject_name' => null, 'school_class_id' => null, 'created_at' => '2026-04-22 23:41:04', 'updated_at' => '2026-04-29 22:13:55', 'deleted_at' => null],
                ['title' => 'Laundry and Fold Weekday Clothes', 'description' => 'Wash, dry, and fold uniforms and school clothes.', 'status' => 'to_do', 'priority' => 'medium', 'complexity' => 'simple', 'duration' => 90, 'start_datetime' => '2026-05-02 18:30:00', 'end_datetime' => null, 'project_id' => null, 'event_id' => null, 'completed_at' => null, 'source_type' => null, 'source_id' => 'routine-chore-laundry', 'calendar_feed_id' => null, 'source_url' => null, 'teacher_name' => null, 'subject_name' => null, 'school_class_id' => null, 'created_at' => '2026-04-29 22:14:10', 'updated_at' => '2026-04-29 22:14:10', 'deleted_at' => null],
                ['title' => 'Clean and Organize Study Desk', 'description' => 'Declutter desk, wipe surfaces, and sort papers.', 'status' => 'to_do', 'priority' => 'medium', 'complexity' => 'simple', 'duration' => 45, 'start_datetime' => '2026-05-04 16:00:00', 'end_datetime' => null, 'project_id' => null, 'event_id' => null, 'completed_at' => null, 'source_type' => null, 'source_id' => 'routine-chore-desk-cleanup', 'calendar_feed_id' => null, 'source_url' => null, 'teacher_name' => null, 'subject_name' => null, 'school_class_id' => null, 'created_at' => '2026-04-29 22:14:20', 'updated_at' => '2026-04-29 22:14:20', 'deleted_at' => null],
                ['title' => 'Weekly Grocery Run for Dorm Supplies', 'description' => 'Buy toiletries, snacks, and basic meal prep ingredients.', 'status' => 'to_do', 'priority' => 'medium', 'complexity' => 'moderate', 'duration' => 75, 'start_datetime' => '2026-05-03 10:00:00', 'end_datetime' => null, 'project_id' => null, 'event_id' => null, 'completed_at' => null, 'source_type' => null, 'source_id' => 'routine-chore-groceries', 'calendar_feed_id' => null, 'source_url' => null, 'teacher_name' => null, 'subject_name' => null, 'school_class_id' => null, 'created_at' => '2026-04-29 22:14:30', 'updated_at' => '2026-04-29 22:14:30', 'deleted_at' => null],
                ['title' => 'Meal Prep for Class Days', 'description' => 'Prepare 2-3 simple meals for busy class days.', 'status' => 'to_do', 'priority' => 'medium', 'complexity' => 'moderate', 'duration' => 120, 'start_datetime' => '2026-05-05 17:30:00', 'end_datetime' => null, 'project_id' => null, 'event_id' => null, 'completed_at' => null, 'source_type' => null, 'source_id' => 'routine-chore-meal-prep', 'calendar_feed_id' => null, 'source_url' => null, 'teacher_name' => null, 'subject_name' => null, 'school_class_id' => null, 'created_at' => '2026-04-29 22:14:40', 'updated_at' => '2026-04-29 22:14:40', 'deleted_at' => null],
                ['title' => 'Review Monthly Budget and Expenses', 'description' => 'Track transport, food, and school spending.', 'status' => 'to_do', 'priority' => 'high', 'complexity' => 'moderate', 'duration' => 60, 'start_datetime' => '2026-05-07 20:00:00', 'end_datetime' => null, 'project_id' => null, 'event_id' => null, 'completed_at' => null, 'source_type' => null, 'source_id' => 'routine-chore-budget-review', 'calendar_feed_id' => null, 'source_url' => null, 'teacher_name' => null, 'subject_name' => null, 'school_class_id' => null, 'created_at' => '2026-04-29 22:14:50', 'updated_at' => '2026-04-29 22:14:50', 'deleted_at' => null],
                ['title' => 'Pay Utility and Internet Share', 'description' => 'Settle dorm utilities and internet contribution before cutoff.', 'status' => 'to_do', 'priority' => 'high', 'complexity' => 'simple', 'duration' => 30, 'start_datetime' => '2026-05-10 14:30:00', 'end_datetime' => '2026-05-10 17:30:00', 'project_id' => null, 'event_id' => null, 'completed_at' => null, 'source_type' => null, 'source_id' => 'chore-pay-bills-may', 'calendar_feed_id' => null, 'source_url' => null, 'teacher_name' => null, 'subject_name' => null, 'school_class_id' => null, 'created_at' => '2026-04-29 22:15:00', 'updated_at' => '2026-04-29 22:15:00', 'deleted_at' => null],
                ['title' => 'Professional Elective 3 Weekly Review Session', 'description' => 'Summarize lecture points and solve practice items.', 'status' => 'to_do', 'priority' => 'high', 'complexity' => 'moderate', 'duration' => 90, 'start_datetime' => '2026-05-03 19:00:00', 'end_datetime' => null, 'project_id' => null, 'event_id' => null, 'completed_at' => null, 'source_type' => null, 'source_id' => 'study-pe3-weekly-review', 'calendar_feed_id' => null, 'source_url' => null, 'teacher_name' => null, 'subject_name' => 'PROFESSIONAL ELECTIVE 3', 'school_class_id' => null, 'created_at' => '2026-04-29 22:15:10', 'updated_at' => '2026-04-29 22:15:10', 'deleted_at' => null],
                ['title' => 'CS Thesis Writing Draft Improvement Session', 'description' => 'Revise chapter flow and polish citations.', 'status' => 'to_do', 'priority' => 'high', 'complexity' => 'complex', 'duration' => 120, 'start_datetime' => '2026-05-06 15:30:00', 'end_datetime' => null, 'project_id' => null, 'event_id' => null, 'completed_at' => null, 'source_type' => null, 'source_id' => 'study-thesis-draft-weekly', 'calendar_feed_id' => null, 'source_url' => null, 'teacher_name' => null, 'subject_name' => 'CS THESIS WRITING 2', 'school_class_id' => null, 'created_at' => '2026-04-29 22:15:20', 'updated_at' => '2026-04-29 22:15:20', 'deleted_at' => null],
                ['title' => 'Data Analysis Formula and Interpretation Drill', 'description' => 'Practice statistical formulas and interpretation drills.', 'status' => 'to_do', 'priority' => 'high', 'complexity' => 'complex', 'duration' => 90, 'start_datetime' => '2026-05-08 09:00:00', 'end_datetime' => '2026-05-08 10:30:00', 'project_id' => null, 'event_id' => null, 'completed_at' => null, 'source_type' => null, 'source_id' => 'study-data-analysis-drill-may08', 'calendar_feed_id' => null, 'source_url' => null, 'teacher_name' => null, 'subject_name' => 'Data Analysis for Computer Science (UCOS 4-1)', 'school_class_id' => null, 'created_at' => '2026-04-29 22:15:30', 'updated_at' => '2026-04-29 22:15:30', 'deleted_at' => null],
                ['title' => 'Software Engineering Mock Coding Interview', 'description' => 'Timed problem solving and short architecture explanation.', 'status' => 'to_do', 'priority' => 'medium', 'complexity' => 'complex', 'duration' => 75, 'start_datetime' => '2026-05-12 13:30:00', 'end_datetime' => '2026-05-12 14:45:00', 'project_id' => null, 'event_id' => null, 'completed_at' => null, 'source_type' => null, 'source_id' => 'study-se2-mock-interview', 'calendar_feed_id' => null, 'source_url' => null, 'teacher_name' => null, 'subject_name' => 'Software Engineering 2 LAB (UCOS 4-1)', 'school_class_id' => null, 'created_at' => '2026-04-29 22:15:40', 'updated_at' => '2026-04-29 22:15:40', 'deleted_at' => null],
                ['title' => 'Consolidate Notes for Professional Elective 2', 'description' => 'Merge module notes and produce quick-reference reviewers.', 'status' => 'to_do', 'priority' => 'medium', 'complexity' => 'moderate', 'duration' => 90, 'start_datetime' => '2026-05-14 18:00:00', 'end_datetime' => '2026-05-14 19:30:00', 'project_id' => null, 'event_id' => null, 'completed_at' => null, 'source_type' => null, 'source_id' => 'study-pe2-notes-consolidation', 'calendar_feed_id' => null, 'source_url' => null, 'teacher_name' => null, 'subject_name' => 'Professional Elective 2 (UCOS 4-1)', 'school_class_id' => null, 'created_at' => '2026-04-29 22:15:50', 'updated_at' => '2026-04-29 22:15:50', 'deleted_at' => null],
                ['title' => 'Final Exam Active Recall Session', 'description' => 'Active recall and spaced repetition for upcoming finals.', 'status' => 'to_do', 'priority' => 'high', 'complexity' => 'moderate', 'duration' => 60, 'start_datetime' => '2026-05-16 20:30:00', 'end_datetime' => null, 'project_id' => null, 'event_id' => null, 'completed_at' => null, 'source_type' => null, 'source_id' => 'study-finals-active-recall', 'calendar_feed_id' => null, 'source_url' => null, 'teacher_name' => null, 'subject_name' => null, 'school_class_id' => null, 'created_at' => '2026-04-29 22:16:00', 'updated_at' => '2026-04-29 22:16:00', 'deleted_at' => null],
                ['title' => 'Completed Thesis Literature Sweep', 'description' => 'Review and annotate recent related studies.', 'status' => 'done', 'priority' => 'high', 'complexity' => 'complex', 'duration' => 90, 'start_datetime' => '2026-04-10 16:00:00', 'end_datetime' => '2026-04-18 23:59:00', 'project_id' => null, 'event_id' => null, 'completed_at' => '2026-04-10 18:10:00', 'source_type' => null, 'source_id' => 'history-completed-thesis-sweep', 'calendar_feed_id' => null, 'source_url' => null, 'teacher_name' => null, 'subject_name' => 'CS THESIS WRITING 2', 'school_class_id' => null, 'created_at' => '2026-04-10 15:40:00', 'updated_at' => '2026-04-10 18:10:00', 'deleted_at' => null],
                ['title' => 'Completed SQL Practice Set A', 'description' => 'Run joins, aggregations, and explain plans.', 'status' => 'done', 'priority' => 'medium', 'complexity' => 'moderate', 'duration' => 75, 'start_datetime' => '2026-04-11 19:00:00', 'end_datetime' => '2026-04-19 23:59:00', 'project_id' => null, 'event_id' => null, 'completed_at' => '2026-04-11 20:25:00', 'source_type' => null, 'source_id' => 'history-completed-sql-practice-a', 'calendar_feed_id' => null, 'source_url' => null, 'teacher_name' => null, 'subject_name' => 'Professional Elective 2 (UCOS 4-1)', 'school_class_id' => null, 'created_at' => '2026-04-11 18:40:00', 'updated_at' => '2026-04-11 20:25:00', 'deleted_at' => null],
                ['title' => 'Completed Data Viz Reviewer', 'description' => 'Summarize dashboard and chart selection rules.', 'status' => 'done', 'priority' => 'medium', 'complexity' => 'moderate', 'duration' => 60, 'start_datetime' => '2026-04-13 14:10:00', 'end_datetime' => '2026-04-21 23:59:00', 'project_id' => null, 'event_id' => null, 'completed_at' => '2026-04-13 15:20:00', 'source_type' => null, 'source_id' => 'history-completed-data-viz-reviewer', 'calendar_feed_id' => null, 'source_url' => null, 'teacher_name' => null, 'subject_name' => 'Data Analysis for Computer Science (UCOS 4-1)', 'school_class_id' => null, 'created_at' => '2026-04-13 13:55:00', 'updated_at' => '2026-04-13 15:20:00', 'deleted_at' => null],
                ['title' => 'Completed API Endpoint Refactor', 'description' => 'Refactor endpoint handlers and validations.', 'status' => 'done', 'priority' => 'high', 'complexity' => 'complex', 'duration' => 120, 'start_datetime' => '2026-04-15 17:30:00', 'end_datetime' => '2026-04-23 23:59:00', 'project_id' => null, 'event_id' => null, 'completed_at' => '2026-04-15 19:50:00', 'source_type' => null, 'source_id' => 'history-completed-api-refactor', 'calendar_feed_id' => null, 'source_url' => null, 'teacher_name' => null, 'subject_name' => 'Software Engineering 2 LAB (UCOS 4-1)', 'school_class_id' => null, 'created_at' => '2026-04-15 17:00:00', 'updated_at' => '2026-04-15 19:50:00', 'deleted_at' => null],
                ['title' => 'Completed Statistics Problem Drill', 'description' => 'Solve distribution and hypothesis drills.', 'status' => 'done', 'priority' => 'high', 'complexity' => 'complex', 'duration' => 90, 'start_datetime' => '2026-04-17 13:30:00', 'end_datetime' => '2026-04-24 23:59:00', 'project_id' => null, 'event_id' => null, 'completed_at' => '2026-04-17 15:05:00', 'source_type' => null, 'source_id' => 'history-completed-statistics-drill', 'calendar_feed_id' => null, 'source_url' => null, 'teacher_name' => null, 'subject_name' => 'Data Analysis for Computer Science (UCOS 4-1)', 'school_class_id' => null, 'created_at' => '2026-04-17 13:10:00', 'updated_at' => '2026-04-17 15:05:00', 'deleted_at' => null],
                ['title' => 'Completed Thesis Defense Slide Polish', 'description' => 'Tighten narrative and slide flow for defense.', 'status' => 'done', 'priority' => 'high', 'complexity' => 'moderate', 'duration' => 80, 'start_datetime' => '2026-04-19 18:40:00', 'end_datetime' => '2026-04-26 23:59:00', 'project_id' => null, 'event_id' => null, 'completed_at' => '2026-04-19 20:10:00', 'source_type' => null, 'source_id' => 'history-completed-thesis-slides', 'calendar_feed_id' => null, 'source_url' => null, 'teacher_name' => null, 'subject_name' => 'CS THESIS WRITING 2', 'school_class_id' => null, 'created_at' => '2026-04-19 18:15:00', 'updated_at' => '2026-04-19 20:10:00', 'deleted_at' => null],
            ];

            foreach ($tasks as $task) {
                DB::table('tasks')->updateOrInsert(
                    ['user_id' => $userId, 'title' => $task['title'], 'source_id' => $task['source_id']],
                    $task + ['user_id' => $userId]
                );
            }

            $recurringTasks = [
                ['task_title' => '5KM RUN DAILY', 'recurrence_type' => 'daily', 'interval' => 1, 'start_datetime' => '2026-04-29 17:00:00', 'end_datetime' => null, 'days_of_week' => null, 'created_at' => '2026-04-22 23:41:50', 'updated_at' => '2026-04-29 22:13:55'],
                ['task_title' => 'Laundry and Fold Weekday Clothes', 'recurrence_type' => 'weekly', 'interval' => 1, 'start_datetime' => '2026-05-02 18:30:00', 'end_datetime' => null, 'days_of_week' => '[5]', 'created_at' => '2026-04-29 22:16:10', 'updated_at' => '2026-04-29 22:16:10'],
                ['task_title' => 'Weekly Grocery Run for Dorm Supplies', 'recurrence_type' => 'weekly', 'interval' => 1, 'start_datetime' => '2026-05-03 10:00:00', 'end_datetime' => null, 'days_of_week' => '[6]', 'created_at' => '2026-04-29 22:16:20', 'updated_at' => '2026-04-29 22:16:20'],
                ['task_title' => 'Meal Prep for Class Days', 'recurrence_type' => 'weekly', 'interval' => 1, 'start_datetime' => '2026-05-05 17:30:00', 'end_datetime' => null, 'days_of_week' => '[1]', 'created_at' => '2026-04-29 22:16:30', 'updated_at' => '2026-04-29 22:16:30'],
                ['task_title' => 'Professional Elective 3 Weekly Review Session', 'recurrence_type' => 'weekly', 'interval' => 1, 'start_datetime' => '2026-05-03 19:00:00', 'end_datetime' => null, 'days_of_week' => '[6]', 'created_at' => '2026-04-29 22:16:40', 'updated_at' => '2026-04-29 22:16:40'],
                ['task_title' => 'CS Thesis Writing Draft Improvement Session', 'recurrence_type' => 'weekly', 'interval' => 1, 'start_datetime' => '2026-05-06 15:30:00', 'end_datetime' => null, 'days_of_week' => '[3]', 'created_at' => '2026-04-29 22:16:50', 'updated_at' => '2026-04-29 22:16:50'],
                ['task_title' => 'Final Exam Active Recall Session', 'recurrence_type' => 'weekly', 'interval' => 1, 'start_datetime' => '2026-05-16 20:30:00', 'end_datetime' => null, 'days_of_week' => '[2,4]', 'created_at' => '2026-04-29 22:17:00', 'updated_at' => '2026-04-29 22:17:00'],
            ];

            $events = [
                ['title' => 'Department Research Colloquium', 'description' => 'Attend CS department research presentation and Q&A.', 'start_datetime' => '2026-05-09 14:00:00', 'end_datetime' => '2026-05-09 16:00:00', 'all_day' => 0, 'status' => 'scheduled', 'created_at' => '2026-04-29 22:17:10', 'updated_at' => '2026-04-29 22:17:10', 'deleted_at' => null],
                ['title' => 'Registrar Document Submission', 'description' => 'Submit updated registration and assessment documents.', 'start_datetime' => '2026-05-13 10:00:00', 'end_datetime' => '2026-05-13 11:00:00', 'all_day' => 0, 'status' => 'scheduled', 'created_at' => '2026-04-29 22:17:20', 'updated_at' => '2026-04-29 22:17:20', 'deleted_at' => null],
                ['title' => 'Group Thesis Check-in Meeting', 'description' => 'Weekly team sync for thesis milestones and blockers.', 'start_datetime' => '2026-05-18 18:30:00', 'end_datetime' => '2026-05-18 20:00:00', 'all_day' => 0, 'status' => 'scheduled', 'created_at' => '2026-04-29 22:17:30', 'updated_at' => '2026-04-29 22:17:30', 'deleted_at' => null],
                ['title' => 'Career Center Resume Review', 'description' => 'Resume and portfolio feedback session with adviser.', 'start_datetime' => '2026-05-22 09:30:00', 'end_datetime' => '2026-05-22 10:30:00', 'all_day' => 0, 'status' => 'scheduled', 'created_at' => '2026-04-29 22:17:40', 'updated_at' => '2026-04-29 22:17:40', 'deleted_at' => null],
            ];

            foreach ($recurringTasks as $recurringTask) {
                $taskId = DB::table('tasks')->where('user_id', $userId)->where('title', $recurringTask['task_title'])->value('id');

                if ($taskId === null) {
                    continue;
                }

                DB::table('recurring_tasks')->updateOrInsert(
                    ['task_id' => $taskId],
                    [
                        'recurrence_type' => $recurringTask['recurrence_type'],
                        'interval' => $recurringTask['interval'],
                        'start_datetime' => $recurringTask['start_datetime'],
                        'end_datetime' => $recurringTask['end_datetime'],
                        'days_of_week' => $recurringTask['days_of_week'],
                        'created_at' => $recurringTask['created_at'],
                        'updated_at' => $recurringTask['updated_at'],
                    ]
                );
            }

            foreach ($events as $event) {
                DB::table('events')->updateOrInsert(
                    ['user_id' => $userId, 'title' => $event['title'], 'start_datetime' => $event['start_datetime']],
                    $event + ['user_id' => $userId]
                );
            }

            $completedTaskIdsBySource = DB::table('tasks')
                ->where('user_id', $userId)
                ->whereIn('source_id', [
                    'history-completed-thesis-sweep',
                    'history-completed-sql-practice-a',
                    'history-completed-data-viz-reviewer',
                    'history-completed-api-refactor',
                    'history-completed-statistics-drill',
                    'history-completed-thesis-slides',
                ])
                ->pluck('id', 'source_id')
                ->map(static fn (mixed $value): int => (int) $value)
                ->all();

            $taskMorphClass = (new Task)->getMorphClass();
            $focusSessions = [
                ['source_id' => 'history-completed-thesis-sweep', 'type' => 'work', 'sequence_number' => 1, 'duration_seconds' => 5400, 'completed' => true, 'started_at' => '2026-04-02 14:10:00', 'ended_at' => '2026-04-02 15:35:00', 'paused_seconds' => 300, 'focus_mode_type' => 'single'],
                ['source_id' => 'history-completed-thesis-sweep', 'type' => 'work', 'sequence_number' => 2, 'duration_seconds' => 4800, 'completed' => true, 'started_at' => '2026-04-04 18:20:00', 'ended_at' => '2026-04-04 19:35:00', 'paused_seconds' => 300, 'focus_mode_type' => 'single'],
                ['source_id' => 'history-completed-sql-practice-a', 'type' => 'work', 'sequence_number' => 1, 'duration_seconds' => 4500, 'completed' => true, 'started_at' => '2026-04-05 19:10:00', 'ended_at' => '2026-04-05 20:20:00', 'paused_seconds' => 300, 'focus_mode_type' => 'single'],
                ['source_id' => 'history-completed-data-viz-reviewer', 'type' => 'work', 'sequence_number' => 1, 'duration_seconds' => 3600, 'completed' => true, 'started_at' => '2026-04-06 13:45:00', 'ended_at' => '2026-04-06 14:45:00', 'paused_seconds' => 120, 'focus_mode_type' => 'single'],
                ['source_id' => 'history-completed-api-refactor', 'type' => 'work', 'sequence_number' => 1, 'duration_seconds' => 7200, 'completed' => true, 'started_at' => '2026-04-08 17:45:00', 'ended_at' => '2026-04-08 19:50:00', 'paused_seconds' => 300, 'focus_mode_type' => 'single'],
                ['source_id' => 'history-completed-statistics-drill', 'type' => 'work', 'sequence_number' => 1, 'duration_seconds' => 5400, 'completed' => true, 'started_at' => '2026-04-09 09:30:00', 'ended_at' => '2026-04-09 10:50:00', 'paused_seconds' => 240, 'focus_mode_type' => 'single'],
                ['source_id' => 'history-completed-thesis-slides', 'type' => 'work', 'sequence_number' => 1, 'duration_seconds' => 4800, 'completed' => true, 'started_at' => '2026-04-11 18:55:00', 'ended_at' => '2026-04-11 20:15:00', 'paused_seconds' => 300, 'focus_mode_type' => 'single'],
                ['source_id' => 'history-completed-data-viz-reviewer', 'type' => 'work', 'sequence_number' => 2, 'duration_seconds' => 4200, 'completed' => true, 'started_at' => '2026-04-14 14:25:00', 'ended_at' => '2026-04-14 15:35:00', 'paused_seconds' => 180, 'focus_mode_type' => 'single'],
                ['source_id' => 'history-completed-api-refactor', 'type' => 'work', 'sequence_number' => 2, 'duration_seconds' => 4500, 'completed' => true, 'started_at' => '2026-04-16 20:05:00', 'ended_at' => '2026-04-16 21:20:00', 'paused_seconds' => 300, 'focus_mode_type' => 'single'],
                ['source_id' => 'history-completed-statistics-drill', 'type' => 'work', 'sequence_number' => 2, 'duration_seconds' => 3900, 'completed' => true, 'started_at' => '2026-04-20 13:55:00', 'ended_at' => '2026-04-20 15:00:00', 'paused_seconds' => 120, 'focus_mode_type' => 'single'],
                ['source_id' => 'history-completed-thesis-sweep', 'type' => 'work', 'sequence_number' => 3, 'duration_seconds' => 4800, 'completed' => true, 'started_at' => '2026-04-21 19:15:00', 'ended_at' => '2026-04-21 20:30:00', 'paused_seconds' => 300, 'focus_mode_type' => 'single'],
                ['source_id' => 'history-completed-sql-practice-a', 'type' => 'work', 'sequence_number' => 2, 'duration_seconds' => 3600, 'completed' => true, 'started_at' => '2026-04-23 10:20:00', 'ended_at' => '2026-04-23 11:15:00', 'paused_seconds' => 120, 'focus_mode_type' => 'single'],
                ['source_id' => 'history-completed-thesis-sweep', 'type' => 'short_break', 'sequence_number' => 1, 'duration_seconds' => 1800, 'completed' => true, 'started_at' => '2026-04-02 12:20:00', 'ended_at' => '2026-04-02 12:45:00', 'paused_seconds' => 0, 'focus_mode_type' => null],
                ['source_id' => 'history-completed-data-viz-reviewer', 'type' => 'short_break', 'sequence_number' => 1, 'duration_seconds' => 1500, 'completed' => true, 'started_at' => '2026-04-06 12:40:00', 'ended_at' => '2026-04-06 13:00:00', 'paused_seconds' => 0, 'focus_mode_type' => null],
                ['source_id' => 'history-completed-api-refactor', 'type' => 'long_break', 'sequence_number' => 1, 'duration_seconds' => 2400, 'completed' => true, 'started_at' => '2026-04-08 13:10:00', 'ended_at' => '2026-04-08 13:45:00', 'paused_seconds' => 0, 'focus_mode_type' => null],
                ['source_id' => 'history-completed-statistics-drill', 'type' => 'short_break', 'sequence_number' => 1, 'duration_seconds' => 1800, 'completed' => true, 'started_at' => '2026-04-09 12:35:00', 'ended_at' => '2026-04-09 13:00:00', 'paused_seconds' => 0, 'focus_mode_type' => null],
                ['source_id' => 'history-completed-thesis-slides', 'type' => 'long_break', 'sequence_number' => 1, 'duration_seconds' => 2100, 'completed' => true, 'started_at' => '2026-04-11 12:50:00', 'ended_at' => '2026-04-11 13:20:00', 'paused_seconds' => 0, 'focus_mode_type' => null],
                ['source_id' => 'history-completed-sql-practice-a', 'type' => 'short_break', 'sequence_number' => 1, 'duration_seconds' => 1800, 'completed' => true, 'started_at' => '2026-04-23 12:15:00', 'ended_at' => '2026-04-23 12:40:00', 'paused_seconds' => 0, 'focus_mode_type' => null],
            ];

            foreach ($focusSessions as $focusSession) {
                $taskId = $completedTaskIdsBySource[$focusSession['source_id']] ?? null;
                if (! is_int($taskId) || $taskId <= 0) {
                    continue;
                }

                DB::table('focus_sessions')->insert([
                    'user_id' => $userId,
                    'focusable_type' => $taskMorphClass,
                    'focusable_id' => $taskId,
                    'type' => $focusSession['type'],
                    'focus_mode_type' => $focusSession['focus_mode_type'],
                    'sequence_number' => $focusSession['sequence_number'],
                    'duration_seconds' => $focusSession['duration_seconds'],
                    'completed' => $focusSession['completed'],
                    'started_at' => $focusSession['started_at'],
                    'ended_at' => $focusSession['ended_at'],
                    'paused_seconds' => $focusSession['paused_seconds'],
                    'paused_at' => null,
                    'payload' => null,
                    'created_at' => $focusSession['ended_at'],
                    'updated_at' => $focusSession['ended_at'],
                ]);
            }
        });
    }
}
