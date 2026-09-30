<?php declare(strict_types=1);

namespace App\Jobs;

use App\Models\WebShopFinding;
use App\Services\ShopDiscovery\WebPageReads;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Reads one page a web search found, through the normal add-shop probe. A
 * rate-limited or temporary failure releases the job to try again later;
 * the dev worker runs with `--tries=1`, so the tries are stated here.
 */
#[Timeout(60)]
final class ReadWebFinding implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public int $findingId) {}

    public function tries(): int
    {
        return Config::integer('dipcatch.web_discovery.read_attempts');
    }

    public function uniqueId(): string
    {
        return "read-web-finding:{$this->findingId}";
    }

    /** Longer than every delay the retries can add up to. */
    public function uniqueFor(): int
    {
        return $this->tries() * Config::integer('dipcatch.web_discovery.retry_max_seconds') + 600;
    }

    public function handle(WebPageReads $reads): void
    {
        $finding = WebShopFinding::query()->with('product.shops', 'product.user')->find($this->findingId);

        if (! $finding instanceof WebShopFinding) {
            return;
        }

        $delay = $reads->read($finding);

        if ($delay !== null) {
            $this->release($delay);
        }
    }

    /** A crash or the last try spent: the finding does not stay pending. */
    public function failed(?Throwable $exception = null): void
    {
        $finding = WebShopFinding::query()->with('product.shops', 'product.user')->find($this->findingId);

        if ($finding instanceof WebShopFinding) {
            app(WebPageReads::class)->giveUp($finding, 'worker_failed: ' . ($exception === null ? 'unknown' : class_basename($exception)));
        }
    }
}
