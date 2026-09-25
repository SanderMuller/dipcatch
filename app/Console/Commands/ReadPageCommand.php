<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\PriceAdapters\AdapterResolver;
use App\Services\ShopFetcher\Exceptions\FetchException;
use App\Services\ShopFetcher\Exceptions\HttpError;
use App\Services\ShopFetcher\Exceptions\RobotsDisallowed;
use App\Services\ShopFetcher\ShopFetcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Read one product page the way a price check would, and print what came
 * back. Writes nothing: no shop, no price check, no host failure memory.
 *
 * For the question a local run cannot answer — what a shop serves to the
 * servers DipCatch runs on. Amazon.com, for one, offers nothing to a Dutch
 * address and may well offer a price to the production region.
 */
#[Signature('dipcatch:read-page {url : A product page URL}')]
#[Description('Fetch one product page and print what the adapters read from it. Read-only.')]
final class ReadPageCommand extends Command
{
    public function handle(ShopFetcher $fetcher, AdapterResolver $resolver): int
    {
        $url = (string) $this->argument('url');

        try {
            $fetch = $fetcher->fetch($url, rememberHost: false);
        } catch (RobotsDisallowed|HttpError|FetchException|InvalidArgumentException $e) {
            $this->components->error(class_basename($e) . ': ' . $e->getMessage());

            return self::FAILURE;
        }

        $extraction = $resolver->resolve(url: $fetch->finalUrl, html: $fetch->html);
        $snapshot = $extraction->snapshot;

        $this->table(['field', 'value'], [
            ['final url', $fetch->finalUrl],
            ['bytes', (string) strlen($fetch->html)],
            ['state', $extraction->state],
            ['adapter', $extraction->adapterKey ?? '-'],
            ['failure', $extraction->failureReason ?? '-'],
            ['title', $snapshot->title ?? '-'],
            ['price', $snapshot === null ? '-' : $snapshot->price . ' ' . $snapshot->currency],
            ['pack size', $snapshot->packSize ?? '-'],
            ['in stock', $snapshot === null || $snapshot->inStock === null ? '-' : ($snapshot->inStock ? 'yes' : 'no')],
        ]);

        return $extraction->isSuccess() ? self::SUCCESS : self::FAILURE;
    }
}
