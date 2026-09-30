<?php declare(strict_types=1);

namespace App\Models;

use App\Support\SupermarketChains;
use App\Support\UrlNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shop a person chose never to have suggested ("Don't suggest"). It
 * hides suggestions only: shops they track there keep working.
 *
 * @property int $id
 * @property int $user_id
 * @property string $host
 * @property string $label
 * @property CarbonImmutable $hidden_at
 */
#[WithoutTimestamps]
#[Unguarded]
final class HiddenShop extends Model
{
    /**
     * The hosts a person hid, normalized as `shops.host` is.
     *
     * @return list<string>
     */
    public static function hostsOf(?User $user): array
    {
        if (! $user instanceof User) {
            return [];
        }

        /** @var list<string> */
        return self::query()->where('user_id', $user->id)->pluck('host')->all();
    }

    /**
     * Whether a hidden host covers this one: the same host, or a subdomain of it.
     *
     * @param  list<string>  $hidden
     */
    public static function covers(array $hidden, string $host): bool
    {
        $host = UrlNormalizer::normalizeHost($host);

        return $host !== '' && array_any($hidden, static fn (string $entry): bool => $host === $entry || str_ends_with($host, ".{$entry}"));
    }

    /**
     * The dataset chains none of whose hosts the person hid.
     *
     * @param  array<string, CheckjebonChain>  $chains
     * @return array<string, CheckjebonChain>
     */
    public static function withoutHiddenChains(?User $user, array $chains): array
    {
        $hidden = self::hostsOf($user);

        if ($hidden === []) {
            return $chains;
        }

        return array_filter($chains, static fn (CheckjebonChain $chain): bool => ! array_any(
            SupermarketChains::hosts($chain->chain, $chain->base_url),
            static fn (string $host): bool => self::covers($hidden, $host),
        ));
    }

    /**
     * Hides a shop and returns the name to show for it: the dataset chain's
     * name when one of its hosts matches, else the host.
     */
    public static function hide(User $user, string $host): string
    {
        $host = UrlNormalizer::normalizeHost($host);
        $label = self::labelFor($host);

        self::query()->insertOrIgnore([
            'user_id' => $user->id,
            'host' => $host,
            'label' => mb_substr($label, 0, 255),
            'hidden_at' => now(),
        ]);

        return $label;
    }

    private static function labelFor(string $host): string
    {
        $chain = CheckjebonChain::query()->get()->first(
            static fn (CheckjebonChain $chain): bool => in_array($host, SupermarketChains::hosts($chain->chain, $chain->base_url), strict: true),
        );

        return $chain instanceof CheckjebonChain ? self::displayName($chain->label) : $host;
    }

    /**
     * A shop's name for a sentence or a menu: the dataset's source note
     * dropped, so "Lidl (via boodschaapje.nl)" reads "Lidl".
     */
    public static function displayName(string $label): string
    {
        return trim(preg_replace('/\s*\(via [^)]*\)$/u', '', $label) ?? $label);
    }

    public static function showAgain(User $user, string $host): void
    {
        self::query()->where('user_id', $user->id)->where('host', $host)->delete();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hidden_at' => 'datetime',
        ];
    }
}
