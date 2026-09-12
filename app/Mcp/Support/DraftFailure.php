<?php declare(strict_types=1);

namespace App\Mcp\Support;

/**
 * Why {@see DraftToken::open()} refused a token.
 *
 * One "that draft has expired" for every cause sent a caller who had merely
 * corrupted the token while copying it back to re-read the page, which spends
 * a probe and fixes nothing. Each cause needs its own recovery.
 */
enum DraftFailure: string
{
    /** Unreadable, edited, truncated, or the wrong shape. */
    case Malformed = 'malformed';

    /** Readable, but issued too long ago. */
    case Expired = 'expired';

    /** Issued to another account. */
    case WrongOwner = 'wrong_owner';

    /** Issued while previewing a different product. */
    case WrongProduct = 'wrong_product';

    /**
     * What the caller should be told, including what would fix it.
     */
    public function message(string $tool): string
    {
        return match ($this) {
            self::Malformed => 'That draft token could not be read. Send the draft exactly as it was returned, or call ' . $tool . ' again without confirm to read the page again.',
            self::Expired => 'That draft has expired. Call ' . $tool . ' again without confirm to re-read the page.',
            self::WrongOwner => 'That draft belongs to another account.',
            self::WrongProduct => 'That draft was prepared for a different product. Call ' . $tool . ' again without confirm for this product.',
        };
    }
}
