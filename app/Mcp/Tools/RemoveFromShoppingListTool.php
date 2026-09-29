<?php declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\InteractsWithOwner;
use App\Mcp\Support\ProductPresenter;
use App\Models\Product;
use App\Support\ShoppingList;
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

#[Name('remove_from_shopping_list')]
#[Title('Remove from shopping list')]
#[Description('Takes products off the shopping list. The products stay tracked; only the list changes. Returns the whole list after the change.')]
#[IsReadOnly(false)]
#[IsDestructive]
#[IsOpenWorld(false)]
final class RemoveFromShoppingListTool extends Tool
{
    use InteractsWithOwner;

    public function __construct(private readonly ProductPresenter $presenter) {}

    public function handle(Request $request): ResponseFactory
    {
        $validated = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'product_ids.*' => ['required', 'uuid'],
        ]);

        $ids = $this->uuids($validated, 'product_ids');
        $products = $this->ownedProducts($request, $ids);
        $removed = [];

        foreach ($products as $product) {
            if ($product->isOnShoppingList()) {
                $product->removeFromShoppingList();
                $removed[] = $this->key($product);
            }
        }

        return Response::structured([
            'removed' => $removed,
            'not_on_list' => array_values(array_diff($products->map(fn (Product $product): string => $this->key($product))->all(), $removed)),
            'not_found' => array_values(array_diff($ids, $products->map(fn (Product $product): string => $this->key($product))->all())),
            'list' => $this->presenter->shoppingList(ShoppingList::forUser($this->user($request))),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'product_ids' => $schema->array()->items($schema->string()->format('uuid'))->min(1)->max(1000)->description('From shopping_list or list_products.')->required(),
        ];
    }
}
