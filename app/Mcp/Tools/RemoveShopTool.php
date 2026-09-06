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

#[Name('remove_shop')]
#[Description('Stops tracking one shop of a product. The product and its other shops stay.')]
class RemoveShopTool extends Tool
{
    use InteractsWithOwner;

    public function __construct(private readonly ProductPresenter $presenter) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate(['shop_id' => ['required', 'uuid']]);

        // Scoped through the product: a shop id says nothing about who owns it.
        $shop = $this->ownedShop($request, $this->str($validated, 'shop_id'));

        if ($shop === null) {
            return Response::error('No such shop.');
        }

        $product = $shop->product;

        if ($product === null) {
            return Response::error('No such shop.');
        }

        $shop->delete();

        // Shop::booted() has no deleted hook — on the web this recompute lives
        // in a Filament action, so a tool has to do it itself or the product
        // keeps pointing at a shop that is gone.
        $product->recomputeCheapestShop();
        $product->refresh()->load('shops');

        return Response::structured($this->presenter->detail($product));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'shop_id' => $schema->string()->format('uuid')->description('From get_product.')->required(),
        ];
    }
}
