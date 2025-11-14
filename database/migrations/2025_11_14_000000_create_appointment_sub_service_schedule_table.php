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
        Schema::create('appointment_sub_service_schedule', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('AppointmentID');
            $table->unsignedBigInteger('SubServiceID');
            $table->date('ScheduledDate');
            $table->time('StartTime')->nullable();
            $table->time('EndTime')->nullable();
            $table->timestamps();

            $table->foreign('AppointmentID')
                ->references('AppointmentID')
                ->on('Appointment')
                ->onDelete('cascade');

            $table->foreign('SubServiceID')
                ->references('SubServiceID')
                ->on('sub_service')
                ->onDelete('cascade');

            // Use a short custom index name to avoid MySQL length limits
            $table->index(['AppointmentID', 'SubServiceID'], 'appt_subsvc_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointment_sub_service_schedule');
    }
};
