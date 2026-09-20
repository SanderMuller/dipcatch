<?php declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\Shops\AttachShop;
use App\Actions\Shops\ProbeShopUrl;
use App\Actions\Shops\ShopDraft;
use App\Billing\PlanLimitReached;
use App\Mcp\Concerns\InteractsWithOwner;
use App\Mcp\Support\DraftFailure;
use App\Mcp\Support\DraftToken;
use App\Mcp\Support\ProbeReporter;
use App\Mcp\Support\ProductPresenter;
use App\Models\Product;
use App\Models\Shop;
use App\Support\PackSize;
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

#[Name('add_shop')]
#[Title('Add shop')]
#[Description('Tracks the same product at another shop. Add a shop even when its price is high today — tracking exists to catch a future drop, so the test is whether the page sells the same product, not whether it is cheap now. More shops means more chances to catch a promotion. Call without `draft` first to see what the page says, then again with the returned `draft` and confirm: true.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class AddShopTool extends Tool
{
    use InteractsWithOwner;

    public function __construct(
        private readonly ProbeShopUrl $probe,
        private readonly ProbeReporter $reporter,
        private readonly ProductPresenter $presenter,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        // One message per mistake. `required_without:draft` and
        // `required_unless:confirm,true` are each true too often on their own,
        // and either one alone leaves a case answering with two sentences that
        // steer opposite ways.
        $arguments = $request->all();
        $confirming = ($arguments['confirm'] ?? null) === true;
        $draftSent = ($arguments['draft'] ?? '') !== '';

        $validated = $request->validate([
            'product_id' => ['required', 'uuid'],
            'url' => [Rule::requiredIf(! $confirming && ! $draftSent), 'nullable', 'string', 'max:2048'],
            'draft' => ['required_if:confirm,true', 'prohibited_unless:confirm,true', 'nullable', 'string'],
            // `strict`, because 1 and "1" pass a plain `boolean` and then fail
            // the `=== true` below — the preview branch, on a call that meant
            // to confirm.
            'confirm' => ['nullable', 'boolean:strict'],
            'variant_key' => ['nullable', 'string', 'max:255'],
        ], [
            'url.required' => 'Pass a url to preview a product page.',
            'confirm.boolean' => 'confirm takes a JSON boolean: true or false, not a number and not a string.',
            'draft.prohibited_unless' => 'A draft adds the shop only with confirm: true. Send the draft again with confirm: true once the user has agreed to the preview, or drop the draft to preview a url instead.',
            'draft.required_if' => 'Pass the draft from the preview call alongside confirm: true.',
        ]);

        $product = $this->ownedProduct($request, $this->str($validated, 'product_id'));

        if ($product === null) {
            return Response::error('No such product.');
        }

        if ($confirming) {
            $draft = DraftToken::open($this->user($request), $this->str($validated, 'draft'), $this->key($product));

            if ($draft instanceof DraftFailure) {
                return Response::error($draft->message('add_shop'));
            }

            if ($product->shops()->where('url', $draft->url)->exists()) {
                return Response::error('That shop is already tracked on this product.');
            }

            try {
                app(AttachShop::class)($product, $draft);
            } catch (PlanLimitReached $e) {
                return Response::error($e->getMessage());
            }

            $product->refresh()->load('shops');

            return Response::structured($this->presenter->detail($product));
        }

        $variantKey = is_string($validated['variant_key'] ?? null) ? $validated['variant_key'] : null;

        // With the product, so a URL already on it comes back as a duplicate
        // before anything is fetched.
        $outcome = ($this->probe)($product, $this->str($validated, 'url'), $this->user($request), [], null, $variantKey);

        if (! $outcome->isSuccess()) {
            return $this->reporter->explain($outcome);
        }

        $snapshot = ShopDraft::flatten($outcome);

        $preview = ['found' => $this->reporter->preview($snapshot, $outcome)];

        $mismatch = self::packSizeMismatch($product, $snapshot);

        if ($mismatch !== null) {
            $preview['pack_size_note'] = $mismatch;
        }

        return Response::structured($preview + [
            'draft' => DraftToken::issue($this->user($request), $snapshot, (string) $outcome->normalizedUrl, (string) $outcome->adapterKey, $variantKey, $this->key($product)),
            'next' => 'Show this to the user. If they agree, call add_shop again with the draft and confirm: true.',
        ]);
    }

    /**
     * Says so when this page sells a different amount from the shops already
     * on the product.
     *
     * Not a refusal: a near-match is often what a person wants, and shops do
     * write the same pack differently. But a 227 g bag attached to a 333 g
     * product reads as a cheaper price rather than a smaller bag, and a caller
     * that does not compare the numbers itself has nothing to show the user.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private static function packSizeMismatch(Product $product, array $snapshot): ?string
    {
        $drafted = PackSize::resolve(
            is_string($snapshot['pack_size'] ?? null) ? $snapshot['pack_size'] : null,
            (bool) ($snapshot['pack_size_authoritative'] ?? false),
            is_string($snapshot['title'] ?? null) ? $snapshot['title'] : null,
        );

        if (! $drafted instanceof PackSize) {
            return null;
        }

        $existing = $product->shops
            ->map(self::packOf(...))
            ->filter()
            ->map(fn (PackSize $size): string => $size->quantity . '|' . $size->unit)
            ->unique();

        // Nothing to compare against, or the product already holds a mixture
        // the caller can see for itself in get_product.
        if ($existing->count() !== 1) {
            return null;
        }

        if ($existing->first() === $drafted->quantity . '|' . $drafted->unit) {
            return null;
        }

        return sprintf(
            'This page sells %s. The shops already on this product sell %s. That is a different pack, not a cheaper price — add it only if the user wants the smaller or larger size tracked alongside.',
            self::amount($drafted),
            self::amount($product->shops->map(self::packOf(...))->filter()->first()),
        );
    }

    /**
     * The pack a shop reports, read from its own columns. `Shop::packSize()`
     * is private, and a message is not a reason to widen it.
     */
    private static function packOf(Shop $shop): ?PackSize
    {
        if ($shop->pack_quantity === null || ! is_string($shop->pack_unit)) {
            return null;
        }

        return PackSize::of((float) $shop->pack_quantity, $shop->pack_unit);
    }

    private static function amount(?PackSize $size): string
    {
        if (! $size instanceof PackSize) {
            return 'an unknown amount';
        }

        return match ($size->unit) {
            'g' => rtrim(rtrim(number_format($size->quantity, 1, '.', ''), '0'), '.') . ' g',
            'ml' => rtrim(rtrim(number_format($size->quantity, 1, '.', ''), '0'), '.') . ' ml',
            default => rtrim(rtrim(number_format($size->quantity, 1, '.', ''), '0'), '.') . ' pieces',
        };
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->string()->format('uuid')->description('The product to add this shop to.')->required(),
            'url' => $schema->string()->description('A product page at another shop. Required unless confirm is true.'),
            'draft' => $schema->string()->description('The draft token from the previous call. Send it only alongside confirm: true; on its own it is refused.'),
            'confirm' => $schema->boolean()->description('Set true, with a draft, to actually add the shop.'),
            'variant_key' => $schema->string()->description('Which variant to track, when the previous call reported several.'),
        ];
    }
}
