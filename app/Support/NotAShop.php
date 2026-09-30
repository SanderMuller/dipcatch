<?php declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Config;

/**
 * Hosts that are not a shop a consumer orders from, from
 * `dipcatch.not_a_shop`: comparison sites, social media, wholesale. A
 * comparison page states another shop's price as its own, so tracking one
 * would store a price nobody can buy there.
 */
final class NotAShop
{
    public static function covers(string $host): bool
    {
        $host = UrlNormalizer::normalizeHost($host);

        /** @var list<string> $listed */
        $listed = Config::array('dipcatch.not_a_shop');

        return array_any($listed, static fn (string $entry): bool => $host === $entry || str_ends_with($host, ".{$entry}"));
    }
}
