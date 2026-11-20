<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The AI check on catalogue suggestions now asks about the product in any
 * pack size, where it asked about the tracked size only. This removes the
 * answers that did not accept a row, so the next render of the suggestions
 * asks again and a row in another size can still show. An accepted row
 * stays accepted: the same product in the same pack is the same product.
 *
 * 0.6 is the default `dipcatch.shop_checks.accept_from` on this date.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('shop_suggestion_verdicts')->where('same_chance', '<', 0.6)->delete();
    }

    public function down(): void {}
};
