<?php declare(strict_types=1);

use App\Livewire\Settings\ProductFeatures;
use App\Models\User;

use function Pest\Livewire\livewire;

test('the automatic categories switch is absent when no key is configured', function (): void {
    config()->set('services.typesafe.key', '');
    $this->actingAs(User::factory()->create());

    livewire(ProductFeatures::class)
        ->assertDontSee('Sort new products into a category automatically')
        ->assertSeeHtml('data-test="product-features-unavailable"');
});

test('a free account sees the automatic categories switch disabled with the upgrade note', function (): void {
    config()->set('services.typesafe.key', 'test-key');
    $this->actingAs(User::factory()->create());

    $html = livewire(ProductFeatures::class)
        ->assertSee('Sort new products into a category automatically')
        ->assertSee('Pro sorts products for you. Your choice is kept, and it starts working when you upgrade.')
        ->html();

    expect($html)->toMatch('/disabled="disabled"[^>]*data-test="auto-categories"/')
        ->toMatch('/data-test="auto-categories-pro"[^>]*>\s*Pro\s*</');
});

test('a Pro account sees the automatic categories switch enabled', function (): void {
    config()->set('services.typesafe.key', 'test-key');
    $user = User::factory()->create();
    subscribeUser($user);
    $this->actingAs($user);

    $html = livewire(ProductFeatures::class)
        ->assertSee('Products you add from now on. Products you already track keep their category.')
        ->assertDontSee('Pro sorts products for you.')
        ->html();

    expect($html)->toMatch('/data-test="auto-categories"/')
        ->not->toMatch('/disabled="disabled"[^>]*data-test="auto-categories"/')
        // Marked Pro on a Pro account too, so a subscriber sees what the plan pays for.
        ->toMatch('/data-test="auto-categories-pro"[^>]*>\s*Pro\s*</');
});

test('save persists the automatic categories choice', function (): void {
    config()->set('services.typesafe.key', 'test-key');
    $user = User::factory()->create(['auto_categories' => false]);
    $this->actingAs($user);

    livewire(ProductFeatures::class)
        ->assertSet('auto_categories', false)
        ->set('auto_categories', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($user->refresh()->auto_categories)->toBeTrue();
});

test('save persists the shop-check choice, off until the person switches it on', function (): void {
    config()->set('services.typesafe.key', 'test-key');
    $user = User::factory()->create();
    $this->actingAs($user);

    expect($user->refresh()->shop_checks)->toBeFalse();

    livewire(ProductFeatures::class)
        ->assertSet('shop_checks', false)
        ->set('shop_checks', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($user->refresh()->shop_checks)->toBeTrue()
        ->and($user->wantsShopChecks())->toBeFalse();

    subscribeUser($user);

    expect($user->refresh()->wantsShopChecks())->toBeTrue();
});
