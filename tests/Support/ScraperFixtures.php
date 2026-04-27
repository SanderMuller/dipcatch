<?php declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Products\AutoDetect;
use App\Services\Scraper\CurrencyDetector;
use App\Services\Scraper\FetchHtml;
use App\Services\Scraper\HostThrottle;
use App\Services\Scraper\HtmlScraper;
use App\Services\Scraper\JsonLdPriceExtractor;
use App\Services\Scraper\MetadataExtractor;
use App\Services\Scraper\PriceParser;
use App\Services\Scraper\RobotsGate;
use Illuminate\Http\Client\Factory as HttpFactory;

final class ScraperFixtures
{
    public static function load(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../Fixtures/scraper/' . $name);
    }

    public static function makeScraper(): HtmlScraper
    {
        $http = app(HttpFactory::class);

        return new HtmlScraper(
            $http,
            new PriceParser(),
            new CurrencyDetector(),
            new RobotsGate($http),
            new HostThrottle(),
            new MetadataExtractor(),
            new JsonLdPriceExtractor(),
        );
    }

    public static function makeAutoDetect(): AutoDetect
    {
        return new AutoDetect(
            new FetchHtml(app(HttpFactory::class)),
            new MetadataExtractor(),
        );
    }
}
