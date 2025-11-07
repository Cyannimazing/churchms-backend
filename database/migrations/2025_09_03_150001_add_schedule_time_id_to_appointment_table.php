<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('Appointment', function (Blueprint $table) {
            $table->unsignedBigInteger('ScheduleTimeID')->nullable()->after('ScheduleID');
            $table->foreign('ScheduleTimeID')->references('ScheduleTimeID')->on('schedule_times')->onDelete('set null');
            $table->index(['ScheduleTimeID']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Appointment', function (Blueprint $table) {
            $table->dropForeign(['ScheduleTimeID']);
            $table->dropIndex(['ScheduleTimeID']);
            $table->dropColumn('ScheduleTimeID');
        });
    }
};
