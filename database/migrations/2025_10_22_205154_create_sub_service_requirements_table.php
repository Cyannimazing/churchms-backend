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
        Schema::create('sub_service_requirements', function (Blueprint $table) {
            $table->id('RequirementID');
            $table->unsignedBigInteger('SubServiceID');
            $table->string('RequirementName', 255);
            $table->integer('SortOrder')->default(0);
            $table->timestamps();
            
            // Foreign key constraint
            $table->foreign('SubServiceID')->references('SubServiceID')->on('sub_service')->onDelete('cascade');
            
            // Index for better query performance
            $table->index('SubServiceID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sub_service_requirements');
    }
};
