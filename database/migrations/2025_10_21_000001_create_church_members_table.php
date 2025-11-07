<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('church_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_id')->constrained('Church', 'ChurchID')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            
            // Parish Registration Info
            $table->string('first_name');
            $table->string('middle_initial', 1)->nullable();
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('contact_number')->nullable();
            $table->string('street_address');
            $table->string('city');
            $table->string('province');
            $table->string('postal_code')->nullable();
            $table->string('barangay')->nullable();
            $table->enum('financial_support', ['Weekly Collection', 'Monthly Envelope', 'Bank Transfer', 'GCash/PayMaya'])->nullable();
            
            // Head of House
            $table->string('head_first_name');
            $table->string('head_middle_initial', 1)->nullable();
            $table->string('head_last_name');
            $table->date('head_date_of_birth');
            $table->string('head_phone_number');
            $table->string('head_email_address');
            $table->string('head_religion');
            $table->boolean('head_baptism')->default(false);
            $table->boolean('head_first_eucharist')->default(false);
            $table->boolean('head_confirmation')->default(false);
            $table->enum('head_marital_status', ['Single', 'Married', 'Widowed', 'Divorced']);
            $table->boolean('head_catholic_marriage');
            
            // Spouse (nullable fields)
            $table->string('spouse_first_name')->nullable();
            $table->string('spouse_middle_initial', 1)->nullable();
            $table->string('spouse_last_name')->nullable();
            $table->date('spouse_date_of_birth')->nullable();
            $table->string('spouse_phone_number')->nullable();
            $table->string('spouse_email_address')->nullable();
            $table->string('spouse_religion')->nullable();
            $table->boolean('spouse_baptism')->default(false);
            $table->boolean('spouse_first_eucharist')->default(false);
            $table->boolean('spouse_confirmation')->default(false);
            $table->enum('spouse_marital_status', ['Single', 'Married', 'Widowed', 'Divorced'])->nullable();
            $table->boolean('spouse_catholic_marriage')->nullable();
            
            // About Yourself
            $table->text('talent_to_share')->nullable();
            $table->text('interested_ministry')->nullable();
            $table->text('parish_help_needed')->nullable();
            $table->boolean('homebound_special_needs')->default(false);
            $table->string('other_languages')->nullable();
            $table->string('ethnicity')->nullable();
            
            // Application Status
            $table->enum('status', ['pending', 'approved', 'rejected', 'away'])->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->text('notes')->nullable();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('church_members');
    }
};
