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

#[Name('add_to_shopping_list')]
#[Title('Add to shopping list')]
#[Description('Puts tracked products on the shopping list, as many as the user names in one call. Take the ids from list_products; a product that is not tracked yet needs create_product first. A crossed-off product goes back on the list un-crossed. Returns the whole list after the change.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class AddToShoppingListTool extends Tool
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
        $added = [];

        foreach ($products as $product) {
            // Left alone, so it keeps its place in the list.
            if ($product->isOnShoppingList() && ! $product->isCrossedOff()) {
                continue;
            }

            $product->addToShoppingList();
            $added[] = $this->key($product);
        }

        return Response::structured([
            'added' => $added,
            'already_on_list' => array_values(array_diff($products->map(fn (Product $product): string => $this->key($product))->all(), $added)),
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
            'product_ids' => $schema->array()->items($schema->string()->format('uuid'))->min(1)->max(1000)->description('From list_products.')->required(),
        ];
    }
}
