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

/**
 * Renaming used to mean deleting the product and creating it again, which
 * threw away every price ever recorded for it. The name is the one thing on a
 * product that says nothing about its prices, so it can be changed on its own.
 */
#[Name('set_title')]
#[Title('Rename product')]
#[Description('Renames a tracked product. The name is stored exactly as given. The prices, shops and alert settings are untouched — this only changes the name shown in lists and alerts.')]
#[IsReadOnly(false)]
#[IsDestructive]
#[IsOpenWorld(false)]
final class SetTitleTool extends Tool
{
    use InteractsWithOwner;

    public function __construct(private readonly ProductPresenter $presenter) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'product_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
        ]);

        $product = $this->ownedProduct($request, $this->str($validated, 'product_id'));

        if ($product === null) {
            return Response::error('No such product.');
        }

        // Stored as it was given, like the title override on create_product
        // and the title field in the web form. A name the automatic cleanup
        // got wrong is exactly what this tool is for, so cleaning it again
        // here would put the tool's own purpose out of reach.
        $title = trim($this->str($validated, 'title'));

        $product->title = $title;
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
            'title' => $schema->string()->description('The new name: brand, product, flavour, pack size. Leave out the shop name and words like "kopen".')->required(),
        ];
    }
}
