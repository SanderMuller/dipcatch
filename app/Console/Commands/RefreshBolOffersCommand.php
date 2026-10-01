<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CheckjebonPrice;
use App\Services\BolApi\BolApiFailed;
use App\Services\BolApi\BolCatalogClient;
use App\Services\BolApi\BolProduct;
use App\Services\BolFeed\BolCatalogRows;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Re-reads every stored bol.com suggestion row from the Catalog API, so a
 * suggested price is at most a day old where the feed cannot be imported
 * (production: bol whitelists one IP address). A product bol no longer sells
 * leaves the catalogue. Paced under bol's 10 requests a second.
 */
#[Signature('dipcatch:refresh-bol-offers')]
#[Description('Refresh the stored bol.com suggestion rows from the bol.com Catalog API.')]
final class RefreshBolOffersCommand extends Command
{
    /** Just over a tenth of a second, so the run stays under 10 a second. */
    private const int PAUSE_MICROSECONDS = 110_000;

    public function handle(BolCatalogClient $bol): int
    {
        if (! BolCatalogClient::configured()) {
            $this->warn('No bol.com API client id configured; nothing refreshed.');

            return self::SUCCESS;
        }

        $now = now();
        $updated = 0;
        $gone = 0;

        foreach (CheckjebonPrice::query()->where('supermarket', BolCatalogRows::CHAIN)->whereNotNull('ean')->lazyById() as $row) {
            try {
                $offer = $bol->findByEan((string) $row->ean);
            } catch (BolApiFailed $e) {
                if (! $e->rateLimited()) {
                    $this->error("Stopped: {$e->getMessage()} Rows not reached keep their last price.");

                    return self::FAILURE;
                }

                sleep(1);

                continue;
            }

            $fresh = $offer instanceof BolProduct && $offer->price !== null
                ? BolCatalogRows::row($row->external_id, $offer->ean, $offer->title, $offer->url, $offer->price, $now)
                : null;

            if ($fresh === null) {
                $row->delete();
                $gone++;
            } else {
                $updated += BolCatalogRows::store([$fresh], $now);
            }

            usleep(self::PAUSE_MICROSECONDS);
        }

        $this->info("{$updated} bol.com rows refreshed, {$gone} no longer sold and removed.");

        return self::SUCCESS;
    }
}
