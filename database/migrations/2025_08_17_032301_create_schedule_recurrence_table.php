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
        Schema::create('schedule_recurrences', function (Blueprint $table) {
            $table->id('RecurrenceID');
            $table->unsignedBigInteger('ScheduleID');
            $table->enum('RecurrenceType', ['Weekly', 'MonthlyNth', 'OneTime']);
            $table->integer('DayOfWeek')->nullable()->comment('0=Sunday, 1=Monday, ..., 6=Saturday');
            $table->integer('WeekOfMonth')->nullable()->comment('1=First week, 2=Second week, ..., 5=Fifth week');
            $table->date('SpecificDate')->nullable()->comment('For OneTime recurrence');
            $table->timestamps();
            
            $table->foreign('ScheduleID')->references('ScheduleID')->on('service_schedules')->onDelete('cascade');
            
            $table->index(['ScheduleID']);
            $table->index(['RecurrenceType']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_recurrences');
    }
};
