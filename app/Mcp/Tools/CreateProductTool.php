<?php declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\Products\CreateProductWithShop;
use App\Actions\Products\ProductDraft;
use App\Actions\Shops\ProbeOutcome;
use App\Actions\Shops\ProbeShopUrl;
use App\Actions\Shops\ShopDraft;
use App\Billing\PlanLimitReached;
use App\Enums\ProductCategory;
use App\Mcp\Concerns\InteractsWithOwner;
use App\Mcp\Support\DraftFailure;
use App\Mcp\Support\DraftToken;
use App\Mcp\Support\ProbeReporter;
use App\Mcp\Support\ProductPresenter;
use App\Models\Product;
use Illuminate\Contracts\Database\Eloquent\Builder;
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

#[Name('create_product')]
#[Title('Create product')]
#[Description('Starts tracking a product from a shop URL. Call without `draft` first to see what the page says, show that to the user, then call again with the returned `draft` and confirm: true.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class CreateProductTool extends Tool
{
    use InteractsWithOwner;

    public function __construct(
        private readonly ProbeShopUrl $probe,
        private readonly ProbeReporter $reporter,
        private readonly ProductPresenter $presenter,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        // Both arguments key on `confirm`, because `confirm` is the only thing
        // the handler below branches on. Keyed on each other instead, a draft
        // sent without `confirm` left `url` unrequired, and the preview branch
        // then probed an empty string — answering "that does not look like a
        // URL" at a caller that sent no URL and a perfectly good draft.
        $validated = $request->validate([
            'url' => ['required_unless:confirm,true', 'nullable', 'string', 'max:2048'],
            'draft' => ['required_if:confirm,true', 'prohibited_unless:confirm,true', 'nullable', 'string'],
            // `strict`, because the branch below compares with `===`. Plain
            // `boolean` accepts 1 and "1", which pass every rule here and then
            // fail that comparison — the same empty-URL probe, reached through
            // a value the caller meant as a confirmation. Refusing it is also
            // the safer half: confirming is the step that writes.
            'confirm' => ['nullable', 'boolean:strict'],
            'title' => ['nullable', 'string', 'max:255'],
            'variant_key' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', Rule::enum(ProductCategory::class)],
        ], [
            'url.required_unless' => 'Pass a url to preview a product page.',
            'confirm.boolean' => 'confirm takes true or false, not 1 or 0.',
            'draft.prohibited_unless' => 'A draft only creates the product when confirm is true. Call again with the same draft and confirm: true.',
            'draft.required_if' => 'Pass the draft from the preview call alongside confirm: true.',
        ]);

        $user = $this->user($request);

        if (($validated['confirm'] ?? false) === true) {
            $draft = DraftToken::open($user, $this->str($validated, 'draft'));

            if ($draft instanceof DraftFailure) {
                return Response::error($draft->message('create_product'));
            }

            try {
                $product = app(CreateProductWithShop::class)(
                    $user,
                    new ProductDraft(
                        title: $this->productTitle($validated, $draft),
                        category: is_string($validated['category'] ?? null) ? ProductCategory::from($validated['category']) : null,
                    ),
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
            'draft' => DraftToken::issue($user, $snapshot, (string) $outcome->normalizedUrl, (string) $outcome->adapterKey, $variantKey),
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
            'title' => $schema->string()->description('Overrides the title read from the page. DipCatch already strips the shop name, "kopen" and Shopify\'s "- Default Title"; pass this when what is left still is not the product\'s name — brand, product, flavour, pack size, nothing else.'),
            'variant_key' => $schema->string()->description('Which variant to track, when the previous call reported several.'),
            'category' => $schema->string()->description('A category key from list_categories, such as "food.coffee_tea". Stored as the user\'s own choice, so nothing sorts the product again.'),
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
