<?php declare(strict_types=1);

use App\Livewire\Products\CreateProductManual;
use App\Models\Product;
use App\Models\User;

use function Pest\Livewire\livewire;

it('creates a product the scraper cannot read', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    livewire(CreateProductManual::class)
        ->set('title', 'Local roastery beans')
        ->set('image_url', 'https://example.test/beans.jpg')
        ->set('currency', 'EUR')
        ->call('save');

    $product = Product::query()->where('user_id', $user->id)->sole();

    expect($product->title)->toBe('Local roastery beans')
        ->and($product->image_url)->toBe('https://example.test/beans.jpg');
});

it('accepts a currency typed in lower case', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    livewire(CreateProductManual::class)
        ->set('title', 'Local roastery beans')
        ->set('currency', 'eur')
        ->call('save')
        ->assertHasNoErrors();

    expect(Product::query()->where('user_id', $user->id)->sole()->currency)->toBe('EUR');
});

it('refuses a currency that is not an ISO 4217 code', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    livewire(CreateProductManual::class)
        ->set('title', 'Local roastery beans')
        ->set('currency', 'ZZZ')
        ->call('save')
        ->assertHasErrors('currency');

    expect(Product::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('refuses a zero drop threshold', function (string $field): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    livewire(CreateProductManual::class)
        ->set('title', 'Local roastery beans')
        ->set('currency', 'EUR')
        ->set($field, '0')
        ->call('save')
        ->assertHasErrors($field);

    expect(Product::query()->where('user_id', $user->id)->count())->toBe(0);
})->with(['drop_threshold_pct', 'drop_threshold_abs']);

it('refuses an image url whose scheme is not http(s)', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    livewire(CreateProductManual::class)
        ->set('title', 'Local roastery beans')
        ->set('image_url', 'ftp://example.test/beans.jpg')
        ->set('currency', 'EUR')
        ->call('save')
        ->assertHasErrors('image_url');

    expect(Product::query()->where('user_id', $user->id)->count())->toBe(0);
});
