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
        Schema::create('church_transactions', function (Blueprint $table) {
            $table->id('ChurchTransactionID');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('church_id');
            $table->unsignedBigInteger('appointment_id')->nullable();
            $table->string('paymongo_session_id');
            $table->string('payment_method')->default('multi');
            $table->decimal('amount_paid', 10, 2);
            $table->string('currency', 3)->default('PHP');
            $table->string('transaction_type')->default('appointment_payment');
            $table->datetime('transaction_date');
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('church_id')->references('ChurchID')->on('Church')->onDelete('cascade');
            $table->foreign('appointment_id')->references('AppointmentID')->on('Appointment')->onDelete('set null');
            
            $table->index(['user_id', 'church_id']);
            $table->index('paymongo_session_id');
            $table->index('transaction_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('church_transactions');
    }
};
