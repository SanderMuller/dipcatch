<?php declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\Products\CategoriseProduct;
use App\Enums\ProductCategory;
use App\Mcp\Concerns\InteractsWithOwner;
use App\Mcp\Support\ProductPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
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

/**
 * A category could be chosen when a product was created and never afterwards,
 * so a product that predates the taxonomy could only be sorted by deleting it
 * and creating it again — which throws away its price history. The same hole
 * `set_title` fills for names.
 *
 * Its own tool rather than an argument on `set_title`: a client caches the
 * schema of a tool it already knows, so a new argument on an existing tool can
 * stay invisible for the life of a connection, while a new tool arrives.
 */
#[Name('set_category')]
#[Title('Set product category')]
#[Description('Files a tracked product under one of the categories from list_categories, or clears it. Prices, shops and alert settings are untouched.')]
#[IsReadOnly(false)]
#[IsDestructive]
#[IsOpenWorld(false)]
final class SetCategoryTool extends Tool
{
    use InteractsWithOwner;

    public function __construct(private readonly ProductPresenter $presenter) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        // The default message is "The selected category is invalid", which
        // leaves a caller that sent a department key ("food") with no idea
        // that a department is the mistake, so it retries the same shape.
        $validated = $request->validate([
            'product_id' => ['required', 'uuid'],
            'category' => ['present', 'nullable', 'string', Rule::in(ProductCategory::values())],
        ], [
            'category.in' => 'No such category. A department key like "food" is not a category: call list_categories and pick one of the categories inside the department, such as "food.snacks_sweets".',
        ]);

        $product = $this->ownedProduct($request, $this->str($validated, 'product_id'));

        if ($product === null) {
            return Response::error('No such product.');
        }

        CategoriseProduct::chooseByUser($product, ProductCategory::tryFrom($this->str($validated, 'category')));
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
            'category' => $schema->string()->description('A category key from list_categories, e.g. "food.snacks_sweets". Send null to clear it. A department key is not a category: pick one of the categories inside it.')->required(),
        ];
    }
}
