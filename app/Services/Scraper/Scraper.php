<?php declare(strict_types=1);

namespace App\Services\Scraper;

interface Scraper
{
    public function scrape(ScrapeRequest $request): ScrapeResult;
}
