<?php declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\Shops\ProbeBudget;
use App\Jobs\CheckShopPrice;
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

#[Name('recheck')]
#[Title('Recheck prices')]
#[Description('Reads the shop pages of a tracked product again, right now, and returns what they say. Use it when the stored price or stock looks wrong. Rechecks otherwise run on a schedule, so a stored value can be hours old.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class RecheckTool extends Tool
{
    use InteractsWithOwner;

    public function __construct(
        private readonly ProductPresenter $presenter,
        private readonly ProbeBudget $budget,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'product_id' => ['required', 'uuid'],
            'shop_id' => ['nullable', 'uuid'],
        ]);

        $product = $this->ownedProduct($request, $this->str($validated, 'product_id'));

        if ($product === null) {
            return Response::error('No such product.');
        }

        $shopId = is_string($validated['shop_id'] ?? null) ? $validated['shop_id'] : null;

        $shopsQuery = $product->shops();

        if ($shopId !== null) {
            $shopsQuery->whereKey($shopId);
        }

        $shops = $shopsQuery->get();

        if ($shops->isEmpty()) {
            return Response::error($shopId === null
                ? 'That product has no shops to recheck.'
                : 'That shop is not on this product.');
        }

        $rechecked = 0;
        $waitSeconds = null;

        foreach ($shops as $shop) {
            $waitSeconds = $this->budget->spend($this->user($request));

            if ($waitSeconds !== null) {
                break;
            }

            dispatch_sync(new CheckShopPrice($shop));
            $rechecked++;
        }

        $product->refresh()->load('shops');

        return Response::structured([
            ...$this->presenter->detail($product),
            'rechecked' => $rechecked,
            'skipped' => $shops->count() - $rechecked,
            // The page budget is shared with add_shop and create_product, so
            // a caller that just added shops has less of it left.
            'note' => $waitSeconds === null
                ? null
                : 'The page budget ran out after ' . $rechecked . ' of ' . $shops->count()
                    . '. Call recheck again in ' . $waitSeconds . ' seconds for the rest.',
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->string()->format('uuid')->description('The product to read again.')->required(),
            'shop_id' => $schema->string()->format('uuid')->description('One shop of that product. Omit to recheck every shop on it.'),
        ];
    }
}
