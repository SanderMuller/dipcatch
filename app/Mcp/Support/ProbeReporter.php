<?php declare(strict_types=1);

namespace App\Mcp\Support;

use App\Actions\Shops\ProbeBudget;
use App\Actions\Shops\ProbeOutcome;
use App\Enums\ConsumerPriceIssue;
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
            // Stated before the caller confirms, because this is the one fact
            // that makes an otherwise ordinary price unusable: the shop is
            // added and tracked, but it takes no part in either answer.
            'not_a_consumer_price' => ConsumerPriceIssue::tryFrom(
                is_string($snapshot['consumer_price_issue'] ?? null) ? $snapshot['consumer_price_issue'] : '',
            )?->label(),
            'consumer_price_note' => $snapshot['consumer_price_note'] ?? null,
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
            // Split from the host's own 429 on purpose. This one is DipCatch's
            // per-host throttle, and it knows exactly when it next opens.
            ProbeFailure::LocalThrottle => 'DipCatch is pacing its own requests to that shop. ' . self::waitSentence($context),
            ProbeFailure::HostRateLimited => 'That shop asked DipCatch to slow down. ' . self::hostWaitSentence($context),
            ProbeFailure::RobotsDisallowed => 'That shop asks crawlers not to read this page, and DipCatch honours that.',
            ProbeFailure::Blocked => self::persistent($context)
                ? 'That shop has blocked DipCatch on its last ' . self::failures($context) . ' requests. Retrying will not help — the shop refuses automated readers.'
                : 'That shop blocked the request.' . self::streak($context),
            // Named as a class of cause rather than one cause. Blaming
            // JavaScript sent a reader after the wrong thing on hoogvliet.com,
            // where the price is in the server HTML and simply never a
            // contiguous string: `<span>7</span><span>.</span><sup>29</sup>`,
            // with no text node anywhere reading 7.29.
            ProbeFailure::ExtractionFailed => 'The page loaded but no price could be read from it. Either the shop builds the price in the browser, or it splits it across elements in a way DipCatch does not recognise. Another URL from the same shop will read the same way.',
            ProbeFailure::CurrencyMismatch => 'That page prices in a different currency from the product.',
            ProbeFailure::NotInDataset => 'That shop is covered by a price dataset that does not list this product yet.',
            // Without this the default fired, which tells a caller to try a
            // direct product URL — the one retry that can never work here.
            ProbeFailure::ShopNotServable => 'That shop builds its prices in the browser, so its pages carry no price to read. It cannot be tracked, and another URL from the same shop will not help.',
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
     * What to tell a caller a shop refused with HTTP 429.
     *
     * Only a figure the shop itself stated. DipCatch used to default to sixty
     * seconds and print it as the shop's own instruction; dierapotheker.nl
     * sends a bare nginx 429 with no `Retry-After`, so a caller followed that
     * invented number four times and was refused each time. A retry interval
     * we made up reads as a promise, and a caller that keeps it hammers a shop
     * that has asked us to stop.
     *
     * @param  array<string, mixed>  $context
     */
    private static function hostWaitSentence(array $context): string
    {
        $seconds = $context['retry_after_seconds'] ?? null;

        if (! is_int($seconds) || $seconds < 1) {
            return 'It did not say for how long, so DipCatch cannot tell you when to retry. Leave it several minutes. A shop that keeps refusing is limiting DipCatch rather than being briefly busy, and retrying sooner makes that worse.';
        }

        return 'It asks for ' . $seconds . ' ' . ($seconds === 1 ? 'second' : 'seconds') . '.';
    }

    /**
     * How long to wait when DipCatch is the one holding the request back. Its
     * own limiters always know, so the fallback is a formality.
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
