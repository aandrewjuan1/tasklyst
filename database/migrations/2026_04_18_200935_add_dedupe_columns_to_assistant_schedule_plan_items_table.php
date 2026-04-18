<?php

use App\Enums\AssistantSchedulePlanItemStatus;
use App\Models\AssistantSchedulePlanItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('assistant_schedule_plan_items', function (Blueprint $table) {
            $table->date('planned_day')->nullable()->after('planned_duration_minutes');
            $table->string('dedupe_key', 128)->nullable()->after('planned_day');
            $table->string('active_dedupe_unique', 128)->nullable()->after('dedupe_key');
        });

        $timezone = (string) config('app.timezone', 'UTC');

        foreach (AssistantSchedulePlanItem::query()->cursor() as $item) {
            if ($item->planned_start_at === null) {
                continue;
            }

            $day = Carbon::parse($item->planned_start_at)->setTimezone($timezone)->toDateString();
            $dedupeKey = AssistantSchedulePlanItem::buildDedupeKey(
                (int) $item->user_id,
                (string) $item->entity_type,
                (int) $item->entity_id,
                $day,
            );

            $active = in_array($item->status, [
                AssistantSchedulePlanItemStatus::Planned,
                AssistantSchedulePlanItemStatus::InProgress,
            ], true) ? $dedupeKey : null;

            DB::table('assistant_schedule_plan_items')->where('id', $item->id)->update([
                'planned_day' => $day,
                'dedupe_key' => $dedupeKey,
                'active_dedupe_unique' => $active,
                'updated_at' => $item->updated_at,
            ]);
        }

        $activeStatuses = [
            AssistantSchedulePlanItemStatus::Planned->value,
            AssistantSchedulePlanItemStatus::InProgress->value,
        ];

        $duplicateKeys = DB::table('assistant_schedule_plan_items')
            ->select('user_id', 'dedupe_key')
            ->whereIn('status', $activeStatuses)
            ->whereNotNull('dedupe_key')
            ->groupBy('user_id', 'dedupe_key')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateKeys as $row) {
            $ids = AssistantSchedulePlanItem::query()
                ->where('user_id', $row->user_id)
                ->where('dedupe_key', $row->dedupe_key)
                ->whereIn('status', [
                    AssistantSchedulePlanItemStatus::Planned,
                    AssistantSchedulePlanItemStatus::InProgress,
                ])
                ->orderByDesc('id')
                ->pluck('id')
                ->all();

            $keepId = array_shift($ids);
            foreach ($ids as $dismissId) {
                $metadata = AssistantSchedulePlanItem::query()->whereKey($dismissId)->value('metadata');
                $meta = is_array($metadata) ? $metadata : [];
                data_set($meta, 'cleanup_reason', 'dedupe_backfill');
                data_set($meta, 'cleanup_dismissed_at', now()->toIso8601String());

                DB::table('assistant_schedule_plan_items')->where('id', $dismissId)->update([
                    'status' => AssistantSchedulePlanItemStatus::Dismissed->value,
                    'dismissed_at' => now(),
                    'active_dedupe_unique' => null,
                    'metadata' => json_encode($meta),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::table('assistant_schedule_plan_items', function (Blueprint $table) {
            $table->unique(['user_id', 'active_dedupe_unique'], 'aspi_user_active_dedupe_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assistant_schedule_plan_items', function (Blueprint $table) {
            $table->dropUnique('aspi_user_active_dedupe_unique');
        });

        Schema::table('assistant_schedule_plan_items', function (Blueprint $table) {
            $table->dropColumn(['planned_day', 'dedupe_key', 'active_dedupe_unique']);
        });
    }
};
