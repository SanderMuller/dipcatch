<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('price_drop_events', 'comparison_unit')) {
            Schema::table('price_drop_events', function (Blueprint $table): void {
                // One event now carries two bases, and each is named rather than
                // inferred. `reference_price` and `new_price` keep holding pack
                // money — what the shopper hands over — while the percentage that
                // fired the alert is computed from these.
                $table->decimal('reference_unit_price', 12, 2)->nullable();
                $table->decimal('new_unit_price', 12, 2)->nullable();
                $table->string('comparison_unit')->nullable();
            });
        }

        // A cross-size move has no honest money figure: 500 g at €5 becoming
        // 1 kg at €8 is a real 20% fall per kilo and a €3 *rise* in outlay.
        // Null says "not a saving anyone made" instead of reporting a negative
        // one, and the savings chart skips those rows.
        Schema::table('price_drop_events', function (Blueprint $table): void {
            $table->decimal('drop_abs', 12, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('price_drop_events', function (Blueprint $table): void {
            $table->dropColumn(['reference_unit_price', 'new_unit_price', 'comparison_unit']);
        });
    }
};
