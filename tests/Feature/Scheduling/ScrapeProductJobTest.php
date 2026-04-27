<?php declare(strict_types=1);

use App\Actions\Drops\DetectDrop;
use App\Actions\Scraper\RecordScrape;
use App\Enums\ScrapeStatus;
use App\Jobs\ScrapeProductJob;
use App\Models\PriceCheck;
use App\Models\Product;
use App\Models\User;
use App\Services\Scraper\Scraper;
use App\Services\Scraper\ScrapeRequest;
use App\Services\Scraper\ScrapeResult;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

function bindScraper(ScrapeResult $result): Scraper
{
    $scraper = new class ($result) implements Scraper {
        public int $calls = 0;

        public function __construct(public ScrapeResult $result) {}

        public function scrape(ScrapeRequest $request): ScrapeResult
        {
            $this->calls++;

            return $this->result;
        }
    };

    app()->instance(Scraper::class, $scraper);

    return $scraper;
}

test('happy path records a price_check and updates last_price', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create([
        'initial_price' => '100.00',
        'last_price' => '100.00',
    ]);

    $scraper = bindScraper(ScrapeResult::ok('€80,00', '80.00', 'EUR', null, null));

    new ScrapeProductJob($product)->handle(
        $scraper,
        app(RecordScrape::class),
        app(DetectDrop::class),
    );

    expect(PriceCheck::query()->where('product_id', $product->id)->count())->toBe(1);
    expect($product->fresh()->last_price)->toBe('80.00');
    expect($product->fresh()->last_status)->toBe(ScrapeStatus::Ok);
});

test('Throttled status releases the job without recording a price_check', function (): void {
    $product = Product::factory()->create();
    $scraper = bindScraper(ScrapeResult::failure(ScrapeStatus::Throttled, 'host throttled'));

    $job = new class ($product) extends ScrapeProductJob {
        public ?int $released = null;

        public function release($delay = 0): void
        {
            $this->released = is_int($delay) ? $delay : 0;
        }
    };

    $job->handle($scraper, app(RecordScrape::class), app(DetectDrop::class));

    expect($job->released)->not->toBeNull();
    expect(PriceCheck::query()->where('product_id', $product->id)->count())->toBe(0);
});

test('non-Ok non-Throttled status records the check but does not invoke detector', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    $product = Product::factory()->for($user)->create([
        'initial_price' => '100.00',
        'last_price' => '100.00',
    ]);

    $scraper = bindScraper(ScrapeResult::failure(ScrapeStatus::HttpError, 'boom'));

    new ScrapeProductJob($product)->handle(
        $scraper,
        app(RecordScrape::class),
        app(DetectDrop::class),
    );

    expect(PriceCheck::query()
        ->where('product_id', $product->id)
        ->where('status', ScrapeStatus::HttpError)
        ->count())->toBe(1);
    expect($product->fresh()->last_price)->toBe('100.00');
});

test('job retry config matches spec', function (): void {
    $job = new ScrapeProductJob(Product::factory()->create());

    expect($job->tries)->toBe(3);
    expect($job->timeout)->toBe(30);
    expect($job->backoff)->toBe([60, 300, 1800]);
    expect($job->uniqueFor())->toBe(60);
    expect($job->uniqueId())->toStartWith('scrape:');
});

test('uniqueId is product-specific to prevent duplicate dispatch within window', function (): void {
    $a = Product::factory()->create();
    $b = Product::factory()->create();

    expect(new ScrapeProductJob($a)->uniqueId())
        ->not->toBe(new ScrapeProductJob($b)->uniqueId());
});

test('dispatch goes through Bus when queued', function (): void {
    Bus::fake();
    Queue::fake();

    $product = Product::factory()->create();
    dispatch(new ScrapeProductJob($product))->onQueue('scrapes');

    Bus::assertDispatched(ScrapeProductJob::class);
});
