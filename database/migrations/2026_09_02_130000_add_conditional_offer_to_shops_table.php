<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            // A price only some shoppers can pay — a personal or membership
            // offer the shop advertises beside its own price. Kept apart from
            // `current_price` on purpose: it must never drive a price-drop
            // alert or enter the price history, because the shopper may not
            // be able to claim it. Null until a source reports one.
            $table->decimal('conditional_price', 12, 2)->nullable();
            $table->string('conditional_label')->nullable();
            $table->timestamp('conditional_starts_at')->nullable();
            $table->timestamp('conditional_ends_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn([
                'conditional_price',
                'conditional_label',
                'conditional_starts_at',
                'conditional_ends_at',
            ]);
        });
    }
};
