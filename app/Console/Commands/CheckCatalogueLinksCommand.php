<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Checkjebon\CatalogueLinks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('dipcatch:check-catalogue-links')]
#[Description("Record which Dirk products in the supermarket list are on dirk.nl, from Dirk's product sitemap, so a suggestion skips a page that is gone.")]
final class CheckCatalogueLinksCommand extends Command
{
    public function handle(CatalogueLinks $links): int
    {
        $counts = $links->importDirkSitemap();

        if ($counts === null) {
            $this->error("Dirk's product sitemap could not be read, or listed too few products. Nothing was changed.");

            return self::FAILURE;
        }

        $this->info("Dirk: {$counts['alive']} list products are on dirk.nl, {$counts['gone']} are not.");

        return self::SUCCESS;
    }
}
