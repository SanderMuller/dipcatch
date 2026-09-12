<?php declare(strict_types=1);

namespace App\PriceAdapters;

use App\Support\Gtin;
use DOMNode;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The microdata scopes that enclose an offer's price, nearest first.
 *
 * Every field is read from these scopes rather than from the page: a page
 * with several `Product` scopes (a related-products block) would otherwise
 * pair this offer's price with a neighbour's title, image or identifier.
 * Nested scopes own their own properties and never donate them outwards.
 */
final readonly class MicrodataScope
{
    /**
     * @param  list<Crawler>  $scopes
     */
    private function __construct(private array $scopes) {}

    public static function around(Crawler $priceNode): self
    {
        $scopes = [];

        foreach ($priceNode->ancestors() as $ancestor) {
            $element = new Crawler($ancestor);

            if ($element->attr('itemscope') !== null) {
                $scopes[] = $element;
            }
        }

        return new self($scopes);
    }

    /**
     * The value of `$itemprop` from the enclosing scopes. `$page` is used
     * only when the price sits outside every scope — a page-wide read is
     * otherwise how two products' fields get combined.
     */
    public function read(string $itemprop, Crawler $page): ?string
    {
        foreach ($this->scopes as $scope) {
            $node = self::ownProperty($scope, $itemprop);

            if ($node instanceof Crawler) {
                $value = self::readNode($node);

                if ($value !== null) {
                    return $value;
                }
            }
        }

        return $this->scopes === [] ? self::readNode($page->filter('[itemprop="' . $itemprop . '"]')->first()) : null;
    }

    public function gtin(): ?string
    {
        foreach ($this->scopes as $scope) {
            $fields = [];

            foreach (['gtin13', 'gtin14', 'gtin12', 'gtin8', 'gtin'] as $field) {
                $node = self::ownProperty($scope, $field);
                $fields[$field] = $node instanceof Crawler ? self::readNode($node) : null;
            }

            $gtin = Gtin::fromEntities([$fields]);

            if ($gtin !== null) {
                return $gtin;
            }
        }

        return null;
    }

    /**
     * The first node carrying this `itemprop` that belongs to `$scope`
     * itself — a nested `itemscope` (a recommended product inside the
     * product container) owns its own properties.
     */
    private static function ownProperty(Crawler $scope, string $itemprop): ?Crawler
    {
        $owner = $scope->getNode(0);

        foreach ($scope->filter('[itemprop="' . $itemprop . '"]') as $node) {
            $candidate = new Crawler($node);
            $nearest = self::nearestScopeNode($candidate);

            // PHP hands out a fresh object per DOM node access, so identity
            // has to be asked of the document, not of the wrapper.
            if ($owner !== null && $nearest !== null && $nearest->isSameNode($owner)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function nearestScopeNode(Crawler $node): ?DOMNode
    {
        foreach ($node->ancestors() as $ancestor) {
            if (new Crawler($ancestor)->attr('itemscope') !== null) {
                return $ancestor;
            }
        }

        return null;
    }

    /**
     * `content` first (the microdata convention), then the attribute that
     * carries the value for the element used — `href` on a link, `src` on an
     * image — and finally the element's text.
     */
    private static function readNode(Crawler $node): ?string
    {
        if ($node->count() === 0) {
            return null;
        }

        foreach (['content', 'href', 'src'] as $attribute) {
            $value = $node->attr($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $text = trim($node->text(''));

        return $text === '' ? null : $text;
    }
}
