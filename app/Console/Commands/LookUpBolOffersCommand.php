<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\LookUpBolOffers;
use App\Models\Product;
use App\Services\BolApi\BolCatalogClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Asks bol.com about the products it was not asked about lately. A product
 * is otherwise looked up only when it or a shop is added, so a product
 * tracked before bol.com was a suggestion, or untouched since, never got a
 * bol.com suggestion on the dashboard, until adding a shop asked for one.
 */
#[Signature('dipcatch:look-up-bol-offers')]
#[Description('Queue a bol.com lookup for every product not looked up in the last week.')]
final class LookUpBolOffersCommand extends Command
{
    /** Lookups started per second: each asks bol.com up to four times, and bol allows ten requests a second. */
    private const int PER_SECOND = 2;

    public function handle(): int
    {
        if (! BolCatalogClient::configured()) {
            $this->warn('No bol.com API client id configured; nothing looked up.');

            return self::SUCCESS;
        }

        $queued = 0;

        Product::query()
            ->where('active', true)
            ->whereRaw('UPPER(currency) = ?', ['EUR'])
            ->has('shops')
            ->select('id')
            ->orderBy('id')
            ->lazyById()
            ->each(function (Product $product) use (&$queued): void {
                if (LookUpBolOffers::lookedUpRecently((string) $product->id)) {
                    return;
                }

                dispatch(new LookUpBolOffers((string) $product->id))->delay(intdiv($queued, self::PER_SECOND));
                $queued++;
            });

        $this->info("Queued {$queued} bol.com lookups.");

        return self::SUCCESS;
    }
}
