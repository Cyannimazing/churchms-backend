<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_sub_service_status', function (Blueprint $table) {
            $table->id('StatusID');
            $table->unsignedBigInteger('AppointmentID');
            $table->unsignedBigInteger('SubServiceID');
            $table->boolean('isCompleted')->default(false);
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamps();

            $table->foreign('AppointmentID')->references('AppointmentID')->on('Appointment')->onDelete('cascade');
            $table->foreign('SubServiceID')->references('SubServiceID')->on('sub_service')->onDelete('cascade');
            $table->foreign('reviewed_by')->references('id')->on('users')->onDelete('set null');

            $table->unique(['AppointmentID','SubServiceID']);
            $table->index(['AppointmentID','SubServiceID']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_sub_service_status');
    }
};
