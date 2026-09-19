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
 * The picture a product shows, taken from one of its own shops.
 *
 * Deliberately not a URL. An image address read off a product page is content
 * the shop controls, and a caller pasting one has no way to know whether it is
 * the product, a banner, or something worse — a page can advertise any
 * `og:image` it likes. Naming a shop instead means nothing new enters the
 * system: every candidate is a page this user already chose to track, read on
 * a price check we made ourselves.
 *
 * The web form has offered the same choice all along, as a row of thumbnails
 * labelled by host; this is that picker for a client that cannot see it.
 */
#[Name('set_image')]
#[Title('Set product image')]
#[Description("Shows the picture one of the product's own shops reported. Call get_product first: each shop lists the image it has, and this takes the shop whose picture to use.")]
#[IsReadOnly(false)]
#[IsDestructive]
#[IsOpenWorld(false)]
final class SetImageTool extends Tool
{
    use InteractsWithOwner;

    public function __construct(private readonly ProductPresenter $presenter) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'product_id' => ['required', 'uuid'],
            'shop_id' => ['required', 'uuid'],
        ]);

        $product = $this->ownedProduct($request, $this->str($validated, 'product_id'));

        if ($product === null) {
            return Response::error('No such product.');
        }

        // Scoped through the product, not just through the owner: a shop of
        // another product of this user's would otherwise pass, and its picture
        // is of a different thing.
        $shop = $product->shops()->find($this->str($validated, 'shop_id'));

        if ($shop === null) {
            return Response::error('That shop is not on this product.');
        }

        $image = $shop->safeImageUrl();

        if ($image === null) {
            return Response::error('That shop has no picture yet. Its next price check reads one, or another shop may already have it.');
        }

        $product->image_url = $image;
        $product->save();

        return Response::structured($this->presenter->detail($product->load('shops')));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->string()->format('uuid')->description('From list_products.')->required(),
            'shop_id' => $schema->string()->format('uuid')->description('From get_product: the shop whose picture to show. Only a shop already on this product is accepted, and only its own picture is used — an image address cannot be passed in.')->required(),
        ];
    }
}
