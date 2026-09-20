<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Each statement guarded on its own: a run that dies halfway records
        // nothing, and the retry would otherwise fail on the column it already
        // added.
        if (! Schema::hasColumn('product_cheapest_history', 'best_value_shop_id')) {
            Schema::table('product_cheapest_history', function (Blueprint $table): void {
                // The shop that was cheapest per unit while this segment ran —
                // the basis a drop is decided on. Null on every segment written
                // before unit comparison existed, and deliberately not
                // backfilled: a size taken from today is not the size this
                // segment was written under.
                $table->string('best_value_shop_id')->nullable();
                $table->decimal('best_value_price', 10, 2)->nullable();
            });
        }

        if (! Schema::hasColumn('product_cheapest_history', 'pack_quantity')) {
            Schema::table('product_cheapest_history', function (Blueprint $table): void {
                // The best-value shop's pack size, recorded rather than derived
                // on read. A pack size corrected later must not rescale the past.
                $table->decimal('pack_quantity', 12, 2)->nullable();
                $table->string('pack_unit')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('product_cheapest_history', function (Blueprint $table): void {
            $table->dropColumn(['best_value_shop_id', 'best_value_price', 'pack_quantity', 'pack_unit']);
        });
    }
};
