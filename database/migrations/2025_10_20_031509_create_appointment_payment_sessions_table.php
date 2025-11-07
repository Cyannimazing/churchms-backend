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
            $table->string('status')->default('pending'); // pending, paid, failed, cancelled
            $table->string('checkout_url')->nullable();
            $table->datetime('appointment_date');
            $table->json('metadata')->nullable(); // church_name, service_name, form_data, etc.
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            
            $table->index(['user_id', 'status']);
            $table->index('paymongo_session_id');
            $table->index(['church_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointment_payment_sessions');
    }
};
