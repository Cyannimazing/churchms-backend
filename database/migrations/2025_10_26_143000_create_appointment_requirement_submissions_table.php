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
        Schema::create('appointment_requirement_submissions', function (Blueprint $table) {
            $table->id('SubmissionID');
            $table->unsignedBigInteger('AppointmentID');
            $table->unsignedBigInteger('RequirementID');
            $table->boolean('isSubmitted')->default(false);
            $table->text('notes')->nullable(); // Optional notes about the submission
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable(); // Staff member who reviewed
            $table->timestamps();
            
            // Foreign key constraints
            $table->foreign('AppointmentID')->references('AppointmentID')->on('Appointment')->onDelete('cascade');
            $table->foreign('RequirementID')->references('RequirementID')->on('service_requirement')->onDelete('cascade');
            $table->foreign('reviewed_by')->references('id')->on('users')->onDelete('set null');
            
            // Unique constraint to prevent duplicate entries
            $table->unique(['AppointmentID', 'RequirementID'], 'appt_req_submission_unique');
            
            // Indexes for better performance
            $table->index('AppointmentID');
            $table->index('RequirementID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointment_requirement_submissions');
    }
};
