<?php declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\ProductCategory;
use App\Mcp\Concerns\InteractsWithOwner;
use App\Mcp\Support\ProductPresenter;
use App\Models\Product;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
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

#[Name('list_products')]
#[Title('List products')]
#[Description('Lists every product this user tracks, with the figure the app leads with (`headline_price`: per kilo, litre or piece when `headline_price_basis` is "unit", else the pack price), its current cheapest pack price and how many shops it has. Compare products on the unit price. Filter by a department or category key from list_categories.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class ListProductsTool extends Tool
{
    use InteractsWithOwner;

    public function __construct(private readonly ProductPresenter $presenter) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate(['category' => ['nullable', 'string', 'max:64']]);
        $filter = is_string($validated['category'] ?? null) ? $validated['category'] : '';
        $categories = ProductCategory::leavesFor($filter);

        // A key the taxonomy does not know is an error, not an unfiltered
        // list: an assistant that mistyped a key must not report every
        // product as belonging to it.
        if ($filter !== '' && $categories === null) {
            return Response::error('No such category.');
        }

        $products = Product::query()
            ->where('user_id', $this->user($request)->getKey())
            ->when($categories !== null, fn (EloquentQueryBuilder $query): EloquentQueryBuilder => $query->whereIn('category', $categories ?? []))
            ->with(['cheapestShop', 'shops'])
            ->orderBy('title')
            ->get();

        return Response::structured([
            'products' => $products->map($this->presenter->summary(...))->all(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema->string()->description('A department key ("food") or a category key ("food.coffee_tea") from list_categories. Leave out for every product.'),
        ];
    }
}
