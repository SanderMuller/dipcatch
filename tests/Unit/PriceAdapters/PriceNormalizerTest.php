<?php declare(strict_types=1);

use App\PriceAdapters\PriceNormalizer;

// --- Documented shapes (characterization) --------------------------------

test('canonicalizes a documented separator shape', function (string $input, string $expected): void {
    expect(PriceNormalizer::canonicalizeDecimal($input))->toBe($expected);
})->with([
    'European thousands and decimal' => ['1.234,56', '1234.56'],
    'US thousands and decimal' => ['1,234.56', '1234.56'],
    'dot decimal only' => ['1234.56', '1234.56'],
    'comma decimal only' => ['1234,56', '1234.56'],
    'comma thousands only' => ['1,234', '1234'],
    'small dot decimal' => ['9.99', '9.99'],
    'no separator' => ['12', '12'],
]);

// --- A one-digit comma tail is a decimal, not a thousands group -----------

test('reads a one-digit comma tail as a decimal', function (): void {
    expect(PriceNormalizer::canonicalizeDecimal('1,2'))->toBe('1.2')
        ->and(PriceNormalizer::fromMixed('1,2'))->toBe('1.2');
});

test('refuses a comma tail that is neither a decimal nor a thousands group', function (): void {
    expect(PriceNormalizer::fromMixed('1,2345'))->toBeNull();
});

// JumboAdapter builds "<whole>,<fractional>" from two spans, so an empty
// fractional span reaches this helper as a bare trailing comma.
test('drops a bare trailing comma', function (): void {
    expect(PriceNormalizer::fromMixed('2,'))->toBe('2');
});

// --- A three-digit dot tail is ambiguous, so it is refused ----------------

test('refuses an ambiguous three-digit dot tail', function (): void {
    expect(PriceNormalizer::canonicalizeDecimal('1.099'))->toBeEmpty()
        ->and(PriceNormalizer::fromMixed('1.099'))->toBeNull();
});

test('keeps a dot tail that is not three digits', function (string $input, string $expected): void {
    expect(PriceNormalizer::fromMixed($input))->toBe($expected);
})->with([
    'one decimal' => ['1.0', '1.0'],
    'two decimals' => ['1.09', '1.09'],
    'four decimals' => ['1.2345', '1.2345'],
]);

// --- A 3-digit group needs an integer part that can carry one -------------

test('reads a three-digit group as a decimal when the integer part cannot carry one', function (string $input, string $expected): void {
    expect(PriceNormalizer::fromMixed($input))->toBe($expected);
})->with([
    'zero before a comma' => ['0,899', '0.899'],
    'zero before a dot' => ['0.899', '0.899'],
    'no integer part before a comma' => [',899', '.899'],
    'no integer part before a dot' => ['.099', '.099'],
    'signed zero before a comma' => ['-0,899', '-0.899'],
    'signed zero before a dot' => ['-0.899', '-0.899'],
]);

test('reads a signed thousands group the same way as an unsigned one', function (): void {
    expect(PriceNormalizer::fromMixed('-1,234'))->toBe('-1234');
});

// --- Numbers carry no locale, so the string rules never apply to them -----

test('accepts a numeric value as given', function (int|float $input, string $expected): void {
    expect(PriceNormalizer::fromMixed($input))->toBe($expected);
})->with([
    'integer' => [12, '12'],
    'float with two decimals' => [9.99, '9.99'],
    'float with three decimals' => [1.099, '1.099'],
]);

test('refuses a non-finite float', function (): void {
    expect(PriceNormalizer::fromMixed(INF))->toBeNull()
        ->and(PriceNormalizer::fromMixed(NAN))->toBeNull();
});

// --- The public entry point strips surrounding noise ----------------------

test('strips currency noise before canonicalizing', function (): void {
    expect(PriceNormalizer::fromMixed('€ 1.234,56'))->toBe('1234.56');
});
