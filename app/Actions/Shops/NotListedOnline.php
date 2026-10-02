<?php declare(strict_types=1);

namespace App\Actions\Shops;

use App\Enums\ProbeFailure;
use App\Models\CatalogueLink;
use App\Models\CheckjebonChain;
use App\Services\Checkjebon\CatalogueLinks;
use App\Services\ShopFetcher\FetchResult;

/**
 * The probe's answer when a shop says the product is not on its site. The
 * supermarket list then stops suggesting that page ({@see CatalogueLinks}).
 */
final class NotListedOnline
{
    /** A 404 or 410 for a supermarket list page; other errors stay errors. */
    public static function afterError(string $url, string $host, int $status): ?ProbeOutcome
    {
        $row = CatalogueLinks::isNotFound($url, $status) ? CatalogueLinks::rowFor($url) : null;

        return $row === null ? null : self::outcome($row, $host);
    }

    /** A page that is the shop's "not found" answer, such as Dirk's "helaas". */
    public static function onPage(string $url, FetchResult $page): ?ProbeOutcome
    {
        return CatalogueLinks::isNotFound($page->finalUrl, $page->statusCode) ? self::outcome(CatalogueLinks::rowFor($url), $page->host) : null;
    }

    /** Records the list row gone, and names the shop as the list does. */
    /**
     * @param  array{0: string, 1: string}|null  $row  chain, external id
     */
    private static function outcome(?array $row, string $host): ProbeOutcome
    {
        if ($row !== null) {
            CatalogueLink::record($row[0], $row[1], alive: false);
        }

        $label = $row === null ? null : CheckjebonChain::query()->where('chain', $row[0])->value('label');

        return ProbeOutcome::failed(ProbeFailure::NotListedOnline, ['shop' => is_string($label) ? $label : $host]);
    }
}
