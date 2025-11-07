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
        Schema::create('sub_service', function (Blueprint $table) {
            $table->id('SubServiceID');
            $table->unsignedBigInteger('ServiceID');
            $table->string('SubServiceName', 100);
            $table->text('Description')->nullable();
            $table->boolean('IsActive')->default(true);
            $table->timestamps();
            
            // Foreign key constraint
            $table->foreign('ServiceID')->references('ServiceID')->on('sacrament_service')->onDelete('cascade');
            
            // Index for better query performance
            $table->index('ServiceID');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sub_service');
    }
};
