<?php declare(strict_types=1);

namespace App\Services\ShopFetcher;

/**
 * A bot wall's page served in place of the one asked for. Read from the
 * start of the body, where a challenge page names itself.
 */
final readonly class ChallengePage
{
    /** @var list<string> Lowercased substrings that indicate WAF challenge pages. */
    private const array MARKERS = [
        'cf-mitigated',
        'just a moment',
        'access denied',
        'attention required! | cloudflare',
        'akamai reference',
        'perimeterx',
        'px-captcha',
        // Imperva/Incapsula serves a 200 with a tiny iframe shell. Without
        // this marker the generic adapter can read a number out of the
        // challenge page and store it as a price (hoogvliet.com, 2026-09-01).
        'incapsula incident id',
        '_incapsula_resource',
        // A script challenge served as a 200 shell with nothing to read
        // (rossmann.de, 2026-10-06). Without it the read fails as "no
        // reader", which says the shop is unsupported, not that it refused.
        '<title>client challenge</title>',
        // PerimeterX's own pages, served on 200 at a `/blocked` or
        // `/are-you-human` address (walmart.com, samsclub.com) or on 307
        // (gnc.com), 2026-10-06. Its script on an ordinary page carries
        // neither of these, so a product page that loads it still reads.
        '<title>robot or human?</title>',
        "let us know you're not a robot",
        'access to this page has been denied',
        // Baleen's script challenge (cdiscount.com, 2026-10-06).
        '__blnchallengestore',
    ];

    public static function in(string $body): bool
    {
        if ($body === '') {
            return false;
        }

        $head = strtolower(substr($body, 0, 4096));

        return array_any(self::MARKERS, static fn (string $marker): bool => str_contains($head, $marker));
    }
}
