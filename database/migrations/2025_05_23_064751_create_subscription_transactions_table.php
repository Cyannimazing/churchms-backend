<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('SubscriptionTransaction', function (Blueprint $table) {
            $table->id('SubTransactionID');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->unsignedBigInteger('OldPlanID')->nullable();
            $table->unsignedBigInteger('NewPlanID');
            $table->string('PaymentMethod', 50)->nullable();
            $table->decimal('AmountPaid', 10, 2);
            $table->dateTime('TransactionDate')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->text('Notes')->nullable();
            $table->foreign('OldPlanID')->references('PlanID')->on('SubscriptionPlan')->onDelete('set null');
            $table->foreign('NewPlanID')->references('PlanID')->on('SubscriptionPlan')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('SubscriptionTransaction');
    }
};
