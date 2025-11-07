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
        Schema::dropIfExists('schedule_fees');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('schedule_fees', function (Blueprint $table) {
            $table->id('ScheduleFeeID');
            $table->unsignedBigInteger('ScheduleID');
            $table->enum('FeeType', ['Fee', 'Donation']);
            $table->decimal('Fee', 10, 2);
            $table->timestamps();
            
            $table->foreign('ScheduleID')->references('ScheduleID')->on('service_schedules')->onDelete('cascade');
            
            $table->index(['ScheduleID']);
            $table->index(['FeeType']);
        });
    }
};
