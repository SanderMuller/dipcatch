<?php declare(strict_types=1);

namespace App\Mcp\Support;

use App\Actions\Shops\ProbeBudget;
use App\Actions\Shops\ProbeOutcome;
use App\Enums\ProbeFailure;
use App\PriceAdapters\VariantCandidate;
use Laravel\Mcp\Response;

/**
 * Turns a probe result into something an assistant can read out.
 *
 * A tool that returned "probe failed" would leave the user with nothing to
 * do; every branch here says what happened and what would fix it.
 */
final readonly class ProbeReporter
{
    public function explain(ProbeOutcome $outcome): Response
    {
        if ($outcome->isDuplicate()) {
            $existing = $outcome->existingShop;

            return Response::error('That URL is already tracked on this product'
                . ($existing === null ? '.' : ', as ' . $existing->url . '.'));
        }

        if ($outcome->isAmbiguous()) {
            $unmatched = $outcome->unmatchedVariantKey;

            $opening = $unmatched === null
                ? 'That page sells more than one variant.'
                : 'No variant on that page matches variant_key "' . $unmatched . '".';

            return Response::error(
                $opening . ' Ask which, then call again with variant_key set to one of:'
                . PHP_EOL . implode(PHP_EOL, array_map(self::variantLine(...), $outcome->variants))
                . PHP_EOL . 'A key that is itself a URL can be sent as `url` instead, which also '
                . 'stores the address the user clicks.',
            );
        }

        if ($outcome->extractionReason === 'variant_key_no_match') {
            return Response::error('That page lists no variant matching the variant_key that was sent. Call again without variant_key to see what the page offers.');
        }

        return Response::error($this->failure($outcome->errorCode, $outcome->context ?? []));
    }

    /**
     * What the page said, in the shape a person can be read back.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function preview(array $snapshot, ProbeOutcome $outcome): array
    {
        return [
            'title' => $snapshot['title'] ?? null,
            'price' => $snapshot['price'] ?? null,
            'single_item_price' => $snapshot['single_item_price'] ?? null,
            'bundle_quantity' => $snapshot['bundle_quantity'] ?? null,
            'bundle_total_price' => $snapshot['bundle_total_price'] ?? null,
            'currency' => $snapshot['currency'] ?? null,
            'in_stock' => $snapshot['in_stock'] ?? null,
            'stock' => match ($snapshot['in_stock'] ?? null) {
                true => 'in_stock',
                false => 'out_of_stock',
                default => 'unknown',
            },
            // What the verdict was read from, so the caller can judge it
            // instead of trusting a bare flag.
            'stock_signal' => $snapshot['stock_signal'] ?? null,
            'pack_size' => $snapshot['pack_size'] ?? null,
            'shop' => $outcome->host,
            'url' => $outcome->normalizedUrl,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function failure(?ProbeFailure $code, array $context = []): string
    {
        return match ($code) {
            ProbeFailure::InvalidUrl => 'That does not look like a URL. Paste the address of a product page.',
            ProbeFailure::ProbeRateLimited => 'DipCatch reads at most ' . ProbeBudget::PER_MINUTE . ' pages a minute for one account. ' . self::waitSentence($context),
            ProbeFailure::LocalThrottle, ProbeFailure::HostRateLimited => 'That shop asked DipCatch to slow down. ' . self::waitSentence($context),
            ProbeFailure::RobotsDisallowed => 'That shop asks crawlers not to read this page, and DipCatch honours that.',
            ProbeFailure::Blocked => self::persistent($context)
                ? 'That shop has blocked DipCatch on its last ' . self::failures($context) . ' requests. Retrying will not help — the shop refuses automated readers.'
                : 'That shop blocked the request.' . self::streak($context),
            ProbeFailure::ExtractionFailed => 'The page loaded but no price could be read from it. Some shops load prices with JavaScript, which DipCatch cannot see.',
            ProbeFailure::CurrencyMismatch => 'That page prices in a different currency from the product.',
            ProbeFailure::NotInDataset => 'That shop is covered by a price dataset that does not list this product yet.',
            ProbeFailure::TemporaryFailure, ProbeFailure::HttpError => self::persistent($context)
                ? 'That shop has not answered DipCatch on its last ' . self::failures($context) . ' requests. This is not a passing fault, so another attempt now will fail too.'
                : 'The shop did not answer. Try again shortly.' . self::streak($context),
            default => 'That page could not be read. Try a different shop, or a direct product URL.',
        };
    }

    /**
     * How many times in a row this host has failed this way, when it is more
     * than once.
     *
     * @param  array<string, mixed>  $context
     */
    private static function streak(array $context): string
    {
        $failures = self::failures($context);

        return $failures > 1 ? ' That is ' . $failures . ' in a row.' : '';
    }

    /**
     * One choice, with what it is and what it costs. The key alone told a
     * caller nothing, so it spent a probe on each option to find out.
     */
    private static function variantLine(VariantCandidate $variant): string
    {
        return '- ' . $variant->key . ' — ' . $variant->title . ' — ' . $variant->price . ' ' . $variant->currency;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function persistent(array $context): bool
    {
        return ($context['persistent'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function failures(array $context): int
    {
        $failures = $context['failures'] ?? null;

        return is_int($failures) ? $failures : 0;
    }

    /**
     * How long to wait, when the failure carries a retry-after. Without one
     * the caller still gets a bound rather than a guess.
     *
     * @param  array<string, mixed>  $context
     */
    private static function waitSentence(array $context): string
    {
        $seconds = $context['retry_after_seconds'] ?? null;

        if (! is_int($seconds) || $seconds < 1) {
            return 'Wait a minute and try again.';
        }

        return 'Try again in ' . $seconds . ' ' . ($seconds === 1 ? 'second' : 'seconds') . '.';
    }
}
