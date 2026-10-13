<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The promotion window a shop ran under at each check. The shops row keeps
 * only the current window, so without these columns no past offer can be
 * dated, and how often a shop runs one cannot be measured.
 *
 * The same types as the shops columns they copy, so a window reads back
 * the same from either table.
 *
 * Nullable columns without a default: Postgres adds them without rewriting
 * price_checks. Each is guarded so a retry after a half-run migration skips
 * what already exists.
 */
return new class extends Migration {
    public function up(): void
    {
        $columns = [
            'promotion_starts_at' => fn (Blueprint $table) => $table->timestamp('promotion_starts_at')->nullable(),
            'promotion_ends_at' => fn (Blueprint $table) => $table->timestamp('promotion_ends_at')->nullable(),
            'promotion_label' => fn (Blueprint $table) => $table->string('promotion_label')->nullable(),
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
            $table->dropColumn(['promotion_starts_at', 'promotion_ends_at', 'promotion_label']);
        });
    }
};
