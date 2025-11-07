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
        Schema::table('church_transactions', function (Blueprint $table) {
            // Add missing columns from appointment_payment_sessions
            $table->unsignedBigInteger('service_id')->nullable()->after('church_id');
            $table->unsignedBigInteger('schedule_id')->nullable()->after('service_id');
            $table->unsignedBigInteger('schedule_time_id')->nullable()->after('schedule_id');
            $table->string('status')->default('pending')->after('transaction_type'); // pending, paid, failed, cancelled
            $table->string('checkout_url')->nullable()->after('status');
            $table->datetime('appointment_date')->nullable()->after('checkout_url');
            $table->timestamp('expires_at')->nullable()->after('appointment_date');
            
            // Add indexes
            $table->index(['user_id', 'status']);
            $table->index(['church_id', 'status']);
        });
        
        // Drop the appointment_payment_sessions table
        Schema::dropIfExists('appointment_payment_sessions');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate appointment_payment_sessions table
        Schema::create('appointment_payment_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('church_id');
            $table->unsignedBigInteger('service_id');
            $table->unsignedBigInteger('schedule_id');
            $table->unsignedBigInteger('schedule_time_id');
            $table->string('paymongo_session_id')->unique();
            $table->string('payment_method')->default('gcash');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('PHP');
            $table->string('status')->default('pending');
            $table->string('checkout_url')->nullable();
            $table->datetime('appointment_date');
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            
            $table->index(['user_id', 'status']);
            $table->index('paymongo_session_id');
            $table->index(['church_id', 'status']);
        });
        
        Schema::table('church_transactions', function (Blueprint $table) {
            // Remove added columns
            $table->dropIndex(['user_id', 'status']);
            $table->dropIndex(['church_id', 'status']);
            $table->dropColumn([
                'service_id',
                'schedule_id',
                'schedule_time_id',
                'status',
                'checkout_url',
                'appointment_date',
                'expires_at'
            ]);
        });
    }
};
