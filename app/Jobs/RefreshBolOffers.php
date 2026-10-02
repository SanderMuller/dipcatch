<?php declare(strict_types=1);

namespace App\Jobs;

use App\Models\CheckjebonPrice;
use App\Services\BolApi\BolApiFailed;
use App\Services\BolApi\BolCatalogClient;
use App\Services\BolApi\BolProduct;
use App\Services\BolFeed\BolCatalogRows;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Sleep;

/**
 * Re-reads the stored bol.com rows that have a barcode from the Catalog
 * API, so a suggested price is at most a day old where the feed cannot be
 * imported (production: bol whitelists one IP address). Only a product bol
 * no longer knows leaves the catalogue; one without an offer today, out of
 * stock for example, keeps its row. Paced under bol's 10 requests a second.
 *
 * A queue job may run for 90 seconds, and at that pace the rows can take longer.
 * So each job stops after a fixed time and queues the next one at the id it
 * reached: one job at a time, which keeps the pace.
 */
#[Tries(1)]
#[Timeout(80)]
final class RefreshBolOffers implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const int WORK_SECONDS = 45;

    private const int PAUSE_MICROSECONDS = 110_000;

    public function __construct(public int $afterId = 0) {}

    public function uniqueId(): string
    {
        return "refresh-bol-offers:{$this->afterId}";
    }

    /**
     * Frees the lock should a queued job be lost, before the next night
     * queues the same start again.
     */
    public function uniqueFor(): int
    {
        return 3600;
    }

    public function handle(BolCatalogClient $bol): void
    {
        $now = now();
        $stopAt = CarbonImmutable::now()->addSeconds(self::WORK_SECONDS);

        $rows = CheckjebonPrice::query()
            ->where('supermarket', BolCatalogRows::CHAIN)
            ->whereNotNull('ean')
            ->where('id', '>', $this->afterId)
            ->lazyById();

        foreach ($rows as $row) {
            $this->refresh($bol, $row, $now);

            if (CarbonImmutable::now()->greaterThanOrEqualTo($stopAt)) {
                dispatch(new self($row->id));

                return;
            }
        }
    }

    private function refresh(BolCatalogClient $bol, CheckjebonPrice $row, DateTimeInterface $now): void
    {
        // The API takes EAN-13; a longer barcode cannot be asked for, and
        // must not read as "no longer sold".
        if (strlen((string) $row->ean) > 13) {
            return;
        }

        try {
            $offer = $bol->findByEan((string) $row->ean);
        } catch (BolApiFailed $e) {
            if (! $e->rateLimited()) {
                // Fails the job, which ends the run: rows not reached keep
                // their last price.
                throw $e;
            }

            Sleep::sleep(1);

            return;
        }

        $fresh = $offer instanceof BolProduct && $offer->price !== null
            ? BolCatalogRows::row($row->external_id, $offer->ean, $offer->title, $offer->url, $offer->price, $now)
            : null;

        if (! $offer instanceof BolProduct) {
            $row->delete();
        } elseif ($fresh !== null) {
            BolCatalogRows::store([$fresh], $now);
        }

        Sleep::usleep(self::PAUSE_MICROSECONDS);
    }
}
