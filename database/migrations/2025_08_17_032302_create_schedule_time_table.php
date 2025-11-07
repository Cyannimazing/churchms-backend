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
        Schema::create('schedule_times', function (Blueprint $table) {
            $table->id('ScheduleTimeID');
            $table->unsignedBigInteger('ScheduleID');
            $table->time('StartTime');
            $table->time('EndTime');
            $table->timestamps();
            
            $table->foreign('ScheduleID')->references('ScheduleID')->on('service_schedules')->onDelete('cascade');
            
            $table->index(['ScheduleID']);
            $table->index(['StartTime', 'EndTime']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_times');
    }
};
