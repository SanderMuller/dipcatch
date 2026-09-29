<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // The pack the drop was measured on, so an alert can say "€2.75 for
        // 227 g". Worked back from the unit price it is wrong: that is stored to
        // four decimals, and €21.99 at €0.0275 a tablet reads as 799.6 tablets.
        if (! Schema::hasColumn('price_drop_events', 'pack_quantity')) {
            Schema::table('price_drop_events', function (Blueprint $table): void {
                $table->decimal('pack_quantity', 12, 2)->nullable();
            });
        }

        if (! Schema::hasColumn('price_drop_events', 'pack_unit')) {
            Schema::table('price_drop_events', function (Blueprint $table): void {
                $table->string('pack_unit')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('price_drop_events', function (Blueprint $table): void {
            $table->dropColumn(['pack_quantity', 'pack_unit']);
        });
    }
};
