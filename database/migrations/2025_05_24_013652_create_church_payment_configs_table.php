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
        Schema::create('church_payment_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('church_id');
            $table->string('provider')->default('paymongo'); // For future extensibility
            $table->string('public_key');
            $table->text('secret_key'); // Will be encrypted
            $table->boolean('is_active')->default(false);
            $table->json('settings')->nullable(); // For additional PayMongo settings
            $table->timestamps();

            $table->foreign('church_id')->references('ChurchID')->on('Church')->onDelete('cascade');
            $table->unique(['church_id', 'provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('church_payment_configs');
    }
};
