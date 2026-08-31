<?php declare(strict_types=1);

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Translation\PotentiallyTranslatedString;
use Pest\Expectation;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/**
 * Assert that the value under test (a DateTimeInterface-like) has the same
 * UNIX timestamp as the expected value. Narrows the value to
 * `\Carbon\CarbonInterface` via assert() so PHPStan + Pest's template
 * inference don't trip on `?string`-typed Eloquent attributes.
 */
expect()->extend('toBeSameTimestampAs', function (DateTimeInterface $expected): Expectation {
    /** @var mixed $actual */
    $actual = $this->value;
    Assert::assertInstanceOf(DateTimeInterface::class, $actual);
    Assert::assertSame($expected->getTimestamp(), $actual->getTimestamp());

    return $this;
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Clear the Redis bucket a `ThrottleRequestsWithRedis` named limiter writes
 * to, for one throttle key (normally the client IP).
 *
 * `RateLimiter::clear()` does not reach this state. That facade talks to the
 * cache store (`array` under phpunit.xml), while the middleware bypasses the
 * cache and drives an `Illuminate\Redis\Limiters\DurationLimiter` straight
 * on the Redis connection. So the counter lives in a real Redis server and
 * survives between test runs, which makes a throttle test fail with 429 on
 * its first request.
 *
 * The middleware key is `md5($limiterName . $key)`, or `"$limiterName:$key"`
 * when `ThrottleRequests::shouldHashKeys(false)` is set. That flag is a
 * protected static with no getter, so delete both forms.
 */
function clearRedisRateLimiter(string $limiterName, string $key = '127.0.0.1'): void
{
    Redis::connection()->del(
        md5($limiterName . $key),
        $limiterName . ':' . $key,
    );
}

/**
 * Wrap a JSON-LD payload in a minimal HTML document. The single source of
 * truth for the `<html><head><script type="application/ld+json">…</script>
 * </head></html>` shape used across adapter + Shops fixtures.
 */
function withJsonLd(string $jsonLd): string
{
    return "<html><head><script type=\"application/ld+json\">{$jsonLd}</script></head><body></body></html>";
}

/**
 * Build a single-Product JSON-LD page wrapped in HTML. The offer-block uses
 * `@type => Shop` for historical reasons; the JsonLdAdapter tolerates it, so
 * existing tests rely on that exact body. Fixing to schema.org's `Offer`
 * is a separate follow-up — would change the JSON-LD shape the adapter
 * parses.
 */
function jsonLdPage(string $price = '50.00', string $currency = 'EUR', string $title = 'Test Item'): string
{
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $title,
        'offers' => [
            '@type' => 'Shop',
            'price' => $price,
            'priceCurrency' => $currency,
            'availability' => 'https://schema.org/InStock',
        ],
    ], JSON_THROW_ON_ERROR);

    return withJsonLd($json);
}

/**
 * Drive an `Illuminate\Contracts\Validation\ValidationRule` against a value
 * and return the collected error messages. Use in rule-unit tests:
 *
 *     expect(runRule(new MyRule(), 'attr', $value))->toBe([]);
 *
 * @return list<string>
 */
function runRule(
    ValidationRule $rule,
    string $attribute,
    mixed $value,
): array {
    $errors = [];
    $rule->validate(
        $attribute,
        $value,
        function (string $message, ?string $attribute = null) use (&$errors): PotentiallyTranslatedString {
            $errors[] = $message;

            return new PotentiallyTranslatedString($message, app('translator'));
        },
    );

    return $errors;
}
