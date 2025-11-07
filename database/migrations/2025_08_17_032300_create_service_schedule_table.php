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
        Schema::create('service_schedules', function (Blueprint $table) {
            $table->id('ScheduleID');
            $table->unsignedBigInteger('ServiceID');
            $table->date('StartDate'); // When this availability period starts
            $table->date('EndDate')->nullable(); // When this availability period ends (null = indefinite)
            $table->integer('SlotCapacity'); // How many people can book per time slot
            $table->timestamps();
            
            $table->foreign('ServiceID')->references('ServiceID')->on('sacrament_service')->onDelete('cascade');
            $table->index(['ServiceID']);
            $table->index(['StartDate', 'EndDate']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_schedules');
    }
};
