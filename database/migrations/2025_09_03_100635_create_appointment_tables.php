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
        // Create Appointment table
        Schema::create('Appointment', function (Blueprint $table) {
            $table->id('AppointmentID');
            $table->unsignedBigInteger('UserID');
            $table->unsignedBigInteger('ChurchID');
            $table->unsignedBigInteger('ServiceID');
            $table->datetime('AppointmentDate');
            $table->enum('Status', ['Pending', 'Approved', 'Rejected', 'Cancelled', 'Completed'])->default('Pending');
            $table->text('Notes')->nullable();
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('UserID')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('ChurchID')->references('ChurchID')->on('Church')->onDelete('cascade');
            $table->foreign('ServiceID')->references('ServiceID')->on('sacrament_service')->onDelete('cascade');

            // Indexes for better performance
            $table->index(['UserID', 'Status']);
            $table->index(['ChurchID', 'AppointmentDate']);
            $table->index(['ServiceID']);
        });

        // Create AppointmentInputAnswer table
        Schema::create('AppointmentInputAnswer', function (Blueprint $table) {
            $table->id('AnswerID');
            $table->unsignedBigInteger('AppointmentID');
            $table->unsignedBigInteger('InputFieldID');
            $table->text('AnswerText')->nullable();
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('AppointmentID')->references('AppointmentID')->on('Appointment')->onDelete('cascade');
            $table->foreign('InputFieldID')->references('InputFieldID')->on('service_input_field')->onDelete('cascade');

            // Unique constraint to ensure one answer per field per appointment
            $table->unique(['AppointmentID', 'InputFieldID']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('AppointmentInputAnswer');
        Schema::dropIfExists('Appointment');
    }
};
