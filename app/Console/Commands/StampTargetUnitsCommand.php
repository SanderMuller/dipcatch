<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\UnitTargetConversion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

#[Signature('dipcatch:stamp-target-units')]
#[Description('Record the unit every per-unit target, and every target latch, was set in, as the unit its product compares in today. Run once before shops start stating a second pack size; safe to run again.')]
final class StampTargetUnitsCommand extends Command
{
    /**
     * A target saved before units were recorded means the unit its product
     * compares in now, because nothing has moved it yet. Writing that down
     * before the comparison unit can change is what keeps the number meaning
     * what the owner meant.
     */
    public function handle(): int
    {
        $stamped = 0;

        Product::query()
            ->where(fn (Builder $query): Builder => $query
                ->where(fn (Builder $target): Builder => $target->whereNotNull('unit_price_target')->whereNull('unit_price_target_unit'))
                ->orWhere(fn (Builder $latch): Builder => $latch->whereNotNull('unit_price_notified')->whereNull('unit_price_notified_unit')))
            ->with('shops')
            ->chunkById(200, function (Collection $products) use (&$stamped): void {
                foreach ($products as $product) {
                    $packs = $product->comparablePacks();
                    // The unit it was last compared in, which is the one the
                    // target was read in; today's when it was never compared.
                    $unit = $product->best_value_pack_unit ?? $packs->unit();

                    if ($unit === null) {
                        continue;
                    }

                    if ($product->unit_price_target !== null && $product->unit_price_target_unit === null) {
                        $stamped += Product::query()
                            ->whereKey($product->id)
                            ->whereNull('unit_price_target_unit')
                            ->where('unit_price_target', $product->unit_price_target)
                            ->update([
                                'unit_price_target_unit' => $unit,
                                'unit_price_target_effective' => UnitTargetConversion::effective((string) $product->unit_price_target, $unit, $packs),
                            ]);
                    }

                    // A latch is money in the unit it was armed in, which is
                    // today's for the same reason.
                    if ($product->unit_price_notified !== null && $product->unit_price_notified_unit === null) {
                        Product::query()
                            ->whereKey($product->id)
                            ->whereNull('unit_price_notified_unit')
                            ->update(['unit_price_notified_unit' => $unit]);
                    }
                }
            });

        $this->info("Stamped {$stamped} target(s).");

        return self::SUCCESS;
    }
}
