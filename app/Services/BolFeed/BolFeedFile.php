<?php declare(strict_types=1);

namespace App\Services\BolFeed;

use Generator;
use RuntimeException;

/**
 * One group file of bol.com's product feed (`product-feed_<group>-v2.csv.gz`):
 * gzip, `|`-separated, every field quoted, a header row first. Read as a
 * stream, so a file of a million rows never sits in memory.
 */
final readonly class BolFeedFile
{
    public function __construct(private string $path) {}

    /**
     * The rows that can be offered in the Netherlands now: a selling price,
     * deliverable, and new rather than second-hand.
     *
     * @return Generator<int, array{product_id: string, ean: string, title: string, url: string, price: string}>
     */
    public function offers(): Generator
    {
        $handle = gzopen($this->path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open {$this->path}");
        }

        try {
            $header = fgetcsv($handle, 0, '|', '"', '');
            $columns = is_array($header) ? array_flip(array_map(strval(...), $header)) : [];

            foreach (['productId', 'ean', 'title', 'productPageUrlNL', 'OfferNL.sellingPrice', 'OfferNL.isDeliverable', 'OfferNL.condition'] as $required) {
                if (! isset($columns[$required])) {
                    throw new RuntimeException("The feed has no '{$required}' column.");
                }
            }

            while (($row = fgetcsv($handle, 0, '|', '"', '')) !== false) {
                $field = static fn (string $name): string => (string) ($row[$columns[$name]] ?? '');
                $price = $field('OfferNL.sellingPrice');

                if ($field('OfferNL.isDeliverable') !== 'Y' || $field('OfferNL.condition') !== 'new' || ! is_numeric($price) || (float) $price <= 0.0) {
                    continue;
                }

                yield [
                    'product_id' => $field('productId'),
                    'ean' => $field('ean'),
                    'title' => $field('title'),
                    'url' => $field('productPageUrlNL'),
                    'price' => $price,
                ];
            }
        } finally {
            gzclose($handle);
        }
    }
}
