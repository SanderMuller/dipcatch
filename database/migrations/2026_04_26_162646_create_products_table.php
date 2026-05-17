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
            $table->string('title');
            $table->text('image_url')->nullable();
            $table->char('currency', 3);
            $table->decimal('drop_threshold_pct', 5, 2)->nullable();
            $table->decimal('drop_threshold_abs', 12, 2)->nullable();
            $table->decimal('last_notified_price', 12, 2)->nullable();
            $table->timestampTz('last_notified_at')->nullable();
            // Denormalized cheapest pointer + price. FK to shops added in the
            // create_offers_table migration once that table exists (circular FK).
            $table->uuid('cheapest_shop_id')->nullable();
            $table->decimal('cheapest_price', 12, 2)->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->index(['user_id', 'active']);
            $table->index('cheapest_price');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
