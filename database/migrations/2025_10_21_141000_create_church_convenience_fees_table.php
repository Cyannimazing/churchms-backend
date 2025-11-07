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
        Schema::create('church_convenience_fees', function (Blueprint $table) {
            $table->id('ConvenienceFeeID');
            $table->unsignedBigInteger('church_id');
            $table->string('fee_name')->default('Convenience Fee');
            $table->enum('fee_type', ['percent', 'fixed'])->default('percent');
            $table->decimal('fee_value', 8, 2); // For percent: 0-100, for fixed: actual amount
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->foreign('church_id')->references('ChurchID')->on('Church')->onDelete('cascade');
            $table->index(['church_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('church_convenience_fees');
    }
};
