<?php declare(strict_types=1);

use App\Support\SearchText;

test('folds a term to lowercase letters without accents', function (): void {
    expect(SearchText::fold('Crème Andrélon'))->toBe('creme andrelon');
});

test('maps every accented letter, capitals included, to one plain letter', function (): void {
    // translate() drops a character that has no partner, so a length
    // mismatch would delete letters from every title searched.
    [$from, $to] = SearchText::bindings();

    $capital = mb_strpos($from, 'É');

    expect(mb_strlen($from))->toBe(mb_strlen($to))
        ->and($capital)->toBeInt()
        ->and(mb_substr($to, (int) $capital, 1))->toBe('e');
});
