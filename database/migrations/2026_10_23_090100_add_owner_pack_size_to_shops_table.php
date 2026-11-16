<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much is in the pack the owner buys at this shop, in their own words,
 * for a shop whose page states no size. It gives the product a price per
 * unit. The shop's own size wins once it states one; this stays, so it
 * comes back if the shop stops stating one.
 *
 * Per shop, never per product: another shop can sell another pack.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('shops', 'owner_pack_quantity')) {
            Schema::table('shops', function (Blueprint $table): void {
                $table->decimal('owner_pack_quantity', 10, 2)->nullable();
            });
        }

        if (! Schema::hasColumn('shops', 'owner_pack_unit')) {
            Schema::table('shops', function (Blueprint $table): void {
                $table->string('owner_pack_unit', 16)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn(['owner_pack_quantity', 'owner_pack_unit']);
        });
    }
};
