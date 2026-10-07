<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A large drop DipCatch read once and asked a second reading about, and what
 * that reading said. The price changes list on the product page reads it to
 * show a caught wrong price; the alert decision itself never does.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('large_drop_checks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('shop_id')->nullable()->constrained('shops')->nullOnDelete();
            $table->foreignId('price_check_id')->unique()->constrained('price_checks')->cascadeOnDelete();
            $table->decimal('price', 12, 2);
            $table->timestampTz('asked_at');
            $table->string('outcome')->nullable();
            $table->timestampTz('resolved_at')->nullable();

            $table->index(['product_id', 'asked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('large_drop_checks');
    }
};
