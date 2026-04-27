<?php declare(strict_types=1);

namespace App\Services\Scraper;

use JsonException;
use Symfony\Component\DomCrawler\Crawler;

final class JsonLdPriceExtractor
{
    public function extract(Crawler $crawler): ?string
    {
        foreach ($crawler->filter('script[type="application/ld+json"]') as $node) {
            $text = trim($node->textContent);
            if ($text === '') {
                continue;
            }

            try {
                $data = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            $price = $this->walk($data);
            if ($price !== null) {
                return $price;
            }
        }

        return null;
    }

    private function walk(mixed $data): ?string
    {
        if (! is_array($data)) {
            return null;
        }

        if (isset($data['@type']) && in_array($data['@type'], ['Product', 'Offer'], true)) {
            $price = $data['offers']['price'] ?? $data['price'] ?? null;
            if (is_string($price) || is_numeric($price)) {
                return (string) $price;
            }
        }

        if (isset($data['price']) && (is_string($data['price']) || is_numeric($data['price']))) {
            return (string) $data['price'];
        }

        foreach ($data as $value) {
            $nested = $this->walk($value);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }
}
