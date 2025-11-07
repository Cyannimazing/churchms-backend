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
        Schema::table('service_input_field', function (Blueprint $table) {
            // Form builder positioning and styling properties
            $table->integer('x_position')->nullable()->after('SortOrder'); // X coordinate
            $table->integer('y_position')->nullable()->after('x_position'); // Y coordinate
            $table->integer('width')->nullable()->after('y_position'); // Element width
            $table->integer('height')->nullable()->after('width'); // Element height
            $table->integer('z_index')->default(1)->after('height'); // Z-index for layering
            
            // Text styling properties (for heading and paragraph)
            $table->text('text_content')->nullable()->after('z_index'); // Actual text content
            $table->string('text_size', 20)->nullable()->after('text_content'); // h1, h2, h3, h4, etc.
            $table->string('text_align', 20)->default('left')->after('text_size'); // left, center, right, justify
            $table->string('text_color', 7)->default('#000000')->after('text_align'); // Hex color
            
            // Additional properties for different field types
            $table->integer('textarea_rows')->nullable()->after('text_color'); // Number of rows for textarea
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_input_field', function (Blueprint $table) {
            $table->dropColumn([
                'x_position',
                'y_position', 
                'width',
                'height',
                'z_index',
                'text_content',
                'text_size',
                'text_align',
                'text_color',
                'textarea_rows'
            ]);
        });
    }
};
