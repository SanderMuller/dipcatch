<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Postgres does not index the referencing side of a foreign key, so every
 * column below is a sequential scan today. All four are read once per product
 * or once per offer by the nightly `dipcatch:prune-old-checks`, two of them
 * through the cascade and set-null it triggers on `price_checks`.
 *
 * Plain `CREATE INDEX` rather than `CONCURRENTLY`. A plain build takes a SHARE
 * lock for its duration, which is milliseconds on tables this size. Building
 * concurrently cannot run inside a transaction, so it would need
 * `$withinTransaction = false` and give up the rollback Postgres provides here.
 * Revisit that trade if these tables are ever indexed again at scale.
 *
 * The nearest sibling, `2026_09_02_180000_add_trgm_index_to_checkjebon_prices_name`,
 * guards each statement with `IF NOT EXISTS`. It does not need to here: Postgres
 * has transactional DDL, so a half-applied `up()` rolls back on its own.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('price_drop_events', function (Blueprint $table): void {
            $table->index('product_id');
            $table->index('price_check_id');
            $table->index('triggered_by_shop_id');
        });

        Schema::table('product_cheapest_history', function (Blueprint $table): void {
            $table->index('triggering_price_check_id');
        });
    }

    public function down(): void
    {
        Schema::table('price_drop_events', function (Blueprint $table): void {
            $table->dropIndex(['product_id']);
            $table->dropIndex(['price_check_id']);
            $table->dropIndex(['triggered_by_shop_id']);
        });

        Schema::table('product_cheapest_history', function (Blueprint $table): void {
            $table->dropIndex(['triggering_price_check_id']);
        });
    }
};
