<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Web discovery now asks the AI check about the product in any pack size,
 * where it asked open search findings about the tracked size only. This
 * sends the open findings the AI turned away back to the first check, so a
 * shop that sells another size can still be proposed. The nightly
 * `dipcatch:discover-web-shops` run finishes them.
 *
 * Findings the person hid, Klarna leads (already asked about any size) and
 * findings turned away by the read cap stay as they are. The cleared
 * columns are a copy of `WebShopFinding::CLEARED_CHECKS` on this date.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('web_shop_findings')
            ->whereNull('dismissed_at')
            ->whereNull('lead_url')
            ->where(static function (Builder $query): void {
                $query->where('status', 'declined')
                    ->orWhere(static fn (Builder $rejected): Builder => $rejected->where('status', 'rejected')->whereNull('failure'));
            })
            ->update([
                'status' => 'new',
                'generation' => DB::raw('generation + 1'),
                'first_chance' => null,
                'add_url' => null,
                'served_host' => null,
                'page_title' => null,
                'page_pack_quantity' => null,
                'page_pack_unit' => null,
                'page_price' => null,
                'page_currency' => null,
                'page_gtin' => null,
                'matched_gtin' => null,
                'checked_gtins' => null,
                'variant_key' => null,
                'read_at' => null,
                'second_chance' => null,
                'failure' => null,
                'attempts' => 0,
                'next_attempt_at' => null,
                'checked_at' => null,
            ]);
    }

    public function down(): void {}
};
