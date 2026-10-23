<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Drops\DetectUnitPriceTarget;
use App\Actions\Products\LatchIfMet;
use App\Models\Product;
use App\Models\Shop;
use App\Services\Drops\Reference;
use App\Support\MoneyFormatter;
use App\Support\OldAlertSettings;
use App\Support\UnitTargetConversion;
use App\Support\UnitWord;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

#[Signature('dipcatch:convert-alerts-to-unit-targets {--dry-run : Print what each product would get, and change nothing}')]
#[Description('Turn every "price for any pack" target and stored drop percentage or amount into one target per kilo, litre, piece or pack. Run once after the release that reads only that target; run --dry-run first.')]
final class ConvertAlertsToUnitTargetsCommand extends Command
{
    /**
     * Each old setting gives one candidate, measured from today's best: the
     * highest one is kept, the easiest of the old rules, read against
     * today's best. A product whose target is already
     * set keeps it; a re-run after an owner removed a converted target would
     * bring it back, so this runs once.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        /** @var array<string, int> $counts */
        $counts = [];
        $tierDefaults = 0;

        Product::query()
            ->where(fn (Builder $query): Builder => $query
                ->whereNotNull('target_price')
                ->orWhereNotNull('drop_threshold_pct')
                ->orWhereNotNull('drop_threshold_abs')
                ->orWhere(fn (Builder $legacy): Builder => $legacy->whereNotNull('unit_price_target')->whereNull('unit_price_target_unit')))
            ->select('id')
            ->chunkById(200, function (EloquentCollection $products) use ($dryRun, &$counts, &$tierDefaults): void {
                foreach ($products as $product) {
                    $result = DB::transaction(fn (): ?array => $this->convert((string) $product->id, $dryRun));

                    if ($result === null) {
                        continue;
                    }

                    $counts[$result['outcome']] = ($counts[$result['outcome']] ?? 0) + 1;
                    $tierDefaults += $result['tierDefault'] ? 1 : 0;
                    $this->line($result['line']);
                }
            });

        ksort($counts);

        foreach ($counts as $outcome => $count) {
            $this->info("{$outcome}: {$count}");
        }

        $this->info("Stored drops that equal a default: {$tierDefaults}");

        if ($dryRun) {
            $this->warn('Dry run: nothing was changed.');
        }

        return self::SUCCESS;
    }

    /**
     * One product, under its row lock: a target the owner saved after the
     * product was selected is never overwritten, and no target is visible
     * without its latch.
     *
     * @return array{outcome: string, line: string, tierDefault: bool}|null
     */
    private function convert(string $id, bool $dryRun): ?array
    {
        $product = Product::query()->lockForUpdate()->find($id);

        if ($product === null) {
            return null;
        }

        $label = "{$product->id} {$product->title}:";

        if ($product->unit_price_target !== null) {
            return $this->backfill($product, $label, $dryRun);
        }

        $settings = new OldAlertSettings($product);
        $old = $settings->describe();

        if ($old === []) {
            return null;
        }

        $tierDefault = $settings->isTierDefault();
        $reference = app(Reference::class)->compute($product);
        $describe = $label . ' ' . implode(', ', $old)
            . ($reference === null ? '' : ', drop reference ' . $reference->value . ($reference->unit === null ? '' : ' ' . UnitWord::labelFor($reference->unit)));

        $new = $settings->target();

        if ($new === null) {
            return ['outcome' => 'kept the default drop', 'line' => $describe . ' → no target, the default drop applies', 'tierDefault' => $tierDefault];
        }

        $product->forceFill([
            'unit_price_target' => $new['target'],
            'unit_price_target_unit' => $new['unit'],
            'unit_price_target_shop_id' => $new['shop']?->id,
            'unit_price_target_effective' => UnitTargetConversion::effective($new['target'], $new['unit'], $product->comparablePacks()),
        ]);

        $basis = $product->targetBasis();
        $met = $basis !== null && DetectUnitPriceTarget::meets($basis->value, $basis->target);

        if (! $dryRun) {
            $product->save();
            app(LatchIfMet::class)($product);
        }

        $line = $describe . ', today ' . ($new['today'] ?? '—') . ' → ' . MoneyFormatter::unitPrice($new['target'], (string) $product->currency)
            . ' ' . UnitWord::labelFor($new['unit'])
            . ($new['shop'] === null ? '' : ' at ' . $new['shop']->host)
            . ($met ? ', pre-latched' : '');

        return [
            'outcome' => $new['unit'] === UnitTargetConversion::PACK ? 'converted to a price per pack' : 'converted to a price per unit',
            'line' => $line,
            'tierDefault' => $tierDefault,
        ];
    }

    /**
     * A target set while the product compared in no unit, with nothing to
     * say which unit: a price for the pack of the shop it was added with.
     *
     * @return array{outcome: string, line: string, tierDefault: bool}
     */
    private function backfill(Product $product, string $label, bool $dryRun): array
    {
        if ($product->unit_price_target_unit !== null || $product->best_value_pack_unit !== null) {
            return ['outcome' => 'kept its own target', 'line' => $label . ' has a target, skipped', 'tierDefault' => false];
        }

        $shop = $product->firstAddedShop();

        // A raw update, as `Product::stampUnitBasis()` stamps it: recording
        // what the number meant is not a new target, so the latch stays.
        if (! $dryRun) {
            Product::query()->whereKey($product->id)->update(array_filter([
                'unit_price_target_unit' => UnitTargetConversion::PACK,
                'unit_price_target_shop_id' => $shop?->id,
                'unit_price_notified_unit' => $product->unit_price_notified !== null && $product->unit_price_notified_unit === null ? UnitTargetConversion::PACK : null,
            ]));
        }

        return [
            'outcome' => 'stamped as a price per pack',
            'line' => $label . ' target ' . $product->unit_price_target . ' with no unit → per pack' . ($shop === null ? '' : ' at ' . $shop->host),
            'tierDefault' => false,
        ];
    }
}
