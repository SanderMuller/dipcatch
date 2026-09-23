<?php declare(strict_types=1);

use App\Enums\ShopKind;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;

function markdownProduct(User $user, string $title = 'Coffee beans'): Product
{
    return Product::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'title' => $title]);
}

it('serves the product page as markdown to its owner', function (): void {
    $user = User::factory()->create();
    $product = markdownProduct($user, 'Beans & more');
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/1', 'current_price' => '12.49', 'current_in_stock' => true]);
    $product->recomputeCheapestShop();

    $response = $this->actingAs($user)->get(route('app.products.markdown', $product));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/markdown; charset=utf-8');

    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');

    // Raw text, not HTML: the ampersand arrives as it was typed.
    expect($response->getContent())
        ->toStartWith("# Beans & more\n")
        ->toContain("## Best price now\n\n€12.49 at [jumbo.com](<https://jumbo.com/p/1>)")
        ->toContain('| [jumbo.com](<https://jumbo.com/p/1>) | €12.49 |')
        ->toContain('| In stock |');
});

it('names the best value apart from the lowest price, with the warning the page gives', function (): void {
    $user = User::factory()->create();
    $product = markdownProduct($user);
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/1', 'current_price' => '2.00', 'pack_quantity' => 500, 'pack_unit' => 'g']);
    Shop::factory()->for($product)->create(['url' => 'https://ah.nl/p/2', 'current_price' => '3.00', 'pack_quantity' => 1000, 'pack_unit' => 'g']);
    $product->recomputeCheapestShop();

    $markdown = $this->actingAs($user)->get(route('app.products.markdown', $product))->getContent();

    expect($markdown)
        ->not->toContain('## Best price now')
        ->toContain("## Best value\n\n€3.00 /kg at [ah.nl](<https://ah.nl/p/2>)\n\n€3.00 for 1 kg")
        ->toContain('> Lowest price: €2.00 for 500 g at jumbo.com. That is 33% more per kilo than the best value.')
        ->toContain('| Shop | Price per kilo | Pack price | In stock | Price read |')
        ->toContain('| [jumbo.com](<https://jumbo.com/p/1>) | €4.00 /kg | €2.00 for 500 g |');
});

it('lists the alert rules and marks a shop kept as a link', function (): void {
    $user = User::factory()->create();
    $product = markdownProduct($user);
    $product->update(['target_price' => '10.00', 'drop_threshold_pct' => '15']);
    Shop::factory()->for($product)->create(['url' => 'https://bol.com/p/1', 'kind' => ShopKind::Reference, 'current_price' => null]);

    $markdown = $this->actingAs($user)->get(route('app.products.markdown', $product))->getContent();

    expect($markdown)
        ->toContain("## Alerts\n\n- €10.00 or less\n- 15% drop\n")
        ->toContain('| [bol.com](<https://bol.com/p/1>) | Link only | ' . ShopKind::Reference->note() . ' |');
});

it('keeps a pipe in a shop URL from breaking the table row', function (): void {
    $user = User::factory()->create();
    $product = markdownProduct($user);
    Shop::factory()->for($product)->create(['url' => 'https://jumbo.com/p/1?variant=a|b', 'current_price' => '1.00']);

    $markdown = $this->actingAs($user)->get(route('app.products.markdown', $product))->getContent();
    $row = collect(explode("\n", (string) $markdown))->first(fn (string $line): bool => str_starts_with($line, '| [jumbo.com]'));

    expect($row)->toContain('variant=a\|b')
        // Six unescaped pipes: five cells.
        ->and(preg_match_all('/(?<!\\\\)\|/', (string) $row))->toBe(6);
});

it('refuses a product owned by someone else', function (): void {
    $product = markdownProduct(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->get(route('app.products.markdown', $product))
        ->assertForbidden();
});

it('sends a guest to the login page', function (): void {
    $product = markdownProduct(User::factory()->create());

    $this->get(route('app.products.markdown', $product))->assertRedirect(route('login'));
});

it('keeps the page itself on the uuid and answers 404 for anything else', function (): void {
    $user = User::factory()->create();
    $product = markdownProduct($user);

    $this->actingAs($user)->get(route('app.products.show', $product))->assertOk();
    $this->actingAs($user)->get('/app/products/not-a-uuid.md')->assertNotFound();
});
