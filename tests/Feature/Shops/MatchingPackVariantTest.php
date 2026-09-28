<?php declare(strict_types=1);

use App\Actions\Shops\MatchingPackVariant;
use App\Models\Product;
use App\PriceAdapters\VariantCandidate;

test('a product title that counts its items is read like the variant names', function (): void {
    // "12st … 55g" is a 660 g pack on both sides, not a single 55 g bar.
    $product = Product::factory()->create(['title' => '12st Barebells Protein Bar 55g']);

    $key = MatchingPackVariant::keyFor($product, [
        new VariantCandidate('single', 'Barebells Protein Bar 55g', '2.49', 'EUR'),
        new VariantCandidate('twelve', '12st Barebells Protein Bar 55g', '27.99', 'EUR'),
    ]);

    expect($key)->toBe('twelve');
});
