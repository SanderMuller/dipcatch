<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the shop's price was its lowest in 90 days when the alert fired.
 * Stored, not computed when the digest or push renders: by then the price
 * can have moved, and the badge would describe another offer.
 *
 * Null on rows written before this column. A nullable column without a
 * default: Postgres adds it without rewriting the table. Each is guarded so a
 * retry after a half-run migration skips what already exists.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('price_drop_events', 'lowest_in_90_days')) {
            Schema::table('price_drop_events', function (Blueprint $table): void {
                $table->boolean('lowest_in_90_days')->nullable();
            });
        }

        if (! Schema::hasColumn('target_price_events', 'lowest_in_90_days')) {
            Schema::table('target_price_events', function (Blueprint $table): void {
                $table->boolean('lowest_in_90_days')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('price_drop_events', function (Blueprint $table): void {
            $table->dropColumn('lowest_in_90_days');
        });
        Schema::table('target_price_events', function (Blueprint $table): void {
            $table->dropColumn('lowest_in_90_days');
        });
    }
};
