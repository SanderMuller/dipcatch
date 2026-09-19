<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasIndex('price_drop_events', 'price_drop_events_product_id_fired_at_index')) {
            return;
        }

        Schema::table('price_drop_events', function (Blueprint $table): void {
            // The latest event per product is read for every row of the
            // product list and the dashboard; the foreign key alone carries
            // no index on Postgres.
            $table->index(['product_id', 'fired_at']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasIndex('price_drop_events', 'price_drop_events_product_id_fired_at_index')) {
            return;
        }

        Schema::table('price_drop_events', function (Blueprint $table): void {
            $table->dropIndex(['product_id', 'fired_at']);
        });
    }
};
