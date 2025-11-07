<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('SubscriptionPlan', function (Blueprint $table) {
            $table->id('PlanID');
            $table->string('PlanName', 50);
            $table->decimal('Price', 10, 2);
            $table->integer('DurationInMonths');
            $table->integer('MaxChurchesAllowed');
            $table->text('Description')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('SubscriptionPlan');
    }
};
