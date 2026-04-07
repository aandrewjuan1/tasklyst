<?php

use App\Data\Analytics\DashboardAnalyticsOverview;
use App\Models\Event;
use App\Models\Project;
use App\Models\Task;
use App\Services\UserAnalyticsService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

new
#[Title('Dashboard')]
class extends Component
{
    #[Url(as: 'date')]
    public ?string $selectedDate = null;

    #[Url(as: 'preset')]
    public string $analyticsPreset = '30d';

    /**
     * Cached parsed date to avoid parsing multiple times.
     * Cleared when selectedDate changes.
     */
    protected ?CarbonInterface $parsedSelectedDate = null;

    protected UserAnalyticsService $userAnalyticsService;

    public function boot(UserAnalyticsService $userAnalyticsService): void
    {
        $this->userAnalyticsService = $userAnalyticsService;
    }

    public function mount(): void
    {
        if ($this->selectedDate === null || $this->selectedDate === '' || strtotime($this->selectedDate) === false) {
            $this->selectedDate = now()->toDateString();
        }
    }

    public function updatedSelectedDate(): void
    {
        $this->parsedSelectedDate = null;
    }

    #[Computed]
    public function analytics(): ?DashboardAnalyticsOverview
    {
        $user = Auth::user();
        if ($user === null) {
            return null;
        }

        return $this->userAnalyticsService->dashboardOverview(
            user: $user,
            preset: $this->analyticsPreset,
            anchor: $this->analyticsAnchor(),
        );
    }

    private function analyticsAnchor(): CarbonInterface
    {
        return now();
    }

    #[Computed]
    public function calendarMonth(): int
    {
        return $this->getParsedSelectedDate()->month;
    }

    #[Computed]
    public function calendarYear(): int
    {
        return $this->getParsedSelectedDate()->year;
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{kind: string, item: mixed}>
     */
    #[Computed]
    public function upcoming(): Collection
    {
        $userId = Auth::id();

        if ($userId === null) {
            return collect();
        }

        $fromDate = now()->startOfDay();
        $days = 7;

        $entries = collect();

        $upcomingTasks = Task::query()
            ->forUser($userId)
            ->dueSoon($fromDate, $days)
            ->whereDoesntHave('recurringTask')
            ->orderBy('end_datetime')
            ->limit(50)
            ->get()
            ->map(fn (Task $task) => ['kind' => 'task', 'item' => $task]);

        $entries = $entries->merge($upcomingTasks);

        $upcomingEvents = Event::query()
            ->forUser($userId)
            ->startingSoon($fromDate, $days)
            ->whereDoesntHave('recurringEvent')
            ->notCancelled()
            ->orderBy('start_datetime')
            ->limit(50)
            ->get()
            ->map(fn (Event $event) => ['kind' => 'event', 'item' => $event]);

        $entries = $entries->merge($upcomingEvents);

        $upcomingProjects = Project::query()
            ->forUser($userId)
            ->startingSoon($fromDate, $days)
            ->notArchived()
            ->orderBy('start_datetime')
            ->limit(50)
            ->get()
            ->map(fn (Project $project) => ['kind' => 'project', 'item' => $project]);

        $entries = $entries->merge($upcomingProjects);

        return $entries
            ->sortBy(function (array $entry): int {
                /** @var \App\Models\Task|\App\Models\Event|\App\Models\Project $item */
                $item = $entry['item'];

                return match ($entry['kind']) {
                    'task' => $item->end_datetime?->timestamp ?? PHP_INT_MAX,
                    'event', 'project' => $item->start_datetime?->timestamp ?? PHP_INT_MAX,
                    default => PHP_INT_MAX,
                };
            })
            ->values();
    }

    private function getParsedSelectedDate(): CarbonInterface
    {
        if ($this->parsedSelectedDate === null) {
            $this->parsedSelectedDate = \Carbon\Carbon::parse($this->selectedDate);
        }

        return $this->parsedSelectedDate;
    }
};