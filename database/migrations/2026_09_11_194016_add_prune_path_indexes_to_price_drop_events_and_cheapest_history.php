<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Postgres does not index the referencing side of a foreign key, so every
 * column below is a sequential scan today. All four sit on the nightly
 * `dipcatch:prune-old-checks` path:
 *
 *  - `price_drop_events.product_id` — the command filters on it once per
 *    product, and the chart's drop markers filter on it once per product view.
 *  - `price_drop_events.price_check_id` and
 *    `product_cheapest_history.triggering_price_check_id` — the command deletes
 *    from `price_checks` in bulk, and Postgres enforces the cascade and the
 *    set-null with a lookup on each referencing table per deleted row.
 *  - `price_drop_events.triggered_by_shop_id` — the command filters on it once
 *    per offer, through the `triggeredByShop` existence check.
 *
 * Plain `CREATE INDEX` rather than `CONCURRENTLY`: a plain build takes a SHARE
 * lock for its duration, which is milliseconds on tables this size. Building
 * concurrently cannot run inside a transaction, so it would need
 * `$withinTransaction = false` and give up the rollback Postgres provides here.
 * Revisit that trade if these tables are ever indexed again at scale.
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
