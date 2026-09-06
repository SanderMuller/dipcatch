<?php declare(strict_types=1);

namespace App\Mcp\Support;

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
            $variants = array_map(
                static fn (VariantCandidate $variant): string => $variant->key,
                $outcome->variants,
            );

            return Response::error(
                'That page sells more than one variant. Ask which, then call again with variant_key set to one of: '
                . implode(', ', $variants),
            );
        }

        return Response::error($this->failure($outcome->errorCode));
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
            'currency' => $snapshot['currency'] ?? null,
            'in_stock' => $snapshot['in_stock'] ?? null,
            'pack_size' => $snapshot['pack_size'] ?? null,
            'shop' => $outcome->host,
            'url' => $outcome->normalizedUrl,
        ];
    }

    private function failure(?ProbeFailure $code): string
    {
        return match ($code) {
            ProbeFailure::InvalidUrl => 'That does not look like a URL. Paste the address of a product page.',
            ProbeFailure::ProbeRateLimited, ProbeFailure::LocalThrottle, ProbeFailure::HostRateLimited => 'Too many pages fetched just now. Wait a minute and try again.',
            ProbeFailure::RobotsDisallowed => 'That shop asks crawlers not to read this page, and DipCatch honours that.',
            ProbeFailure::Blocked => 'That shop blocked the request.',
            ProbeFailure::ExtractionFailed => 'The page loaded but no price could be read from it. Some shops load prices with JavaScript, which DipCatch cannot see.',
            ProbeFailure::CurrencyMismatch => 'That page prices in a different currency from the product.',
            ProbeFailure::NotInDataset => 'That shop is covered by a price dataset that does not list this product yet.',
            ProbeFailure::TemporaryFailure, ProbeFailure::HttpError => 'The shop did not answer. Try again shortly.',
            default => 'That page could not be read. Try a different shop, or a direct product URL.',
        };
    }
}
