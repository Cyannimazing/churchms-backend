<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
     public function up(): void
    {
        Schema::create('ChurchOwnerDocument', function (Blueprint $table) {
            $table->id('DocumentID');
            $table->foreignId('ChurchID')
                  ->constrained('Church', 'ChurchID')
                  ->onDelete('cascade')
                  ->nullable(false);
            $table->string('DocumentType', 100)->nullable(false);
            $table->string('DocumentPath')->nullable(); // Store file path
            $table->dateTime('SubmissionDate')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ChurchOwnerDocument');
    }
};
