<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The pack size a shop sold at each check. The shops row keeps only the
 * current size, so without these columns a price from before a pack change
 * cannot be told apart from one after it.
 *
 * The same types as the shops columns they copy.
 *
 * Nullable columns without a default: Postgres adds them without rewriting
 * price_checks. Each is guarded so a retry after a half-run migration skips
 * what already exists.
 */
return new class extends Migration {
    public function up(): void
    {
        $columns = [
            'pack_quantity' => fn (Blueprint $table) => $table->decimal('pack_quantity', 10, 2)->nullable(),
            'pack_unit' => fn (Blueprint $table) => $table->string('pack_unit')->nullable(),
        ];

        foreach ($columns as $column => $add) {
            if (! Schema::hasColumn('price_checks', $column)) {
                Schema::table('price_checks', $add);
            }
        }
    }

    public function down(): void
    {
        Schema::table('price_checks', function (Blueprint $table): void {
            $table->dropColumn(['pack_quantity', 'pack_unit']);
        });
    }
};
