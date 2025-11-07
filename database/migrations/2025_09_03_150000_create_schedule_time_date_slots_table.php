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
        Schema::create('schedule_time_date_slots', function (Blueprint $table) {
            $table->id('ScheduleTimeDateSlotID');
            $table->unsignedBigInteger('ScheduleTimeID');
            $table->date('SlotDate'); // Specific date for this time slot
            $table->integer('RemainingSlots'); // Slots remaining for this specific ScheduleTimeID on this date
            $table->timestamps();
            
            $table->foreign('ScheduleTimeID')->references('ScheduleTimeID')->on('schedule_times')->onDelete('cascade');
            
            // Unique constraint to prevent duplicate entries for same ScheduleTimeID + date
            $table->unique(['ScheduleTimeID', 'SlotDate']);
            
            $table->index(['ScheduleTimeID']);
            $table->index(['SlotDate']);
            $table->index(['ScheduleTimeID', 'SlotDate', 'RemainingSlots'], 'idx_schedule_time_slots');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_time_date_slots');
    }
};
