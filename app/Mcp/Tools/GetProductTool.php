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
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_product')]
#[Title('Get product')]
#[Description('Reads one product: every shop it is tracked at, their prices, and both answers about them — `is_cheapest` is the smallest amount of money at the till, `is_best_value` the lowest price per kilo, litre or piece, and a drop alert fires on best value. They are often different shops. `headline_price` is the figure the app leads with: the best value per unit when `headline_price_basis` is "unit", else the lowest pack price. Compare on `*_unit_price`. A shop that cannot join the unit comparison carries `excluded_reason` saying which fact is missing; it is never silently absent. Each shop also says which reader produced its price in `read_by`. "checkjebon" is a daily dataset that carries no promotions, so a shop read that way shows its shelf price and never a multi-buy; "ah-api" and the shop adapters do read promotions.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class GetProductTool extends Tool
{
    use InteractsWithOwner;

    public function __construct(private readonly ProductPresenter $presenter) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate(['product_id' => ['required', 'uuid']]);
        $product = $this->ownedProduct($request, $this->str($validated, 'product_id'));

        if ($product === null) {
            return Response::error('No such product.');
        }

        $product->load('shops');

        return Response::structured($this->presenter->detail($product));
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
