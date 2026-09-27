<?php declare(strict_types=1);

namespace App\Services\ShopFetcher;

use Closure;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * The response sink, refusing to grow past the body cap.
 *
 * curl decompresses before it writes here, so the cap holds against what the
 * page expands to, not what came over the wire: a 10 MB gzip bomb that
 * unpacks to gigabytes stops at the cap instead of filling memory first.
 */
final class CappedStream implements StreamInterface
{
    use StreamDecoratorTrait;

    public function __construct(private StreamInterface $stream, private readonly int $cap) {}

    /**
     * Guzzle middleware giving every request, each redirect hop included, a
     * sink of its own. One `sink` option is handed to every hop, so a
     * redirect's body would otherwise count against the page, or be left in
     * front of it.
     *
     * @return Closure(callable): Closure
     */
    public static function middleware(int $cap): Closure
    {
        return static fn (callable $handler): Closure => static fn (RequestInterface $request, array $options) => $handler(
            $request,
            ['sink' => new self(Utils::streamFor(Utils::tryFopen('php://temp', 'w+')), $cap)] + $options,
        );
    }

    public function write(string $string): int
    {
        if (($this->stream->getSize() ?? 0) + strlen($string) > $this->cap) {
            throw new BodyTooLarge("Response body exceeds {$this->cap} bytes.");
        }

        return $this->stream->write($string);
    }
}
