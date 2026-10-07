<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // The unit the owner set the per-unit target in. Without it a product
        // that starts comparing per kilo reads a target set per piece as a
        // price per kilo.
        if (! Schema::hasColumn('products', 'unit_price_target_unit')) {
            Schema::table('products', fn (Blueprint $table) => $table->string('unit_price_target_unit', 16)->nullable());
        }

        // The target in the unit the product compares in today, or null while
        // it cannot be expressed in that unit. Every comparison reads this one.
        if (! Schema::hasColumn('products', 'unit_price_target_effective')) {
            Schema::table('products', fn (Blueprint $table) => $table->decimal('unit_price_target_effective', 12, 4)->nullable());
        }

        // The unit `unit_price_notified` is money in.
        if (! Schema::hasColumn('products', 'unit_price_notified_unit')) {
            Schema::table('products', fn (Blueprint $table) => $table->string('unit_price_notified_unit', 16)->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['unit_price_target_unit', 'unit_price_target_effective', 'unit_price_notified_unit']);
        });
    }
};
