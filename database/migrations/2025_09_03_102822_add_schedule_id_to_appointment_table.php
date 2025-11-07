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
            $table->unsignedBigInteger('ScheduleID')->nullable()->after('ServiceID');
            $table->foreign('ScheduleID')->references('ScheduleID')->on('service_schedules')->onDelete('set null');
            $table->index(['ScheduleID']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('Appointment', function (Blueprint $table) {
            $table->dropForeign(['ScheduleID']);
            $table->dropIndex(['ScheduleID']);
            $table->dropColumn('ScheduleID');
        });
    }
};
