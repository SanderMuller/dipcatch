<?php declare(strict_types=1);

namespace App\Services\BolFeed;

use FTP\Connection;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Fetches a file of bol.com's product feed over FTPS (explicit TLS).
 *
 * The server only answers an IP address whitelisted in the affiliate
 * portal, and it ends the TLS session uncleanly after a transfer: a plain
 * `ftp_get()` then waits forever for a close that never comes. So the
 * transfer runs non-blocking and stops once every byte of the file's
 * known size has arrived.
 */
final readonly class BolFeedDownloader implements FeedSource
{
    private const int TIMEOUT_SECONDS = 60;

    /** A group file is at most about 600 MB; this caps a stalled transfer. */
    private const int MAX_SECONDS = 3600;

    public static function configured(): bool
    {
        return Config::string('services.bol.feed.username') !== '';
    }

    public function download(string $file, string $target): void
    {
        $ftp = @ftp_ssl_connect(Config::string('services.bol.feed.host'), 21, self::TIMEOUT_SECONDS);

        if ($ftp === false) {
            throw new RuntimeException('Cannot reach the bol.com feed server. Is this IP address whitelisted?');
        }

        try {
            if (! @ftp_login($ftp, Config::string('services.bol.feed.username'), Config::string('services.bol.feed.password'))) {
                throw new RuntimeException('The bol.com feed server refused the login.');
            }

            ftp_pasv($ftp, true);
            $size = ftp_size($ftp, $file);

            if ($size <= 0) {
                throw new RuntimeException("The bol.com feed has no file {$file}.");
            }

            $this->transfer($ftp, $file, $target, $size);
        } finally {
            // The server's unclean TLS shutdown makes this warn; the file is
            // already complete by then.
            @ftp_close($ftp);
        }
    }

    private function transfer(Connection $ftp, string $file, string $target, int $size): void
    {
        $out = fopen($target, 'w');

        if ($out === false) {
            throw new RuntimeException("Cannot write {$target}");
        }

        $deadline = time() + self::MAX_SECONDS;

        try {
            $status = ftp_nb_fget($ftp, $out, $file, FTP_BINARY);

            while ($status === FTP_MOREDATA && ftell($out) < $size && time() < $deadline) {
                $status = ftp_nb_continue($ftp);
            }
        } finally {
            $written = ftell($out);
            fclose($out);
        }

        if ($written !== $size) {
            throw new RuntimeException("Downloaded {$written} of {$size} bytes of {$file}.");
        }
    }
}
