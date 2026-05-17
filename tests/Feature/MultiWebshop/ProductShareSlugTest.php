<?php declare(strict_types=1);

use App\Models\Product;
use Illuminate\Database\QueryException;

test('share_slug defaults to null and isPubliclyShared() reflects state', function (): void {
    $product = Product::factory()->create();

    expect($product->fresh()->share_slug)->toBeNull()
        ->and($product->isPubliclyShared())->toBeFalse()
        ->and($product->publicShareUrl())->toBeNull();
});

test('setting share_slug round-trips and exposes a /p/{slug} URL', function (): void {
    $product = Product::factory()->create(['share_slug' => 'abc123abc123abc123abc123abc123ab']);

    $fresh = $product->fresh();
    expect($fresh->share_slug)->toBe('abc123abc123abc123abc123abc123ab')
        ->and($fresh->isPubliclyShared())->toBeTrue()
        ->and($fresh->publicShareUrl())->toContain('/p/abc123abc123abc123abc123abc123ab');
});

test('share_slug uniqueness constraint rejects collisions', function (): void {
    Product::factory()->create(['share_slug' => 'shared-slug-fixed-for-test-abcd1']);

    expect(fn () => Product::factory()->create(['share_slug' => 'shared-slug-fixed-for-test-abcd1']))
        ->toThrow(QueryException::class);
});

test('safeImageUrl accepts http and https, rejects everything else', function (): void {
    $cases = [
        'https://example.com/img.png' => 'https://example.com/img.png',
        'http://example.com/img.png' => 'http://example.com/img.png',
        'javascript:alert(1)' => null,
        'data:image/png;base64,AAAA' => null,
        'file:///etc/passwd' => null,
        'not a url at all' => null,
        '' => null,
    ];

    foreach ($cases as $input => $expected) {
        $product = Product::factory()->make(['image_url' => $input]);
        expect($product->safeImageUrl())->toBe($expected, "input: '{$input}'");
    }
});

test('safeImageUrl returns null when image_url is null', function (): void {
    $product = Product::factory()->make(['image_url' => null]);

    expect($product->safeImageUrl())->toBeNull();
});
