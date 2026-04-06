<?php

use App\Data\Analytics\DashboardAnalyticsOverview;
use App\Services\UserAnalyticsService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

new
#[Title('Dashboard')]
class extends Component
{
    #[Url(as: 'preset')]
    public string $analyticsPreset = '30d';

    protected UserAnalyticsService $userAnalyticsService;

    public function boot(UserAnalyticsService $userAnalyticsService): void
    {
        $this->userAnalyticsService = $userAnalyticsService;
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
};