<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shop a target set per pack compares with. A product with no size has
 * no price per unit, so the owner picks the pack they buy and the price for
 * that pack. Only that shop's price is compared: a single bar at another
 * shop is always cheaper than a 12-pack.
 *
 * Null for a target per kilo, litre or piece. Cleared when the shop is
 * removed, which suspends the target until the owner picks another pack.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'unit_price_target_shop_id')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->foreignUuid('unit_price_target_shop_id')->nullable()->constrained('shops')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('unit_price_target_shop_id');
        });
    }
};
