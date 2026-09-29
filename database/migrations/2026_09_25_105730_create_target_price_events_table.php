<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A product reaching the price its owner set, kept for the daily email. The
 * push and the bell are sent the moment it happens; this row is what the
 * next digest reads. Drops keep their own table: their figures feed the
 * savings totals, and a target reached is not a saving measured.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('target_price_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('shop_id')->nullable()->constrained('shops')->nullOnDelete();
            $table->char('currency', 3);
            $table->decimal('target', 12, 4);
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('unit_price', 12, 4)->nullable();
            $table->string('comparison_unit')->nullable();
            $table->decimal('pack_quantity', 12, 2)->nullable();
            $table->string('pack_unit')->nullable();
            $table->string('deal')->nullable();
            $table->timestampTz('fired_at');

            $table->index(['user_id', 'fired_at']);
            $table->index('fired_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('target_price_events');
    }
};
