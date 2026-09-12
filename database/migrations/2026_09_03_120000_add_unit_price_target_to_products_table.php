<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            // "Tell me when this costs 6.00 per kilo or less." A target the
            // shopper sets, not a drop measured against history: the two
            // answer different questions, so this one gets its own latch.
            $table->decimal('unit_price_target', 12, 2)->nullable();
            $table->decimal('unit_price_notified', 12, 2)->nullable();
            $table->timestamp('unit_price_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['unit_price_target', 'unit_price_notified', 'unit_price_notified_at']);
        });
    }
};
