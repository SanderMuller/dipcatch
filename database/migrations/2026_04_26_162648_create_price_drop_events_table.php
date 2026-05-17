<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('price_drop_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('price_check_id');
            $table->uuid('notification_id')->nullable();
            $table->foreignUuid('triggered_by_shop_id')->nullable()->constrained('shops')->nullOnDelete();
            $table->char('currency', 3);
            $table->decimal('reference_price', 12, 2);
            $table->string('reference_kind');
            $table->decimal('new_price', 12, 2);
            $table->decimal('drop_pct', 7, 4);
            $table->decimal('drop_abs', 12, 2);
            $table->timestampTz('fired_at');

            $table->foreign('price_check_id')->references('id')->on('price_checks')->cascadeOnDelete();
            $table->index(['user_id', 'fired_at']);
            $table->index('fired_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_drop_events');
    }
};
