<?php declare(strict_types=1);

use App\Support\TitleDiff;

/**
 * @return list<string>
 */
function markedWords(string $title, string $other): array
{
    return array_values(array_map(
        static fn (array $mark): string => $mark['text'],
        array_filter(TitleDiff::marks($title, $other), static fn (array $mark): bool => $mark['differs']),
    ));
}

test('the words one title lacks are marked', function (): void {
    expect(markedWords('HiPRO Protein Drink Mango 300 ml', 'HiPRO Protein Drink Vanille 300 ml'))->toBe(['Mango'])
        ->and(markedWords('HiPRO Protein Drink Vanille 300 ml', 'HiPRO Protein Drink Mango 300 ml'))->toBe(['Vanille']);
});

test('spelling of the same word is not a difference', function (string $title, string $other): void {
    expect(markedWords($title, $other))->toBeEmpty();
})->with([
    'a size written together' => ['Cola 1,5L', 'Cola 1,5 l'],
    'case and accents' => ['CRÈME fraîche', 'creme Fraiche'],
    'filler words' => ['Cat food with salmon', 'Cat food salmon'],
    'punctuation' => ['Lay\'s chips - naturel', 'Lays chips naturel'],
    'a unit spelled out' => ['Aroma Rood 1 kilo', 'Aroma Rood 1 kg'],
    'a compound word' => ['Aroma Rood koffiebonen', 'Aroma Rood bonen'],
]);

test('another size is a difference', function (): void {
    expect(markedWords('Milka Mmmax 300 g', 'Milka Mmmax 100 g'))->toBe(['300'])
        ->and(markedWords('Water 1000 ml', 'Water 10000 ml'))->toBe(['1000']);
});

test('a short word is not matched inside a longer one', function (): void {
    expect(markedWords('Ham slices', 'Hamburger slices'))->toBe(['Ham']);
});
