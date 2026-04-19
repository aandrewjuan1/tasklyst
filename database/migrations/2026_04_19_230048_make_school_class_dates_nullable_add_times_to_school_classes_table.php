<?php

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
        Schema::table('school_classes', function (Blueprint $table) {
            $table->time('start_time')->nullable()->after('teacher_id');
            $table->time('end_time')->nullable()->after('start_time');
        });

        $rows = DB::table('school_classes')
            ->select('id', 'start_datetime', 'end_datetime')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $start = Carbon::parse((string) $row->start_datetime)->format('H:i:s');
            $end = Carbon::parse((string) $row->end_datetime)->format('H:i:s');

            DB::table('school_classes')
                ->where('id', $row->id)
                ->update([
                    'start_time' => $start,
                    'end_time' => $end,
                ]);
        }

        Schema::table('school_classes', function (Blueprint $table) {
            $table->time('start_time')->nullable(false)->change();
            $table->time('end_time')->nullable(false)->change();
            $table->dateTime('start_datetime')->nullable()->change();
            $table->dateTime('end_datetime')->nullable()->change();
            $table->index(['user_id', 'start_time', 'end_time'], 'school_classes_user_start_end_time_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $rows = DB::table('school_classes')
            ->select('id', 'created_at', 'start_datetime', 'end_datetime', 'start_time', 'end_time')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $anchorDate = $row->created_at !== null
                ? Carbon::parse((string) $row->created_at)->toDateString()
                : now()->toDateString();

            $startDatetime = $row->start_datetime !== null
                ? Carbon::parse((string) $row->start_datetime)
                : Carbon::parse($anchorDate.' '.((string) $row->start_time));

            $endDatetime = $row->end_datetime !== null
                ? Carbon::parse((string) $row->end_datetime)
                : Carbon::parse($anchorDate.' '.((string) $row->end_time));

            DB::table('school_classes')
                ->where('id', $row->id)
                ->update([
                    'start_datetime' => $startDatetime,
                    'end_datetime' => $endDatetime,
                ]);
        }

        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropIndex('school_classes_user_start_end_time_index');
            $table->dateTime('start_datetime')->nullable(false)->change();
            $table->dateTime('end_datetime')->nullable(false)->change();
            $table->dropColumn(['start_time', 'end_time']);
        });
    }
};
