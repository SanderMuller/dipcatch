<?php declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\InteractsWithOwner;
use App\Mcp\Support\ProductPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('set_threshold')]
#[Description('Sets when this product alerts: how far the price must fall (a percentage, an absolute amount, or both), a price to reach, and/or a unit price to reach. Omit a value to leave it as it is.')]
class SetThresholdTool extends Tool
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

        // A stored target on a free account is kept and starts working on
        // upgrade, so say that rather than let the caller promise an alert
        // that will not arrive.
        if ($unitPriceTarget !== null && $this->user($request)->entitlements()->allowsUnitPriceAlerts() !== true) {
            $summary['note'] = 'The unit price target is stored, but unit-price alerts are a Pro feature. This account is not alerted on it until it upgrades.';
        }

        return Response::structured($summary);
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
            'target_price' => $schema->number()->description('Alert when the cheapest shop reaches this price. This is the price on the shelf, not a fall, so it does not move with the current price. Free accounts get this alert.'),
            'unit_price_target' => $schema->number()->description('Alert when the best value reaches this price per kg, litre or piece. Pro accounts only — for a pack price on any plan, use target_price.'),
        ];
    }
}
