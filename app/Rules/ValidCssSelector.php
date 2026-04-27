<?php declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Symfony\Component\CssSelector\Exception\SyntaxErrorException;
use Throwable;

final class ValidCssSelector implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            // Required-ness is enforced separately; an empty value is not a CSS-selector failure.
            return;
        }

        try {
            new CssSelectorConverter()->toXPath($value);
        } catch (SyntaxErrorException $e) {
            $fail("The {$attribute} is not a valid CSS selector: {$e->getMessage()}");
        } catch (Throwable) {
            $fail("The {$attribute} could not be parsed as a CSS selector.");
        }
    }
}
