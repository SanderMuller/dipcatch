<?php declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\Shops\SaveOwnerPackSize;
use App\Mcp\Concerns\InteractsWithOwner;
use App\Mcp\Support\ProductPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
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

#[Name('set_pack_size')]
#[Title('Set pack size')]
#[Description('States how much is in the pack the user buys at one shop, for a shop whose page states no size: "660 g", "1 l", "4 pieces". It gives the product a price per kg, litre or piece, and a target per pack on that shop becomes one. The shop\'s own size wins once its page states one. An empty pack_size removes it.')]
#[IsReadOnly(false)]
#[IsDestructive]
#[IsOpenWorld(false)]
final class SetPackSizeTool extends Tool
{
    use InteractsWithOwner;

    public function __construct(private readonly ProductPresenter $presenter) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'shop_id' => ['required', 'uuid'],
            'pack_size' => ['present', 'nullable', 'string', 'max:64'],
        ]);

        // Scoped through the product: a shop id says nothing about who owns it.
        $shop = $this->ownedShop($request, $this->str($validated, 'shop_id'));
        $product = $shop?->product;

        if ($shop === null || $product === null) {
            return Response::error('No such shop.');
        }

        try {
            app(SaveOwnerPackSize::class)($product, (string) $shop->id, $this->str($validated, 'pack_size'));
        } catch (ValidationException $e) {
            return Response::error($e->validator->errors()->first());
        }

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
            'pack_size' => $schema->string()->description('How much is in the pack, such as "660 g", "1 l" or "4 pieces". Not a word for the pack itself ("1 pack", "a box"). Empty to remove.')->required(),
        ];
    }
}
