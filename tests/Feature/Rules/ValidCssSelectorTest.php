<?php declare(strict_types=1);

use App\Rules\ValidCssSelector;

dataset('valid_selectors', [
    'class' => '.foo',
    'id' => '#bar',
    'descendant + child combo' => '#bar > span',
    'attribute selector' => '[data-x]',
    'compound class + pseudo' => '.product-price:not(.strike)',
    'meta with attr equality' => 'meta[itemprop="price"]',
]);

test('valid CSS selectors pass', function (string $selector): void {
    expect(runRule(new ValidCssSelector(), 'selector', $selector))->toBe([]);
})->with('valid_selectors');

dataset('invalid_selectors', [
    'unbalanced bracket' => '[data-x',
    'shadow combinator' => '>>>',
]);

test('invalid CSS selectors fail with a descriptive error', function (string $selector): void {
    $errors = runRule(new ValidCssSelector(), 'selector', $selector);
    expect($errors)->not->toBe([])
        ->and($errors[0])->toContain('selector');
})->with('invalid_selectors');

test('empty value is not a CSS-selector failure (required validates separately)', function (): void {
    expect(runRule(new ValidCssSelector(), 'selector', ''))->toBe([])
        ->and(runRule(new ValidCssSelector(), 'selector', '   '))->toBe([]);
});
