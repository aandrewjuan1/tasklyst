<?php

use App\Actions\Workspace\AlignWorkspaceForScheduledPlanItemAction;
use App\Models\AssistantSchedulePlanItem;
use Carbon\Carbon;

test('align action reports date change when current differs from planned', function (): void {
    $item = AssistantSchedulePlanItem::make([
        'planned_start_at' => Carbon::parse('2026-04-16 10:00:00', config('app.timezone')),
    ]);

    $result = app(AlignWorkspaceForScheduledPlanItemAction::class)->execute($item, '2026-04-15');

    expect($result['date_changed'])->toBeTrue()
        ->and($result['new_date'])->toBe('2026-04-16');
});

test('align action reports no date change when current matches planned day', function (): void {
    $item = AssistantSchedulePlanItem::make([
        'planned_start_at' => Carbon::parse('2026-04-16 10:00:00', config('app.timezone')),
    ]);

    $result = app(AlignWorkspaceForScheduledPlanItemAction::class)->execute($item, '2026-04-16');

    expect($result['date_changed'])->toBeFalse()
        ->and($result['new_date'])->toBe('2026-04-16');
});

test('align action returns null new date when planned start missing', function (): void {
    $item = AssistantSchedulePlanItem::make([
        'planned_start_at' => null,
    ]);

    $result = app(AlignWorkspaceForScheduledPlanItemAction::class)->execute($item, '2026-04-15');

    expect($result['new_date'])->toBeNull()
        ->and($result['date_changed'])->toBeFalse();
});
