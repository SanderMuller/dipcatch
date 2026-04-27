<?php declare(strict_types=1);

use App\Rules\ValidCssSelector;
use Illuminate\Translation\PotentiallyTranslatedString;

function runRule(string $value): array
{
    $errors = [];
    new ValidCssSelector()->validate(
        'selector',
        $value,
        function (string $message, ?string $attribute = null) use (&$errors): PotentiallyTranslatedString {
            $errors[] = $message;

            return new PotentiallyTranslatedString($message, app('translator'));
        },
    );

    return $errors;
}

dataset('valid_selectors', [
    'class' => '.foo',
    'id' => '#bar',
    'descendant + child combo' => '#bar > span',
    'attribute selector' => '[data-x]',
    'compound class + pseudo' => '.product-price:not(.strike)',
    'meta with attr equality' => 'meta[itemprop="price"]',
]);

test('valid CSS selectors pass', function (string $selector): void {
    expect(runRule($selector))->toBe([]);
})->with('valid_selectors');

dataset('invalid_selectors', [
    'unbalanced bracket' => '[data-x',
    'shadow combinator' => '>>>',
]);

test('invalid CSS selectors fail with a descriptive error', function (string $selector): void {
    expect(runRule($selector))->not->toBe([])
        ->and(runRule($selector)[0])->toContain('selector');
})->with('invalid_selectors');

test('empty value is not a CSS-selector failure (required validates separately)', function (): void {
    expect(runRule(''))->toBe([])
        ->and(runRule('   '))->toBe([]);
});
