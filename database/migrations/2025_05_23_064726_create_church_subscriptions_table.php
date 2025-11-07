<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ChurchSubscription', function (Blueprint $table) {
            $table->id('SubscriptionID');
            $table->unsignedBigInteger('UserID');
            $table->unsignedBigInteger('PlanID');
            $table->datetime('StartDate');
            $table->datetime('EndDate');
            $table->string('Status', 20)->default('Active')->checkIn(['Active', 'Expired', 'Pending']);
            $table->foreign('UserID')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('PlanID')->references('PlanID')->on('SubscriptionPlan')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ChurchSubscription');
    }
};
