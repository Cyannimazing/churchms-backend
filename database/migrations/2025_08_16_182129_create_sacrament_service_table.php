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
        Schema::create('sacrament_service', function (Blueprint $table) {
            $table->id('ServiceID');
            $table->unsignedBigInteger('ChurchID');
            $table->string('ServiceName', 100);
            $table->text('Description')->nullable();
            $table->timestamps();
            
            // Foreign key constraint
            $table->foreign('ChurchID')->references('ChurchID')->on('Church')->onDelete('cascade');
            
            // Index for better query performance
            $table->index(['ChurchID', 'ServiceName']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sacrament_service');
    }
};
