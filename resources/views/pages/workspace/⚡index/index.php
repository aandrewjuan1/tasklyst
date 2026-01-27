<?php

use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Workspace')]
class extends Component
{
    public string $selectedDate;

    public function mount(): void
    {
        $this->selectedDate = now()->toDateString();
    }

    public function goToToday(): void
    {
        $this->selectedDate = now()->toDateString();
    }

    public function goToPreviousDay(): void
    {
        $this->selectedDate = Carbon::parse($this->selectedDate)->subDay()->toDateString();
    }

    public function goToNextDay(): void
    {
        $this->selectedDate = Carbon::parse($this->selectedDate)->addDay()->toDateString();
    }

    /**
     * Get tasks for the selected date for the authenticated user.
     */
    #[Computed]
    public function tasks(): Collection
    {
        $userId = auth()->id();

        if ($userId === null) {
            return collect();
        }

        $date = Carbon::parse($this->selectedDate);

        return Task::query()
            ->with([
                'project',
                'event',
                'recurringTask',
                'tags',
                'collaborations',
            ])
            ->forUser($userId)
            ->incomplete()
            ->relevantForDate($date)
            ->orderBy('start_datetime')
            ->limit(50)
            ->get();
    }

    /**
     * Get projects for the selected date for the authenticated user.
     */
    #[Computed]
    public function projects(): Collection
    {
        $userId = auth()->id();

        if ($userId === null) {
            return collect();
        }

        $date = Carbon::parse($this->selectedDate);

        return Project::query()
            ->with([
                'tasks',
                'collaborations',
            ])
            ->forUser($userId)
            ->notArchived()
            ->activeForDate($date)
            ->orderBy('start_datetime')
            ->limit(50)
            ->get();
    }

    /**
     * Get events for the selected date for the authenticated user.
     */
    #[Computed]
    public function events(): Collection
    {
        $userId = auth()->id();

        if ($userId === null) {
            return collect();
        }

        $date = Carbon::parse($this->selectedDate);

        return Event::query()
            ->with([
                'recurringEvent',
                'collaborations',
            ])
            ->forUser($userId)
            ->activeForDate($date)
            ->orderBy('start_datetime')
            ->limit(50)
            ->get();
    }
};
