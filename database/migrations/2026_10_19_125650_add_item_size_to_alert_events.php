<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much one item held, in ml or g, where the product read per item when
 * the alert fired: four 350 ml glasses beside a single one. Stored, not read
 * when the digest renders: by then the shops can sell another item, and
 * "€5.75 each" would describe another offer.
 *
 * Null on rows written before this column, and where the product read per
 * litre, kilo or piece.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('price_drop_events', 'item_size')) {
            Schema::table('price_drop_events', function (Blueprint $table): void {
                $table->decimal('item_size', 12, 4)->nullable();
            });
        }

        if (! Schema::hasColumn('target_price_events', 'item_size')) {
            Schema::table('target_price_events', function (Blueprint $table): void {
                $table->decimal('item_size', 12, 4)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('price_drop_events', function (Blueprint $table): void {
            $table->dropColumn('item_size');
        });
        Schema::table('target_price_events', function (Blueprint $table): void {
            $table->dropColumn('item_size');
        });
    }
};
