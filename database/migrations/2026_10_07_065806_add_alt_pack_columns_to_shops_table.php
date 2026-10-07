<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // A second size the shop states for the same pack, in another unit:
        // AH lists Iglo fish fingers as 20 pieces and as 560 g. Kept beside
        // the pack columns, so a product can compare every shop in one unit.
        if (! Schema::hasColumn('shops', 'alt_pack_quantity')) {
            Schema::table('shops', fn (Blueprint $table) => $table->decimal('alt_pack_quantity', 12, 2)->nullable());
        }

        if (! Schema::hasColumn('shops', 'alt_pack_unit')) {
            Schema::table('shops', fn (Blueprint $table) => $table->string('alt_pack_unit', 16)->nullable());
        }

        // The page and the two sizes Jev was last asked about, and the answer:
        // null not asked, true confirmed, false rejected.
        if (! Schema::hasColumn('shops', 'alt_pack_check_key')) {
            Schema::table('shops', fn (Blueprint $table) => $table->string('alt_pack_check_key', 64)->nullable());
        }

        if (! Schema::hasColumn('shops', 'alt_pack_confirmed')) {
            Schema::table('shops', fn (Blueprint $table) => $table->boolean('alt_pack_confirmed')->nullable());
        }

        // When the second size was stored or confirmed, so a shop that starts
        // competing on it is not read as a price that fell.
        if (! Schema::hasColumn('shops', 'alt_pack_since')) {
            Schema::table('shops', fn (Blueprint $table) => $table->timestamp('alt_pack_since')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn(['alt_pack_quantity', 'alt_pack_unit', 'alt_pack_check_key', 'alt_pack_confirmed', 'alt_pack_since']);
        });
    }
};
