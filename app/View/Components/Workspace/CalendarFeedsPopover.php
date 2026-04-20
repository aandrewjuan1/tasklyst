<?php

namespace App\View\Components\Workspace;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;

class CalendarFeedsPopover extends Component
{
    public int $importPastMonths;

    /**
     * @var list<int>
     */
    public array $importPastChoices;

    /**
     * @var array<string, string>
     */
    public array $importPastMonthLabels;

    /**
     * @var array<string, mixed>
     */
    public array $alpineBootstrap;

    public function __construct()
    {
        /** @var list<int|string> $raw */
        $raw = config('calendar_feeds.allowed_import_past_months', [1, 3, 6]);
        $this->importPastChoices = array_values(array_map(static fn (mixed $v): int => (int) $v, $raw));

        $labels = [];
        foreach ($this->importPastChoices as $m) {
            $labels[(string) $m] = $m === 1
                ? __('1 month')
                : __(':count months', ['count' => $m]);
        }
        $this->importPastMonthLabels = $labels;

        $user = Auth::user();
        $this->importPastMonths = $user instanceof User
            ? $user->resolvedCalendarImportPastMonths()
            : (int) config('calendar_feeds.default_import_past_months');

        $this->alpineBootstrap = [
            'importPastMonths' => $this->importPastMonths,
            'importPastMonthsSaved' => $this->importPastMonths,
            'importPastChoices' => $this->importPastChoices,
            'importPastMonthLabels' => $this->importPastMonthLabels,
            'strings' => [
                'pleaseEnterBrightspaceUrl' => __('Please enter your Brightspace calendar URL.'),
                'useBrightspaceSubscribeUrl' => __('Please use a Brightspace calendar link that starts with https://eac.brightspace.com/d2l/le/calendar/feed/user/feed.ics'),
                'connectingCalendar' => __('Connecting your calendar…'),
                'couldNotConnectFeed' => __('Couldn’t connect the calendar feed. Try again.'),
            ],
        ];
    }

    public function render(): View
    {
        return view('components.workspace.calendar-feeds-popover');
    }
}
