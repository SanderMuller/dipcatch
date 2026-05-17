<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_cheapest_history', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('cheapest_shop_id')->nullable()->constrained('shops')->nullOnDelete();
            $table->decimal('cheapest_price', 12, 2)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->foreignId('triggering_price_check_id')->nullable()->constrained('price_checks')->nullOnDelete();

            $table->index(['product_id', 'started_at']);
            $table->index(['product_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_cheapest_history');
    }
};
