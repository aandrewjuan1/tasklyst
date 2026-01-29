<?php

namespace Database\Seeders;

use App\Enums\EventRecurrenceType;
use App\Enums\EventStatus;
use App\Enums\TaskComplexity;
use App\Enums\TaskPriority;
use App\Enums\TaskRecurrenceType;
use App\Enums\TaskStatus;
use App\Models\Event;
use App\Models\RecurringEvent;
use App\Models\RecurringTask;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class FakeDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::where('email', 'andrew.juan.cvt@eac.edu.ph')->firstOrFail();

        $currentYear = Carbon::now()->year;

        // Create tags
        $artTag = Tag::firstOrCreate(['user_id' => $user->id, 'name' => 'art']);
        $creativeTag = Tag::firstOrCreate(['user_id' => $user->id, 'name' => 'creative']);
        $healthTag = Tag::firstOrCreate(['user_id' => $user->id, 'name' => 'health']);
        $exerciseTag = Tag::firstOrCreate(['user_id' => $user->id, 'name' => 'exercise']);
        $financeTag = Tag::firstOrCreate(['user_id' => $user->id, 'name' => 'finance']);
        $workTag = Tag::firstOrCreate(['user_id' => $user->id, 'name' => 'work']);
        $learningTag = Tag::firstOrCreate(['user_id' => $user->id, 'name' => 'learning']);
        $personalTag = Tag::firstOrCreate(['user_id' => $user->id, 'name' => 'personal']);

        // Create recurring yearly events (birthdays)
        $this->createBirthdayEvent($user, 'My Birthday', $currentYear, 1, 31);
        $this->createBirthdayEvent($user, "Girlfriend's Birthday", $currentYear, 6, 16);

        // Create recurring daily tasks with tags
        $this->createDailyTask($user, 'Drawing', 60, null, null, [$artTag, $creativeTag]);
        $this->createDailyTask($user, 'Walking', 120, null, null, [$healthTag, $exerciseTag]);
        $this->createDailyTask($user, 'Day Trading', 480, Carbon::today()->setTime(15, 0), Carbon::today()->setTime(23, 0), [$financeTag, $workTag]);
        $this->createDailyTask($user, 'Read', 60, null, null, [$learningTag, $personalTag]);
        $this->createDailyTask($user, 'Workout', 120, null, null, [$healthTag, $exerciseTag]);
    }

    /**
     * Create a recurring yearly birthday event.
     */
    private function createBirthdayEvent(User $user, string $title, int $year, int $month, int $day): void
    {
        $birthdayDate = Carbon::create($year, $month, $day);

        // If the birthday has already passed this year, use next year's date
        if ($birthdayDate->isPast()) {
            $birthdayDate->addYear();
        }

        $event = Event::create([
            'user_id' => $user->id,
            'title' => $title,
            'description' => null,
            'start_datetime' => $birthdayDate->copy()->startOfDay(),
            'end_datetime' => $birthdayDate->copy()->endOfDay(),
            'all_day' => true,
            'timezone' => null,
            'location' => null,
            'color' => null,
            'status' => EventStatus::Scheduled,
        ]);

        RecurringEvent::create([
            'event_id' => $event->id,
            'recurrence_type' => EventRecurrenceType::Yearly,
            'interval' => 1,
            'days_of_week' => null,
            'start_datetime' => $birthdayDate->copy()->startOfDay(),
            'end_datetime' => null,
            'timezone' => null,
        ]);
    }

    /**
     * Create a recurring daily task.
     */
    private function createDailyTask(User $user, string $title, int $durationMinutes, ?Carbon $startTime, ?Carbon $endTime, array $tags = []): void
    {
        $taskStartDatetime = $startTime ? $startTime->copy() : null;
        $taskEndDatetime = $endTime ? $endTime->copy() : null;

        $task = Task::create([
            'user_id' => $user->id,
            'title' => $title,
            'description' => null,
            'status' => TaskStatus::ToDo,
            'priority' => TaskPriority::Medium,
            'complexity' => TaskComplexity::Moderate,
            'duration' => $durationMinutes,
            'start_datetime' => $taskStartDatetime,
            'end_datetime' => $taskEndDatetime,
            'project_id' => null,
            'event_id' => null,
            'completed_at' => null,
        ]);

        RecurringTask::create([
            'task_id' => $task->id,
            'recurrence_type' => TaskRecurrenceType::Daily,
            'interval' => 1,
            'start_datetime' => Carbon::today(),
            'end_datetime' => null,
            'days_of_week' => null,
        ]);

        // Attach tags to the task
        if (! empty($tags)) {
            $task->tags()->attach(collect($tags)->pluck('id')->toArray());
        }
    }
}
