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
        Schema::create('payment_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('plan_id');
            $table->string('paymongo_session_id')->unique();
            $table->string('payment_method')->default('gcash');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('PHP');
            $table->string('status')->default('pending'); // pending, paid, failed, cancelled
            $table->string('checkout_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            
            // Remove foreign key constraints to avoid issues
            // $table->foreign('user_id')->references('id')->on('users');
            // $table->foreign('plan_id')->references('PlanID')->on('SubscriptionPlan');
            $table->index(['user_id', 'status']);
            $table->index('paymongo_session_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_sessions');
    }
};
