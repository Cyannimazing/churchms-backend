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
        Schema::create('service_requirement', function (Blueprint $table) {
            $table->id('RequirementID');
            $table->unsignedBigInteger('ServiceID');
            $table->text('Description');
            $table->boolean('IsMandatory')->default(false);
            $table->string('RequirementType', 50)->nullable(); // 'document', 'age_limit', 'status_check', etc.
            $table->json('RequirementData')->nullable(); // Additional data for requirement validation
            $table->integer('SortOrder')->default(0);
            $table->timestamps();
            
            // Foreign key constraint
            $table->foreign('ServiceID')->references('ServiceID')->on('sacrament_service')->onDelete('cascade');
            
            // Index for better query performance
            $table->index(['ServiceID', 'IsMandatory']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_requirement');
    }
};
