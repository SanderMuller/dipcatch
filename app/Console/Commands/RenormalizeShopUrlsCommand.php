<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Shop;
use App\Support\UrlNormalizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Bring stored shop URLs in line with the current normalizer.
 *
 * The normalizer decides a shop's identity. When it learns to strip another
 * tracking parameter, a row stored with that parameter keeps its old hash:
 * the same page pasted again is no longer caught as a duplicate, and rows on
 * one page stop sharing a reading. This rewrites those rows.
 *
 * A dry run unless `--apply`. Two rows of one product that would collapse to
 * one address are reported and left alone — which of them to keep is a
 * person's call, not this command's.
 */
#[Signature('dipcatch:renormalize-shop-urls {--apply : Write the changes; without it nothing is written}')]
#[Description('Rewrite stored shop URLs and hashes to the current normalizer, reporting collisions.')]
final class RenormalizeShopUrlsCommand extends Command
{
    public function handle(): int
    {
        $apply = $this->option('apply') === true;
        $changed = 0;
        $collisions = [];
        $invalid = 0;

        Shop::query()->orderBy('id')->each(function (Shop $shop) use ($apply, &$changed, &$collisions, &$invalid): void {
            try {
                $normalized = UrlNormalizer::normalize($shop->url);
            } catch (InvalidArgumentException) {
                $invalid++;

                return;
            }

            $hash = UrlNormalizer::hash($normalized);

            if ($normalized === $shop->url && $hash === $shop->url_hash) {
                return;
            }

            $taken = Shop::query()
                ->where('product_id', $shop->product_id)
                ->where('url_hash', $hash)
                ->whereKeyNot($shop->getKey())
                ->exists();

            if ($taken) {
                $collisions[] = [$shop->id, (string) $shop->host];

                return;
            }

            $changed++;

            if ($apply) {
                $shop->forceFill(['url' => $normalized, 'url_hash' => $hash])->save();
            }
        });

        $this->info(($apply ? 'Rewrote' : 'Would rewrite') . " {$changed} shop URL(s).");

        if ($invalid > 0) {
            $this->warn("{$invalid} stored URL(s) could not be parsed and were left alone.");
        }

        if ($collisions !== []) {
            $this->warn(count($collisions) . ' shop(s) would collide with another shop of the same product and were left alone:');
            $this->table(['shop', 'host'], $collisions);
        }

        return self::SUCCESS;
    }
}
