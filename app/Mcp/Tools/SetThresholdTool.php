<?php declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\PackExclusion;
use App\Enums\PackProvenance;
use App\Mcp\Concerns\InteractsWithOwner;
use App\Mcp\Support\ProductPresenter;
use App\Models\Product;
use App\Models\Shop;
use App\Support\ComparablePacks;
use App\Support\Numeric;
use App\Support\UnitTargetConversion;
use App\Support\UnitTargetMath;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('set_threshold')]
#[Title('Set threshold')]
#[Description('Sets the price this product alerts at. A product has one target: a price per kg, litre or piece that holds every shop and pack size to one rate, or the price of one shop\'s pack, compared with that shop only until its size is known. Give exactly one of unit_price_target, percent_under_best or pack_price. Without a target the product alerts on a large drop under its usual price.')]
#[IsReadOnly(false)]
#[IsDestructive]
#[IsOpenWorld(false)]
final class SetThresholdTool extends Tool
{
    use InteractsWithOwner;

    public function __construct(private readonly ProductPresenter $presenter) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'product_id' => ['required', 'uuid'],
            // Four decimals, as the column and the web forms keep: a price per
            // piece is often below a cent.
            'unit_price_target' => ['nullable', 'numeric', 'min:0.0001', 'max:99999999.9999'],
            'percent_under_best' => ['nullable', 'numeric', 'min:0.01', 'max:99.99'],
            'pack_price' => ['nullable', 'numeric', 'min:0.01'],
            'shop_id' => ['nullable', 'uuid'],
            // Gone: a drop or a price for any pack is no longer an alert. An
            // unknown key is otherwise ignored, so an old caller would think
            // it had set one.
            'percent' => ['prohibited'],
            'amount' => ['prohibited'],
            'target_price' => ['prohibited'],
        ], [
            'percent.prohibited' => 'percent is no longer supported. Use percent_under_best: a target that percentage under today\'s best price per unit.',
            'amount.prohibited' => 'amount is no longer supported. Use pack_price: the price for the pack you buy, with shop_id.',
            'target_price.prohibited' => 'target_price is no longer supported. Use pack_price: the price for the pack you buy, with shop_id.',
        ]);

        $product = $this->ownedProduct($request, $this->str($validated, 'product_id'));

        if ($product === null) {
            return Response::error('No such product.');
        }

        $given = array_filter(
            ['unit_price_target', 'percent_under_best', 'pack_price'],
            fn (string $key): bool => ($validated[$key] ?? null) !== null,
        );

        if (count($given) !== 1) {
            return Response::error('Give exactly one of unit_price_target, percent_under_best or pack_price.');
        }

        $shopId = is_string($validated['shop_id'] ?? null) ? $validated['shop_id'] : null;

        if ($shopId !== null && ! $product->shops()->whereKey($shopId)->exists()) {
            return Response::error('No such shop on this product.');
        }

        // Under the lock a price check takes: the unit a target is stored in
        // is read here, and a check moving the product to another unit in
        // between would store the number in a unit nobody chose.
        $result = DB::transaction(function () use ($product, $validated, $shopId): Product|string {
            $locked = Product::query()->with('shops')->lockForUpdate()->findOrFail($product->id);
            $target = self::target($locked, $validated, $shopId);

            if (is_string($target)) {
                return $target;
            }

            $locked->forceFill([
                'unit_price_target' => $target['target'],
                'unit_price_target_unit' => $target['unit'],
                'unit_price_target_shop_id' => $target['shop'],
                'unit_price_target_effective' => UnitTargetConversion::effective($target['target'], $target['unit'], $locked->comparablePacks()),
            ])->save();

            return $locked;
        });

        if (is_string($result)) {
            return Response::error($result);
        }

        $summary = $this->presenter->summary($result);
        $note = $result->unit_price_target_unit === UnitTargetConversion::PACK
            ? self::packNote($result)
            : self::shopsOutsideTheUnitGroup($result);

        if ($note !== '') {
            $summary['note'] = $note;
        }

        return Response::structured($summary);
    }

    /**
     * The target to store, or the reason it cannot be set.
     *
     * @param  array<string, mixed>  $validated
     * @return array{target: string, unit: string, shop: ?string}|string
     */
    private static function target(Product $product, array $validated, ?string $shopId): array|string
    {
        $packs = $product->comparablePacks();
        $unitTarget = $validated['unit_price_target'] ?? null;
        $percent = $validated['percent_under_best'] ?? null;
        $packPrice = $validated['pack_price'] ?? null;

        if (is_numeric($unitTarget)) {
            return self::unitTarget($packs, $unitTarget);
        }

        // Only a shop a shopper can buy from: a target pinned to any other
        // would be paused the moment it is set.
        $shop = $shopId === null ? $product->bestValueShop() : $product->eligibleShops()->first(fn (Shop $candidate): bool => $candidate->id === $shopId);

        if ($shopId !== null && $shop === null) {
            return 'That shop is sold out, no longer tracked, or not a price a shopper pays. Pick another shop_id from get_product.';
        }

        if (is_numeric($percent)) {
            return self::percentTarget($product, $packs, $shop, (string) $percent);
        }

        return is_numeric($packPrice)
            ? self::packPriceTarget($packs, $shop, (string) $packPrice)
            : 'Give exactly one of unit_price_target, percent_under_best or pack_price.';
    }

    /**
     * @param  int|float|numeric-string  $value
     * @return array{target: string, unit: string, shop: ?string}|string
     */
    private static function unitTarget(ComparablePacks $packs, int|float|string $value): array|string
    {
        $unit = $packs->unit();

        if ($unit === null) {
            return 'No shop on this product has a pack size yet, so it has no price per unit. Use pack_price with shop_id: the price for the pack you buy at that shop.';
        }

        return ['target' => self::fourDecimalsDown($value), 'unit' => $unit, 'shop' => null];
    }

    /**
     * Under today's best per unit, off the winner's pack price; while nobody
     * wins, off the price of `$shop`'s pack.
     *
     * @return array{target: string, unit: string, shop: ?string}|string
     */
    private static function percentTarget(Product $product, ComparablePacks $packs, ?Shop $shop, string $percent): array|string
    {
        $winner = $product->bestValueShop();
        $winnerSize = $winner === null ? null : $packs->for($winner)?->size;
        $unit = $packs->unit();

        if ($winner?->current_price !== null && $winnerSize !== null && $unit !== null) {
            return self::stored(UnitTargetMath::percentUnderPack((string) $winner->current_price, $percent, UnitTargetMath::perPack($winnerSize)), $unit, null, 'That percentage leaves no target.');
        }

        if ($shop?->current_price === null) {
            return 'No shop can be compared per unit yet. Give shop_id: the shop whose pack price the percentage comes off.';
        }

        return self::stored(UnitTargetMath::percentUnderPack((string) $shop->current_price, $percent, '1'), UnitTargetConversion::PACK, (string) $shop->id, 'That percentage leaves no target.');
    }

    /**
     * A pack whose size the shop or the owner states sets a price per
     * unit. A size borrowed from the other shops can be another pack, so any
     * other pack is compared with its own shop's price only.
     *
     * @return array{target: string, unit: string, shop: ?string}|string
     */
    private static function packPriceTarget(ComparablePacks $packs, ?Shop $shop, string $packPrice): array|string
    {
        if ($shop === null) {
            return 'No shop can be compared per unit yet. Give shop_id: the shop whose pack this price is for.';
        }

        $unit = $packs->unit();
        $pack = $packs->for($shop);

        if ($unit !== null && $pack?->size !== null && $pack->isOwnSize()) {
            return self::stored(UnitTargetMath::fromPackPrice($packPrice, UnitTargetMath::perPack($pack->size)), $unit, null, 'That price leaves no target.');
        }

        return self::stored(UnitTargetMath::fromPackPrice($packPrice, '1'), UnitTargetConversion::PACK, (string) $shop->id, 'That price leaves no target.');
    }

    /**
     * @return array{target: string, unit: string, shop: ?string}|string
     */
    private static function stored(?string $target, string $unit, ?string $shopId, string $refusal): array|string
    {
        return $target === null ? $refusal : ['target' => $target, 'unit' => $unit, 'shop' => $shopId];
    }

    private static function packNote(Product $product): string
    {
        $shop = $product->shops->first(fn (Shop $shop): bool => $shop->id === $product->unit_price_target_shop_id);

        return $shop === null
            ? ''
            : sprintf('Compared with the price at %s only, until it says how much is in its pack. Then it becomes a price per kilo, litre or piece.', $shop->host);
    }

    /**
     * Says so when a per-unit target cannot reach some of the product's shops.
     *
     * Shops can report different units — 30 pieces at one, 840 g at another —
     * and the comparison runs in the unit that brings in the most shops, so a
     * shop with no size in that unit can never satisfy a target set in it. The target is
     * still stored: the caller asked for it, most of the shops answer it, and
     * a unit can change when a shop next reads a pack size. What was missing
     * was anyone saying that part of the product is out of scope.
     */
    private static function shopsOutsideTheUnitGroup(Product $product): string
    {
        // The resolver's own exclusions, so this note names the shops the page
        // marks as in another unit or sold by the piece. A shop that also
        // states the pack in the product's unit is inside.
        $packs = $product->comparablePacks();
        $unit = $packs->unit();

        if ($unit === null) {
            return '';
        }

        $withPack = $product->shops->filter(fn (Shop $shop): bool => $shop->ownOrOwnerPackSize() !== null);

        // A shop given an estimated size from its count is in the list as an
        // estimate, but it reports pieces and can never reach a per-unit target.
        $outside = $withPack->filter(fn (Shop $shop): bool => in_array(
            $packs->for($shop)?->exclusion,
            [PackExclusion::UnitDoesNotConvert, PackExclusion::SoldByThePiece],
            strict: true,
        ) || $packs->for($shop)?->provenance === PackProvenance::Converted);

        if ($outside->isEmpty()) {
            return '';
        }

        return sprintf(
            '%d of %d shops report %s, and this target is compared %s. %s %s %s, so the target never reaches %s.',
            $withPack->count() - $outside->count(),
            $withPack->count(),
            self::unitNoun($unit),
            self::unitPhrase($unit),
            $outside->map(fn (Shop $shop): string => (string) $shop->host)->values()->implode(', '),
            $outside->count() === 1 ? 'reports' : 'report',
            self::unitNoun((string) $outside->first()->ownOrOwnerPackSize()?->unit),
            $outside->count() === 1 ? 'it' : 'them',
        );
    }

    /**
     * Cut to four decimals, never rounded up, so a stored target is never
     * above the figure the caller asked for — the same as the web forms.
     *
     * @param  int|float|numeric-string  $value
     */
    private static function fourDecimalsDown(int|float|string $value): string
    {
        // PHP writes a float as its shortest exact form, so 0.012599999999
        // stays itself; a formatted float would round it to 0.0126 first.
        $decimal = is_string($value) ? $value : (string) $value;

        if (stripos($decimal, 'e') !== false) {
            $decimal = sprintf('%.20F', (float) $value);
        }

        return bcadd(Numeric::str($decimal), '0', 4);
    }

    private static function unitPhrase(string $unit): string
    {
        return match ($unit) {
            'g' => 'per kilo',
            'ml' => 'per litre',
            UnitTargetConversion::PACK => 'per pack',
            default => 'per piece',
        };
    }

    private static function unitNoun(string $unit): string
    {
        return match ($unit) {
            'g' => 'grams',
            'ml' => 'millilitres',
            UnitTargetConversion::PACK => 'packs',
            default => 'pieces',
        };
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->string()->format('uuid')->description('From list_products.')->required(),
            'unit_price_target' => $schema->number()->description('Alert when the best value reaches this price per kg, litre or piece (comparison_unit), up to four decimals (e.g. 0.0125 per piece). It holds every shop and pack size to one rate.'),
            'percent_under_best' => $schema->number()->description('Set the target this many percent under today\'s best price per unit, e.g. 10. With no price per unit yet, it comes off the price of shop_id\'s pack.'),
            'pack_price' => $schema->number()->description('The price for the pack you buy at shop_id (default: the best value). With a known pack size it becomes a price per unit; without one, only that shop\'s price is compared.'),
            'shop_id' => $schema->string()->format('uuid')->description('With pack_price or percent_under_best: the shop whose pack is meant, from get_product. Needed while no shop can be compared per unit.'),
        ];
    }
}
