<?php declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\Products\CreateProductWithShop;
use App\Actions\Products\ProductDraft;
use App\Actions\Shops\ProbeOutcome;
use App\Actions\Shops\ProbeShopUrl;
use App\Actions\Shops\ShopDraft;
use App\Billing\PlanLimitReached;
use App\Mcp\Concerns\InteractsWithOwner;
use App\Mcp\Support\DraftToken;
use App\Mcp\Support\ProbeReporter;
use App\Mcp\Support\ProductPresenter;
use App\Models\Product;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create_product')]
#[Description('Starts tracking a product from a shop URL. Call without `draft` first to see what the page says, show that to the user, then call again with the returned `draft` and confirm: true.')]
class CreateProductTool extends Tool
{
    use InteractsWithOwner;

    public function __construct(
        private readonly ProbeShopUrl $probe,
        private readonly ProbeReporter $reporter,
        private readonly ProductPresenter $presenter,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'url' => ['required_without:draft', 'nullable', 'string', 'max:2048'],
            'draft' => ['required_if:confirm,true', 'nullable', 'string'],
            'confirm' => ['nullable', 'boolean'],
            'title' => ['nullable', 'string', 'max:255'],
            'variant_key' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $this->user($request);

        if (($validated['confirm'] ?? false) === true) {
            $draft = DraftToken::open($this->str($validated, 'draft'));

            if ($draft === null) {
                return Response::error('That draft has expired. Call create_product again without confirm to re-read the page.');
            }

            try {
                $product = app(CreateProductWithShop::class)(
                    $user,
                    new ProductDraft(title: $this->productTitle($validated, $draft)),
                    $draft,
                );
            } catch (PlanLimitReached $e) {
                return Response::error($e->getMessage());
            }

            $product->load('shops');

            return Response::structured($this->presenter->detail($product));
        }

        $variantKey = is_string($validated['variant_key'] ?? null) ? $validated['variant_key'] : null;

        $outcome = $this->runProbe($this->str($validated, 'url'), $request, $variantKey);

        if (! $outcome->isSuccess()) {
            return $this->reporter->explain($outcome);
        }

        $snapshot = ShopDraft::flatten($outcome);

        return Response::structured([
            'found' => $this->reporter->preview($snapshot, $outcome),
            'already_tracked_as' => $this->existingTitle($user->getKey(), $outcome->normalizedUrl),
            'draft' => DraftToken::issue($snapshot, (string) $outcome->normalizedUrl, (string) $outcome->adapterKey, $variantKey),
            'next' => 'Show this to the user. If they agree, call create_product again with the draft and confirm: true.',
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->description('A product page at a shop. Required unless confirming a draft.'),
            'draft' => $schema->string()->description('The draft token from the previous call.'),
            'confirm' => $schema->boolean()->description('Set true, with a draft, to actually create the product.'),
            'title' => $schema->string()->description('Overrides the title read from the page.'),
            'variant_key' => $schema->string()->description('Which variant to track, when the previous call reported several.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function productTitle(array $validated, ShopDraft $draft): string
    {
        $given = $validated['title'] ?? null;

        return is_string($given) && trim($given) !== '' ? trim($given) : $draft->title ?? 'Untitled product';
    }

    private function existingTitle(mixed $userId, ?string $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $product = Product::query()
            ->where('user_id', $userId)
            ->whereHas('shops', fn (Builder $query): Builder => $query->where('url', $url))
            ->first();

        return $product?->title;
    }

    private function runProbe(string $url, Request $request, ?string $variantKey): ProbeOutcome
    {
        return ($this->probe)(null, $url, $this->user($request), [], null, $variantKey);
    }
}
