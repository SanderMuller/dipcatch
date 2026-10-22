<?php declare(strict_types=1);

namespace App\Support;

/** Tells a pasted shop link from a typed product name in the add-product wizard's one input. */
final class TypedLink
{
    /**
     * The link in the typed text, or null for a name. A link starts with a
     * scheme in any case, or with a host and a path (`ah.nl/producten/…`),
     * which gets `https://`. Words after it are dropped. The last host label
     * is letters only, so a name such as `1.5/2 liter` stays a name.
     */
    public static function in(string $text): ?string
    {
        $first = preg_split('/\s+/u', trim($text), 2)[0] ?? '';

        if (preg_match('~^https?://~i', $first) === 1) {
            return $first;
        }

        if (preg_match('~^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}/~i', $first) === 1) {
            return 'https://' . $first;
        }

        return null;
    }
}
