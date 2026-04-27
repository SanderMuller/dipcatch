<?php declare(strict_types=1);

namespace App\Services\Scraper;

use Symfony\Component\DomCrawler\Crawler;
use Throwable;

final class MetadataExtractor
{
    /**
     * Extract a product title: explicit selector → og:title → <title> → host.
     */
    public function title(Crawler $crawler, string $url, ?string $selector): ?string
    {
        if ($selector !== null) {
            $custom = $this->firstMatchText($crawler, $selector);
            if ($custom !== null) {
                return trim($custom);
            }
        }

        $og = $crawler->filter('meta[property="og:title"]');
        if ($og->count() > 0) {
            $content = trim($og->attr('content') ?? '');
            if ($content !== '') {
                return $content;
            }
        }

        $title = $crawler->filter('title');
        if ($title->count() > 0) {
            $text = trim($title->text(''));
            if ($text !== '') {
                return $text;
            }
        }

        return parse_url($url, PHP_URL_HOST) ?: null;
    }

    /**
     * Extract a product image: explicit selector → og:image (resolved to absolute).
     */
    public function image(Crawler $crawler, string $url, ?string $selector): ?string
    {
        $candidate = null;

        if ($selector !== null) {
            try {
                $node = $crawler->filter($selector)->first();
                if ($node->count() > 0) {
                    $candidate = $node->attr('src') ?? $node->attr('content') ?? $node->attr('href');
                }
            } catch (Throwable) {
                $candidate = null;
            }
        }

        if ($candidate === null) {
            $og = $crawler->filter('meta[property="og:image"]');
            if ($og->count() > 0) {
                $candidate = $og->attr('content');
            }
        }

        if ($candidate === null || trim($candidate) === '') {
            return null;
        }

        return UrlResolver::resolve($url, trim($candidate));
    }

    /**
     * Read the first match's text (or `content`/`value` for meta/input nodes).
     */
    public function firstMatchText(Crawler $crawler, string $selector): ?string
    {
        try {
            $node = $crawler->filter($selector)->first();
        } catch (Throwable) {
            return null;
        }

        if ($node->count() === 0) {
            return null;
        }

        $tag = strtolower($node->nodeName());
        if ($tag === 'meta' || $tag === 'input') {
            $value = $node->attr('content') ?? $node->attr('value');

            return $value !== null && trim($value) !== '' ? $value : null;
        }

        $text = $node->text('');

        return trim($text) !== '' ? $text : null;
    }
}
