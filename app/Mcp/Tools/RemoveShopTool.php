<?php declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\InteractsWithOwner;
use App\Mcp\Support\ProductPresenter;
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

#[Name('remove_shop')]
#[Title('Remove shop')]
#[Description('Stops tracking one shop of a product. The product and its other shops stay.')]
#[IsReadOnly(false)]
#[IsDestructive]
#[IsOpenWorld(false)]
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

        // One transaction: `Shop::booted()` has no deleted hook, so the
        // recompute is this tool's job, and a failure between the two would
        // leave `cheapest_shop_id` pointing at a row that no longer exists.
        DB::transaction(function () use ($shop, $product): void {
            $shop->delete();
            $product->recomputeCheapestShop();
        });
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
