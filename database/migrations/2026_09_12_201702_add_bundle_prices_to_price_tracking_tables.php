<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('shops', 'single_item_price')) {
            Schema::table('shops', fn (Blueprint $table) => $table->decimal('single_item_price', 12, 2)->nullable());
        }
        if (! Schema::hasColumn('shops', 'bundle_quantity')) {
            Schema::table('shops', fn (Blueprint $table) => $table->unsignedSmallInteger('bundle_quantity')->nullable());
        }
        if (! Schema::hasColumn('shops', 'bundle_total_price')) {
            Schema::table('shops', fn (Blueprint $table) => $table->decimal('bundle_total_price', 12, 2)->nullable());
        }

        if (! Schema::hasColumn('price_checks', 'single_item_price')) {
            Schema::table('price_checks', fn (Blueprint $table) => $table->decimal('single_item_price', 12, 2)->nullable());
        }
        if (! Schema::hasColumn('price_checks', 'bundle_quantity')) {
            Schema::table('price_checks', fn (Blueprint $table) => $table->unsignedSmallInteger('bundle_quantity')->nullable());
        }
        if (! Schema::hasColumn('price_checks', 'bundle_total_price')) {
            Schema::table('price_checks', fn (Blueprint $table) => $table->decimal('bundle_total_price', 12, 2)->nullable());
        }

        if (! Schema::hasColumn('product_cheapest_history', 'single_item_price')) {
            Schema::table('product_cheapest_history', fn (Blueprint $table) => $table->decimal('single_item_price', 12, 2)->nullable());
        }
        if (! Schema::hasColumn('product_cheapest_history', 'bundle_quantity')) {
            Schema::table('product_cheapest_history', fn (Blueprint $table) => $table->unsignedSmallInteger('bundle_quantity')->nullable());
        }
        if (! Schema::hasColumn('product_cheapest_history', 'bundle_total_price')) {
            Schema::table('product_cheapest_history', fn (Blueprint $table) => $table->decimal('bundle_total_price', 12, 2)->nullable());
        }
    }

    public function down(): void
    {
        foreach (['shops', 'price_checks', 'product_cheapest_history'] as $tableName) {
            foreach (['single_item_price', 'bundle_quantity', 'bundle_total_price'] as $column) {
                if (Schema::hasColumn($tableName, $column)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }
    }
};
