<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\ShopDataExport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The admin Shops page's CSV exports on standard output, so an assistant can
 * read them through `cloud command:run`. Read-only, and carries no owner data;
 * see {@see ShopDataExport}.
 */
#[Signature('dipcatch:export-shop-data {dataset : hosts or urls}')]
#[Description('Print the hosts or product URLs export as CSV, without owner data. Read-only.')]
final class ExportShopDataCommand extends Command
{
    public function handle(): int
    {
        $rows = match ($this->argument('dataset')) {
            'hosts' => ShopDataExport::hosts(),
            'urls' => ShopDataExport::urls(),
            default => null,
        };

        if ($rows === null) {
            $this->error('Unknown dataset. Use "hosts" or "urls".');

            return self::INVALID;
        }

        foreach ($rows as $row) {
            $this->output->write(ShopDataExport::csvLine($row), options: OutputInterface::OUTPUT_RAW);
        }

        return self::SUCCESS;
    }
}
