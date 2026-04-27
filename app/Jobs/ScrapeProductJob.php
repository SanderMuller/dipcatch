<?php declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Drops\DetectDrop;
use App\Actions\Scraper\RecordScrape;
use App\Enums\ScrapeStatus;
use App\Models\Product;
use App\Services\Scraper\Scraper;
use App\Services\Scraper\ScrapeRequest;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ScrapeProductJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 1800];

    public int $timeout = 30;

    public function __construct(public Product $product) {}

    public function handle(Scraper $scraper, RecordScrape $record, DetectDrop $detect): void
    {
        $result = $scraper->scrape(ScrapeRequest::fromProduct($this->product));

        if ($result->status === ScrapeStatus::Throttled) {
            $this->release(random_int(30, 90));

            return;
        }

        $record($this->product, $result);

        if ($result->status === ScrapeStatus::Ok) {
            $fresh = $this->product->fresh();
            if ($fresh !== null) {
                $detect($fresh);
            }
        }
    }

    public function uniqueId(): string
    {
        return "scrape:{$this->product->id}";
    }

    public function uniqueFor(): int
    {
        return 60;
    }
}
