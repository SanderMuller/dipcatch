<?php declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\InteractsWithOwner;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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

#[Name('price_history')]
#[Title('Price history')]
#[Description('How the cheapest price of a product has moved, as dated segments. Each carries the pack size it was written under, so `unit_price` reflects what was known then rather than the shop\'s size today; a segment recorded before pack sizes existed answers null. Free plans see a shorter window than Pro.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class PriceHistoryTool extends Tool
{
    use InteractsWithOwner;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate(['product_id' => ['required', 'uuid']]);
        $product = $this->ownedProduct($request, $this->str($validated, 'product_id'));

        if ($product === null) {
            return Response::error('No such product.');
        }

        // The plan's own retention window, the same number the charts use.
        $days = $this->user($request)->entitlements()->historyDays();
        $cutoff = $days === null ? null : CarbonImmutable::now()->subDays($days);

        $rows = $product->cheapestHistory()
            ->overlapping($cutoff)
            ->inOrder()
            ->get();

        $segments = [];

        foreach ($rows as $segment) {
            $started = $segment->started_at;
            $ended = $segment->ended_at;

            $segments[] = [
                'price' => is_scalar($segment->cheapest_price) ? (string) $segment->cheapest_price : null,
                // The size this segment was written under, and the unit price
                // that follows from it — not from the shop's size today. A pack
                // size corrected later must not rescale a month of readings, so
                // a segment that recorded none answers null rather than a
                // plausible number.
                'pack_quantity' => $segment->packSize()?->quantity,
                'pack_unit' => $segment->packSize()?->unit,
                'unit_price' => $segment->unitPrice(),
                'single_item_price' => $segment->singleItemPrice(),
                'bundle_quantity' => $segment->bundleOffer()?->quantity,
                'bundle_total_price' => $segment->bundleOffer()?->totalPrice,
                'from' => $started instanceof CarbonInterface ? $started->toIso8601String() : null,
                'until' => $ended instanceof CarbonInterface ? $ended->toIso8601String() : null,
            ];
        }

        return Response::structured([
            'product_id' => $this->str($validated, 'product_id'),
            'currency' => $product->currency,
            'truncated_to' => $cutoff?->toDateString(),
            'segments' => $segments,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->string()->format('uuid')->description('From list_products.')->required(),
        ];
    }
}
