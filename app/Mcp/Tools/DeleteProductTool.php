<?php declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\InteractsWithOwner;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('delete_product')]
#[Description('Stops tracking a product and removes its shops and price history. Cannot be undone; confirm with the user first.')]
class DeleteProductTool extends Tool
{
    use InteractsWithOwner;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate(['product_id' => ['required', 'uuid']]);
        $product = $this->ownedProduct($request, $this->str($validated, 'product_id'));

        if ($product === null) {
            return Response::error('No such product.');
        }

        $title = $product->title;
        $product->delete();

        return Response::structured(['deleted' => true, 'title' => $title]);
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
