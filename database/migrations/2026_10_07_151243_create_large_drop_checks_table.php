<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
            $table->foreignId('resolved_by_price_check_id')->nullable()->constrained('price_checks')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();

            $table->index(['shop_id', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('large_drop_checks');
    }
};
