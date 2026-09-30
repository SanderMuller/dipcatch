<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('empty_searches')) {
            return;
        }

        Schema::create('empty_searches', function (Blueprint $table): void {
            // A search in the header that found nothing: one row per person
            // and term, so we see what people look for and cannot find.
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('term', 100);
            $table->unsignedInteger('times')->default(1);
            $table->timestamp('first_searched_at');
            $table->timestamp('last_searched_at')->index();

            $table->unique(['user_id', 'term']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empty_searches');
    }
};
