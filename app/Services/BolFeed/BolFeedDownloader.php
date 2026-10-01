<?php declare(strict_types=1);

namespace App\Services\BolFeed;

use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Fetches a file of bol.com's product feed over FTPS (explicit TLS), with
 * curl: PHP's own `ftp_ssl_connect()` does not check the server's
 * certificate, so the feed login could go to whoever answers.
 *
 * The server only answers an IP address whitelisted in the affiliate
 * portal, and it ends the TLS session uncleanly after a transfer. curl then
 * reports an error although every byte arrived, so the file counts as
 * complete when its size matches the size the server announced.
 */
final readonly class BolFeedDownloader implements FeedSource
{
    private const string HOST = 'apm-feed.unftp.bol.com';

    private const int CONNECT_SECONDS = 60;

    /** A group file is at most about 600 MB; this caps a stalled transfer. */
    private const int MAX_SECONDS = 3600;

    public static function configured(): bool
    {
        return Config::string('services.bol.feed.username') !== '';
    }

    public function download(string $file, string $target): void
    {
        $out = fopen($target, 'w');

        if ($out === false) {
            throw new RuntimeException("Cannot write {$target}");
        }

        $curl = curl_init('ftp://' . self::HOST . '/' . rawurlencode($file));
        curl_setopt_array($curl, [
            CURLOPT_USE_SSL => CURLUSESSL_ALL,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERPWD => Config::string('services.bol.feed.username') . ':' . Config::string('services.bol.feed.password'),
            CURLOPT_FTP_USE_EPSV => false,
            CURLOPT_FILE => $out,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_SECONDS,
            CURLOPT_TIMEOUT => self::MAX_SECONDS,
        ]);

        try {
            curl_exec($curl);
            $error = curl_errno($curl) === 0 ? null : curl_error($curl);
            $expected = (int) curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD_T);
            $written = (int) curl_getinfo($curl, CURLINFO_SIZE_DOWNLOAD_T);
        } finally {
            fclose($out);
        }

        if ($expected > 0 && $written === $expected) {
            return;
        }

        throw new RuntimeException($error === null
            ? "Downloaded {$written} of {$expected} bytes of {$file}."
            : "Could not download {$file} from bol.com ({$error}). Is this IP address whitelisted?");
    }
}
