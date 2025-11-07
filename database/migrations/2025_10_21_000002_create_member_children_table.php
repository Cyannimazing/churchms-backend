<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('church_member_id')->constrained('church_members')->onDelete('cascade');
            
            // Child Information
            $table->string('first_name');
            $table->string('last_name')->nullable(); // Can be different from parent
            $table->date('date_of_birth');
            $table->enum('sex', ['M', 'F']);
            $table->string('religion')->nullable();
            
            // Sacraments
            $table->boolean('baptism')->default(false);
            $table->boolean('first_eucharist')->default(false);
            $table->boolean('confirmation')->default(false);
            
            // School Information
            $table->string('school')->nullable();
            $table->string('grade')->nullable(); // e.g., "2022-23 School Year"
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_children');
    }
};
