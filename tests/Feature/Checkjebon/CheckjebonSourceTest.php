<?php declare(strict_types=1);

use App\Models\CheckjebonPrice;
use App\Services\Checkjebon\CheckjebonResult;
use App\Services\Checkjebon\CheckjebonSource;

function seedCheckjebonRow(string $supermarket, string $externalId, string $name = 'Seeded product', string $price = '1.25'): CheckjebonPrice
{
    return CheckjebonPrice::query()->create([
        'supermarket' => $supermarket,
        'external_id' => $externalId,
        'name' => $name,
        'price' => $price,
        'size' => '125 g',
        'refreshed_at' => now(),
    ]);
}

beforeEach(function (): void {
    $this->source = app(CheckjebonSource::class);
});

test('supports the dataset hosts and their subdomains only', function (): void {
    expect($this->source->supports('ah.nl'))->toBeTrue()
        ->and($this->source->supports('boodschaapje.nl'))->toBeTrue()
        ->and($this->source->supports('shop.ah.nl'))->toBeTrue()
        // dirk.nl is scraped directly: its JSON-LD carries the live promo
        // price, while the dataset only holds the regular price.
        ->and($this->source->supports('dirk.nl'))->toBeFalse()
        ->and($this->source->supports('lidl.nl'))->toBeFalse()
        ->and($this->source->supports('jumbo.com'))->toBeFalse()
        ->and($this->source->supports('notah.nl'))->toBeFalse();
});

test('resolves an AH URL by its wi id, slug ignored', function (): void {
    seedCheckjebonRow('ah', 'wi257', 'AH Kruiden roomkaas');

    $result = $this->source->resolve('https://ah.nl/producten/product/wi257/whatever-slug');

    expect($result->isFound())->toBeTrue()
        ->and($result->snapshot?->title)->toBe('AH Kruiden roomkaas')
        ->and($result->snapshot?->price)->toBe('1.25')
        ->and($result->snapshot?->currency)->toBe('EUR')
        ->and($result->snapshot?->imageUrl)->toBeNull()
        ->and($result->snapshot?->inStock)->toBeTrue();
});

test('resolves boodschaapje URLs by their trailing numeric id', function (): void {
    seedCheckjebonRow('lidl', '8128671', 'Vlies filterzakken', '6.99');

    expect($this->source->resolve('https://boodschaapje.nl/product/8128671')->snapshot?->price)->toBe('6.99');
});

test('URL without a recognizable product id is unrecognized', function (): void {
    seedCheckjebonRow('lidl', '8128671');

    expect($this->source->resolve('https://boodschaapje.nl/product/not-a-number')->missReason)->toBe(CheckjebonResult::REASON_UNRECOGNIZED_URL)
        ->and($this->source->resolve('https://boodschaapje.nl')->missReason)->toBe(CheckjebonResult::REASON_UNRECOGNIZED_URL);
});

test('valid id missing from a non-empty dataset is not_in_dataset', function (): void {
    seedCheckjebonRow('ah', 'wi257');

    $result = $this->source->resolve('https://ah.nl/producten/product/wi999999/unknown');

    expect($result->missReason)->toBe(CheckjebonResult::REASON_NOT_IN_DATASET);
});

test('empty table for the supermarket is dataset_empty', function (): void {
    $result = $this->source->resolve('https://ah.nl/producten/product/wi257/roomkaas');

    expect($result->missReason)->toBe(CheckjebonResult::REASON_DATASET_EMPTY);
});
