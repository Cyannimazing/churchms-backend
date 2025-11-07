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
        Schema::create('service_input_field', function (Blueprint $table) {
            $table->id('InputFieldID');
            $table->unsignedBigInteger('ServiceID');
            $table->string('Label', 100)->nullable();
            $table->string('InputType', 50)->nullable();
            $table->boolean('IsRequired')->default(false);
            $table->json('Options')->nullable(); // For dropdown, checkbox, radio options
            $table->text('Placeholder')->nullable();
            $table->text('HelpText')->nullable();
            $table->integer('SortOrder')->default(0); // For field ordering
            $table->timestamps();
            
            // Foreign key constraint
            $table->foreign('ServiceID')->references('ServiceID')->on('sacrament_service')->onDelete('cascade');
            
            // Index for better query performance
            $table->index(['ServiceID', 'SortOrder']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_input_field');
    }
};
