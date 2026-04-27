<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->string('title');
            $table->text('image_url')->nullable();
            $table->string('price_selector', 500);
            $table->json('fallback_selectors');
            $table->string('image_selector', 500)->nullable();
            $table->string('title_selector', 500)->nullable();
            $table->char('currency', 3);
            $table->decimal('initial_price', 12, 2);
            $table->timestampTz('initial_checked_at');
            $table->decimal('last_price', 12, 2)->nullable();
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->string('last_status');
            $table->text('last_error')->nullable();
            $table->decimal('drop_threshold_pct', 5, 2)->nullable();
            $table->decimal('drop_threshold_abs', 12, 2)->nullable();
            $table->decimal('last_notified_price', 12, 2)->nullable();
            $table->timestampTz('last_notified_at')->nullable();
            $table->boolean('needs_js')->default(false);
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->index(['user_id', 'active']);
            $table->index(['active', 'last_checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
