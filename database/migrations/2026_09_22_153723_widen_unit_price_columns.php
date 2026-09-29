<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every column that holds a price per kilo, litre or piece goes to four
 * decimals.
 *
 * Two could not separate the things people buy by the piece: a 400-tablet pack
 * and an 800-tablet pack of the same tablet are 18% apart and both stored
 * `0.03`. A target could not be expressed between two adjacent values, and the
 * drop reference — a median of these figures — could not fall far enough to
 * clear a threshold.
 *
 * Widening only. No stored value changes meaning, and the columns that hold
 * pack money stay at two decimals, because that is what a till charges.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('unit_price_target', 12, 4)->nullable()->change();
            $table->decimal('unit_price_notified', 12, 4)->nullable()->change();
        });

        Schema::table('price_drop_events', function (Blueprint $table): void {
            $table->decimal('reference_unit_price', 12, 4)->nullable()->change();
            $table->decimal('new_unit_price', 12, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('unit_price_target', 12, 2)->nullable()->change();
            $table->decimal('unit_price_notified', 12, 2)->nullable()->change();
        });

        Schema::table('price_drop_events', function (Blueprint $table): void {
            $table->decimal('reference_unit_price', 12, 2)->nullable()->change();
            $table->decimal('new_unit_price', 12, 2)->nullable()->change();
        });
    }
};
