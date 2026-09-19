<?php declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\InteractsWithOwner;
use App\Mcp\Support\ProductPresenter;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
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
#[Description('Sets when this product alerts: how far the price must fall (a percentage, an absolute amount, or both), a price to reach, and/or a unit price to reach. Omit a value to leave it as it is.')]
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
            'percent' => ['nullable', 'numeric', 'min:0.01', 'max:99.99'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'target_price' => ['nullable', 'numeric', 'min:0.01'],
            'unit_price_target' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $product = $this->ownedProduct($request, $this->str($validated, 'product_id'));

        if ($product === null) {
            return Response::error('No such product.');
        }

        $percent = $validated['percent'] ?? null;
        $amount = $validated['amount'] ?? null;
        $targetPrice = $validated['target_price'] ?? null;
        $unitPriceTarget = $validated['unit_price_target'] ?? null;

        if ($percent === null && $amount === null && $targetPrice === null && $unitPriceTarget === null) {
            return Response::error('Give a percent, an amount, a target_price, a unit_price_target, or several of them.');
        }

        if (is_numeric($percent)) {
            $product->drop_threshold_pct = round((float) $percent, 2);
        }

        if (is_numeric($amount)) {
            $product->drop_threshold_abs = round((float) $amount, 2);
        }

        if (is_numeric($targetPrice)) {
            $product->target_price = round((float) $targetPrice, 2);
        }

        if (is_numeric($unitPriceTarget)) {
            $product->unit_price_target = round((float) $unitPriceTarget, 2);
        }

        $product->save();

        $summary = $this->presenter->summary($product);

        $notes = [];

        // A stored target on a free account is kept and starts working on
        // upgrade, so say that rather than let the caller promise an alert
        // that will not arrive.
        if ($unitPriceTarget !== null && ! $this->user($request)->entitlements()->allowsUnitPriceAlerts()) {
            $notes[] = 'The unit price target is stored, but unit-price alerts are a Pro feature. This account is not alerted on it until it upgrades.';
        }

        if ($unitPriceTarget !== null) {
            $excluded = self::shopsOutsideTheUnitGroup($product);

            if ($excluded !== '') {
                $notes[] = $excluded;
            }
        }

        if ($notes !== []) {
            $summary['note'] = implode(' ', $notes);
        }

        return Response::structured($summary);
    }

    /**
     * Says so when a per-unit target cannot reach some of the product's shops.
     *
     * Shops can report different units — 30 pieces at one, 840 g at another —
     * and the comparison runs inside the largest group only, so a shop outside
     * it can never satisfy a target set in the group's unit. The target is
     * still stored: the caller asked for it, most of the shops answer it, and
     * a unit can change when a shop next reads a pack size. What was missing
     * was anyone saying that part of the product is out of scope.
     */
    private static function shopsOutsideTheUnitGroup(Product $product): string
    {
        $unit = $product->unitPriceUnit();

        if ($unit === null) {
            return 'No shop on this product has read a pack size yet, so there is nothing to compare per unit until one does.';
        }

        $withPack = $product->shops->filter(
            fn (Shop $shop): bool => is_string($shop->pack_unit) && $shop->pack_unit !== '',
        );

        $outside = $withPack->filter(fn (Shop $shop): bool => $shop->pack_unit !== $unit);

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
            self::unitNoun((string) $outside->first()->pack_unit),
            $outside->count() === 1 ? 'it' : 'them',
        );
    }

    private static function unitPhrase(string $unit): string
    {
        return match ($unit) {
            'g' => 'per kilo',
            'ml' => 'per litre',
            default => 'per piece',
        };
    }

    private static function unitNoun(string $unit): string
    {
        return match ($unit) {
            'g' => 'grams',
            'ml' => 'millilitres',
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
            'percent' => $schema->number()->description('Alert when the price falls this many percent, e.g. 10.'),
            'amount' => $schema->number()->description('Alert when the price falls by at least this much money.'),
            'target_price' => $schema->number()->description('Alert when the lowest price per item reaches this amount. If that price requires a multi-buy, the result says how many items to buy. Available on Free and Pro.'),
            'unit_price_target' => $schema->number()->description('Alert when the best value reaches this price per kg, litre or piece. Pro accounts only — for a pack price on any plan, use target_price.'),
        ];
    }
}
