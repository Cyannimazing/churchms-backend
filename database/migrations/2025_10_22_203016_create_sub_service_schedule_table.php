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
        Schema::create('sub_service_schedule', function (Blueprint $table) {
            $table->id('ScheduleID');
            $table->unsignedBigInteger('SubServiceID');
            $table->enum('DayOfWeek', ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']);
            $table->time('StartTime');
            $table->time('EndTime');
            $table->enum('OccurrenceType', ['weekly', 'nth_day_of_month']);
            $table->integer('OccurrenceValue')->nullable()->comment('For nth_day_of_month: 1=1st, 2=2nd, 3=3rd, 4=4th, -1=last');
            $table->timestamps();
            
            // Foreign key constraint
            $table->foreign('SubServiceID')->references('SubServiceID')->on('sub_service')->onDelete('cascade');
            
            // Index for better query performance
            $table->index('SubServiceID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sub_service_schedule');
    }
};
