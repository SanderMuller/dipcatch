<?php declare(strict_types=1);

namespace App\Mcp\Support;

use App\Actions\Shops\ShopDraft;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/**
 * Carries a probed snapshot between the preview call and the confirm call.
 *
 * The alternative — re-probing on confirm — spends a second of the six
 * fetches a user gets per minute, and would store whatever the *second* fetch
 * returned rather than what the user was shown and agreed to.
 *
 * It carries the snapshot rather than a decomposed draft so the confirm path
 * rebuilds through `ShopDraft::fromSnapshot()`, the same reader the web uses.
 * Encrypted, so a client cannot read or edit a price; stamped, so a stale
 * draft is refused instead of quietly writing an old one; and bound to the
 * account it was issued for, so it means what it claims — that this user saw
 * this and agreed to it.
 */
final readonly class DraftToken
{
    private const int TTL_SECONDS = 900;

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function issue(User $owner, array $snapshot, string $url, string $adapterKey, ?string $variantKey, ?string $productId = null): string
    {
        return Crypt::encryptString((string) json_encode([
            'v' => 1,
            'at' => CarbonImmutable::now()->getTimestamp(),
            'uid' => self::ownerKey($owner),
            'pid' => $productId,
            'snapshot' => $snapshot,
            'url' => $url,
            'adapterKey' => $adapterKey,
            'variantKey' => $variantKey,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Null when the token is unreadable, the wrong shape, or expired.
     */
    private static function ownerKey(User $owner): string
    {
        $key = $owner->getKey();

        return is_scalar($key) ? (string) $key : '';
    }

    public static function open(User $owner, string $token, ?string $productId = null): ?ShopDraft
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        if (! is_array($payload)) {
            return null;
        }

        $issuedAt = $payload['at'] ?? null;

        if (! is_int($issuedAt) || $issuedAt + self::TTL_SECONDS < CarbonImmutable::now()->getTimestamp()) {
            return null;
        }

        // Bound to its issuer: a draft is a record that one account approved
        // what it was shown, so another account cannot spend it.
        if (($payload['uid'] ?? null) !== self::ownerKey($owner)) {
            return null;
        }

        // The probe checked this snapshot against one product's currency, so
        // confirming it onto a different product would write a shop that
        // product's own guard would have refused.
        if (($payload['pid'] ?? null) !== $productId) {
            return null;
        }

        $snapshot = $payload['snapshot'] ?? null;
        $url = $payload['url'] ?? null;
        $adapterKey = $payload['adapterKey'] ?? null;
        $variantKey = $payload['variantKey'] ?? null;

        if (! is_array($snapshot) || ! is_string($url) || ! is_string($adapterKey)) {
            return null;
        }

        $clean = [];

        foreach ($snapshot as $key => $value) {
            if (is_string($key)) {
                $clean[$key] = $value;
            }
        }

        return ShopDraft::fromSnapshot(
            snapshot: $clean,
            url: $url,
            adapterKey: $adapterKey,
            variantKey: is_string($variantKey) ? $variantKey : null,
        );
    }
}
