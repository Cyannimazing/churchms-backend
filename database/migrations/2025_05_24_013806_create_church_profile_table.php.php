    <?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Support\Facades\Schema;

    return new class extends Migration
    {
        public function up(): void
        {
            Schema::create('ChurchProfile', function (Blueprint $table) {
                $table->foreignId('ChurchID')
                    ->primary()
                    ->constrained('Church', 'ChurchID')
                    ->onDelete('cascade');
                $table->text('Description')->nullable();
                $table->text('ParishDetails')->nullable();
                $table->string('ProfilePicturePath')->nullable();
                $table->timestamps();
            });
        }

        public function down(): void
        {
            Schema::dropIfExists('ChurchProfile');
        }
    };
