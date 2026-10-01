<?php declare(strict_types=1);

namespace App\Services\BolFeed;

use Illuminate\Container\Attributes\Bind;

/**
 * Where the import gets a group file of bol.com's product feed. An
 * interface so tests hand out local files instead of reaching the FTPS
 * server, which only answers a whitelisted IP address.
 */
#[Bind(BolFeedDownloader::class)]
interface FeedSource
{
    /** Writes the feed file `$file` to the local path `$target`, or throws. */
    public function download(string $file, string $target): void;
}
