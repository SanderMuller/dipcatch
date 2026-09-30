<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The price a shop says it charged before a discount, and what the discount
 * check needs to judge that claim against DipCatch's own readings. See
 * specs/category-expansion.md, sections 3 and 4.
 *
 * Nullable columns without a default: Postgres adds them without rewriting
 * price_checks. Each is guarded so a retry after a half-run migration skips
 * what already exists.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('shops', 'claimed_regular_price')) {
            Schema::table('shops', fn (Blueprint $table) => $table->decimal('claimed_regular_price', 12, 2)->nullable());
        }

        $checks = [
            'claimed_regular_price' => fn (Blueprint $table) => $table->decimal('claimed_regular_price', 12, 2)->nullable(),
            'seller' => fn (Blueprint $table) => $table->string('seller')->nullable(),
            // True when the reader can state a claim, so a null claim means
            // "the page stated none" rather than "this reader cannot tell".
            'claim_read' => fn (Blueprint $table) => $table->boolean('claim_read')->nullable(),
            // True when the tracked and shelf prices were carried over from
            // an earlier reading rather than read from this page.
            'shelf_inherited' => fn (Blueprint $table) => $table->boolean('shelf_inherited')->nullable(),
            'consumer_price_issue' => fn (Blueprint $table) => $table->string('consumer_price_issue')->nullable(),
        ];

        foreach ($checks as $column => $add) {
            if (! Schema::hasColumn('price_checks', $column)) {
                Schema::table('price_checks', $add);
            }
        }
    }

    public function down(): void
    {
        Schema::table('price_checks', function (Blueprint $table): void {
            $table->dropColumn(['claimed_regular_price', 'seller', 'claim_read', 'shelf_inherited', 'consumer_price_issue']);
        });

        Schema::table('shops', function (Blueprint $table): void {
            $table->dropColumn('claimed_regular_price');
        });
    }
};
