<?php declare(strict_types=1);

namespace App\Actions\Shops;

use App\Enums\ProbeFailure;
use App\Services\ShopDiscovery\KlarnaLeads;
use App\Services\ShopDiscovery\ShopLead;
use App\Services\ShopFetcher\Exceptions\FetchException;
use App\Services\ShopFetcher\ShopFetcher;
use App\Support\NotAShop;
use App\Support\UnservableShops;
use Closure;
use InvalidArgumentException;

/**
 * Why a pasted address is refused before its page is read as a shop: a shop
 * that builds its prices in the browser, or a site that is not a shop. A
 * Klarna page is a comparison site too, but one that names the shops, so its
 * refusal reads the page to list them. See specs/klarna-shop-leads.md §7.
 */
final readonly class AddressRefusal
{
    private const int KLARNA_SHOPS_SHOWN = 8;

    public function __construct(private ShopFetcher $fetcher) {}

    /**
     * Null when the address may be read. `$overBudget` runs before the one
     * fetch a refusal makes, so a Klarna page spends the probe budget like
     * any paste.
     *
     * @param  Closure(): ?ProbeOutcome  $overBudget
     */
    public function for(string $url, string $host, Closure $overBudget): ?ProbeOutcome
    {
        if (KlarnaLeads::isKlarnaPage($url)) {
            return $overBudget() ?? $this->klarna($url);
        }

        $unservable = UnservableShops::reasonFor($host);

        if ($unservable !== null) {
            return ProbeOutcome::failed(ProbeFailure::ShopNotServable, ['reason' => $unservable]);
        }

        return NotAShop::covers($host) ? ProbeOutcome::failed(ProbeFailure::NotAShop) : null;
    }

    /**
     * The not-a-shop refusal with the shops the page lists: in stock first,
     * then cheapest. A page that cannot be read gives the plain refusal.
     */
    private function klarna(string $url): ProbeOutcome
    {
        try {
            $fetch = $this->fetcher->fetch($url);
        } catch (FetchException|InvalidArgumentException) {
            return ProbeOutcome::failed(ProbeFailure::NotAShop);
        }

        $leads = KlarnaLeads::fromHtml($fetch->html, $url);
        usort($leads, static fn (ShopLead $a, ShopLead $b): int => [$a->inStock !== true, (float) $a->price] <=> [$b->inStock !== true, (float) $b->price]);
        $leads = array_slice($leads, 0, self::KLARNA_SHOPS_SHOWN);

        return ProbeOutcome::failed(ProbeFailure::NotAShop, $leads === [] ? null : [
            'klarna_url' => $url,
            'leads' => array_map(static fn (ShopLead $lead): array => $lead->toArray(), $leads),
        ]);
    }
}
