<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Suggestions\SuggestShops;
use App\Services\BolFeed\BolCatalogRows;
use App\Services\BolFeed\BolFeedDownloader;
use App\Services\BolFeed\BolFeedFile;
use App\Services\BolFeed\FeedInterest;
use App\Services\BolFeed\FeedSource;
use DateTimeInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Imports bol.com's affiliate product feed as one more chain of the
 * suggestion catalogue (`checkjebon_prices`, chain `bol`). The feed holds
 * millions of rows, so only the ones a tracked product could be offered are
 * kept ({@see FeedInterest}). A group that fails to download or read keeps
 * the rows of the last good import.
 */
#[Signature('dipcatch:import-bol-feed {--group=* : Import only these groups, for example supermarket}')]
#[Description('Import the bol.com product feed groups DipCatch suggests from (FTPS; needs a whitelisted IP address).')]
final class ImportBolFeedCommand extends Command
{
    /** The groups of things people buy again and again. */
    private const array GROUPS = ['supermarket', 'daily-care', 'health', 'pet', 'perfumery', 'baby'];

    private const int UPSERT_CHUNK = 1000;

    /** Longer than a day, so a row the API refresh read yesterday survives. */
    private const int KEEP_HOURS = 36;

    public function handle(FeedSource $downloader, SuggestShops $suggest): int
    {
        if (! BolFeedDownloader::configured()) {
            $this->warn('No bol.com feed username configured; nothing imported.');

            return self::SUCCESS;
        }

        $interest = FeedInterest::build($suggest);

        if ($interest->isEmpty()) {
            $this->info('No products to match; nothing imported.');

            return self::SUCCESS;
        }

        $runStartedAt = now();
        $groups = $this->option('group') === [] ? self::GROUPS : array_values(array_intersect(self::GROUPS, (array) $this->option('group')));
        $directory = storage_path('app/private/bol-feed');
        File::ensureDirectoryExists($directory);
        $failed = 0;
        $emptyGroups = 0;

        foreach ($groups as $group) {
            $file = "product-feed_{$group}-v2.csv.gz";
            $path = "{$directory}/{$file}";

            try {
                $downloader->download($file, $path);
                $kept = $this->store(new BolFeedFile($path), $interest, $runStartedAt);
                $emptyGroups += $kept === 0 ? 1 : 0;
                $this->info("{$group}: {$kept} rows kept.");
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error("{$group}: {$e->getMessage()} Existing rows kept.");
            } finally {
                File::delete($path);
            }
        }

        if ($failed > 0 || $groups !== self::GROUPS) {
            // A partial run cannot tell a delisted row from one in a group it
            // did not read.
            return $failed > 0 ? self::FAILURE : self::SUCCESS;
        }

        if ($emptyGroups > 0) {
            // A group with nothing wanted is far likelier a changed feed
            // format than a real answer; pruning on it would empty the chain.
            $this->warn("{$emptyGroups} groups kept no rows; nothing removed.");

            return self::SUCCESS;
        }

        // Rows the daily API refresh keeps current, including ones found by
        // the live lookup outside these groups, are left alone.
        $pruned = DB::table('checkjebon_prices')
            ->where('supermarket', BolCatalogRows::CHAIN)
            ->where('refreshed_at', '<', $runStartedAt->copy()->subHours(self::KEEP_HOURS))
            ->delete();
        $this->info("{$pruned} rows no longer wanted or no longer offered were removed.");

        return self::SUCCESS;
    }

    private function store(BolFeedFile $feed, FeedInterest $interest, DateTimeInterface $refreshedAt): int
    {
        $kept = 0;
        $chunk = [];

        foreach ($feed->offers() as $offer) {
            $row = $interest->wants($offer['title'], $offer['ean'])
                ? BolCatalogRows::row($offer['product_id'], $offer['ean'], $offer['title'], $offer['url'], $offer['price'], $refreshedAt)
                : null;

            if ($row === null) {
                continue;
            }

            $chunk[] = $row;

            if (count($chunk) === self::UPSERT_CHUNK) {
                $kept += BolCatalogRows::store($chunk, $refreshedAt);
                $chunk = [];
            }
        }

        return $kept + BolCatalogRows::store($chunk, $refreshedAt);
    }
}
