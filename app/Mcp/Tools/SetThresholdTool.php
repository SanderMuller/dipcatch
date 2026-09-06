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
#[Description('Sets how far a price must fall before this product alerts: a percentage, an absolute amount, or both. Omit a value to leave it as it is.')]
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
        ]);

        $product = $this->ownedProduct($request, $this->str($validated, 'product_id'));

        if ($product === null) {
            return Response::error('No such product.');
        }

        $percent = $validated['percent'] ?? null;
        $amount = $validated['amount'] ?? null;

        if ($percent === null && $amount === null) {
            return Response::error('Give a percent, an amount, or both.');
        }

        if (is_numeric($percent)) {
            $product->drop_threshold_pct = round((float) $percent, 2);
        }

        if (is_numeric($amount)) {
            $product->drop_threshold_abs = round((float) $amount, 2);
        }

        $product->save();

        return Response::structured($this->presenter->summary($product));
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
        ];
    }
}
