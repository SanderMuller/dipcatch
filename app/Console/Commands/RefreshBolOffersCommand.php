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
use Illuminate\Support\Sleep;

/**
 * Re-reads every stored bol.com row that has a barcode from the Catalog
 * API, so a suggested price is at most a day old where the feed cannot be
 * imported (production: bol whitelists one IP address). Only a product bol
 * no longer knows leaves the catalogue; one without an offer today, out of
 * stock for example, keeps its row. Paced under bol's 10 requests a second.
 */
#[Signature('dipcatch:refresh-bol-offers')]
#[Description('Refresh the stored bol.com suggestion rows from the bol.com Catalog API.')]
final class RefreshBolOffersCommand extends Command
{
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
        $kept = 0;

        foreach (CheckjebonPrice::query()->where('supermarket', BolCatalogRows::CHAIN)->whereNotNull('ean')->lazyById() as $row) {
            // The API takes EAN-13; a longer barcode cannot be asked for, and
            // must not read as "no longer sold".
            if (strlen((string) $row->ean) > 13) {
                $kept++;

                continue;
            }

            try {
                $offer = $bol->findByEan((string) $row->ean);
            } catch (BolApiFailed $e) {
                if (! $e->rateLimited()) {
                    $this->error("Stopped: {$e->getMessage()} Rows not reached keep their last price.");

                    return self::FAILURE;
                }

                Sleep::sleep(1);

                continue;
            }

            $fresh = $offer instanceof BolProduct && $offer->price !== null
                ? BolCatalogRows::row($row->external_id, $offer->ean, $offer->title, $offer->url, $offer->price, $now)
                : null;

            if (! $offer instanceof BolProduct) {
                $row->delete();
                $gone++;
            } elseif ($fresh === null) {
                $kept++;
            } else {
                $updated += BolCatalogRows::store([$fresh], $now);
            }

            Sleep::usleep(self::PAUSE_MICROSECONDS);
        }

        $this->info("{$updated} bol.com rows refreshed, {$gone} no longer sold and removed, {$kept} kept as they were.");

        return self::SUCCESS;
    }
}
