<?php declare(strict_types=1);

use App\Services\ShopFetcher\BodyTooLarge;
use App\Services\ShopFetcher\CappedStream;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;

it('keeps a body up to the cap', function (): void {
    $stream = new CappedStream(Utils::streamFor(), 10);

    $stream->write('12345');
    $stream->write('67890');

    expect((string) $stream)->toBe('1234567890');
});

it('stops the moment a body grows past the cap', function (): void {
    $stream = new CappedStream(Utils::streamFor(), 10);
    $stream->write('12345678');

    expect(fn (): int => $stream->write('901'))->toThrow(BodyTooLarge::class);
});

/**
 * Through a real Guzzle stack rather than Http::fake(), which never writes to
 * a sink: this is the path a page actually takes.
 */
function cappedClient(int $cap, Response ...$responses): Client
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(CappedStream::middleware($cap));

    return new Client(['handler' => $stack]);
}

it('gives every redirect hop its own sink, so a redirect body neither counts nor lingers', function (): void {
    $client = cappedClient(
        50,
        new Response(302, ['Location' => 'http://shop.test/final'], str_repeat('R', 40)),
        new Response(200, [], str_repeat('F', 30)),
    );

    expect((string) $client->get('http://shop.test/start')->getBody())->toBe(str_repeat('F', 30));
});

it('refuses a body past the cap while it arrives', function (): void {
    $client = cappedClient(50, new Response(200, [], str_repeat('B', 60)));

    $causes = [];

    try {
        $client->get('http://shop.test/p');
    } catch (Throwable $e) {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            $causes[] = $cause::class;
        }
    }

    expect($causes)->toContain(BodyTooLarge::class);
});
