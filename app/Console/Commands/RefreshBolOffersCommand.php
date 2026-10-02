<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RefreshBolOffers;
use App\Services\BolApi\BolCatalogClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Queues the daily re-read of the stored bol.com rows; see
 * {@see RefreshBolOffers}. The rows can take longer than the App stays awake
 * after a scheduled run, so the work runs on the queue.
 */
#[Signature('dipcatch:refresh-bol-offers')]
#[Description('Refresh the stored bol.com suggestion rows from the bol.com Catalog API.')]
final class RefreshBolOffersCommand extends Command
{
    public function handle(): int
    {
        if (! BolCatalogClient::configured()) {
            $this->warn('No bol.com API client id configured; nothing refreshed.');

            return self::SUCCESS;
        }

        dispatch(new RefreshBolOffers());

        $this->info('Queued the bol.com refresh.');

        return self::SUCCESS;
    }
}
