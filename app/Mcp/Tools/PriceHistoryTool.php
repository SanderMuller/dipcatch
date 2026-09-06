<?php declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\InteractsWithOwner;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('price_history')]
#[Description('How the cheapest price of a product has moved, as dated segments. Free plans see a shorter window than Pro.')]
class PriceHistoryTool extends Tool
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
            ->when($cutoff instanceof CarbonImmutable, fn (Builder $query): Builder => $query->where('started_at', '>=', $cutoff))
            ->inOrder()
            ->get();

        $segments = [];

        foreach ($rows as $segment) {
            $started = $segment->started_at;
            $ended = $segment->ended_at;

            $segments[] = [
                'price' => is_scalar($segment->cheapest_price) ? (string) $segment->cheapest_price : null,
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
