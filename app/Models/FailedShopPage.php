<?php declare(strict_types=1);

namespace App\Models;

use App\Actions\Shops\ProbeOutcome;
use App\Enums\ProbeFailure;
use App\Support\UrlNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A shop page someone tried to add and DipCatch could not read, kept so we
 * can make that shop work. One row per page; a later successful add of the
 * same page removes it. Kept for six months after the last failed try.
 *
 * @property int $id
 * @property string $url
 * @property string $url_hash
 * @property string $host
 * @property ProbeFailure $failure
 * @property ?string $reason
 * @property ?int $user_id
 * @property int $times
 * @property CarbonImmutable $first_failed_at
 * @property CarbonImmutable $last_failed_at
 */
#[WithoutTimestamps]
#[Unguarded]
final class FailedShopPage extends Model
{
    use MassPrunable;

    /**
     * Failures that say nothing about the shop: a typo, our own pacing, a
     * shop answering "later", a site that is no shop, or a page in another
     * currency than the product.
     */
    private const array NOT_ABOUT_THE_SHOP = [
        ProbeFailure::InvalidUrl,
        ProbeFailure::ProbeRateLimited,
        ProbeFailure::LocalThrottle,
        ProbeFailure::HostRateLimited,
        ProbeFailure::NotAShop,
        ProbeFailure::CurrencyMismatch,
    ];

    /**
     * The failures worth a row.
     *
     * @return list<ProbeFailure>
     */
    public static function recordable(): array
    {
        return array_values(array_filter(ProbeFailure::cases(), static fn (ProbeFailure $failure): bool => ! in_array($failure, self::NOT_ABOUT_THE_SHOP, strict: true)));
    }

    /**
     * Records what a probe a person asked for says about the page: a failure
     * worth looking into adds a row or counts one more try, and a page read
     * without help from the person removes its row. A probe with the
     * person's own selectors says something about those selectors, not the
     * shop, so it changes nothing.
     *
     * @param  array<string, ?string>  $selectors  The person's own selectors, when they gave any.
     */
    public static function recordOutcome(string $rawUrl, ProbeOutcome $outcome, ?User $user, array $selectors = []): void
    {
        try {
            $url = UrlNormalizer::normalize(trim($rawUrl));
        } catch (InvalidArgumentException) {
            return;
        }

        if (array_filter($selectors) !== []) {
            return;
        }

        $hash = UrlNormalizer::hash($url);

        if ($outcome->isSuccess()) {
            self::query()->where('url_hash', $hash)->delete();

            return;
        }

        if ($outcome->isDuplicate() || $outcome->isAmbiguous() || ! $outcome->errorCode instanceof ProbeFailure || ! in_array($outcome->errorCode, self::recordable(), strict: true)) {
            return;
        }

        $reason = $outcome->extractionReason ?? (is_string($outcome->context['reason'] ?? null) ? $outcome->context['reason'] : null);

        self::query()->upsert(
            [[
                'url' => $url,
                'url_hash' => $hash,
                'host' => UrlNormalizer::normalizeHost((string) parse_url($url, PHP_URL_HOST)),
                'failure' => $outcome->errorCode->value,
                'reason' => $reason === null ? null : mb_substr($reason, 0, 255),
                'user_id' => $user?->id,
                'times' => 1,
                'first_failed_at' => now(),
                'last_failed_at' => now(),
            ]],
            ['url_hash'],
            ['failure', 'reason', 'user_id', 'times' => DB::raw('failed_shop_pages.times + 1'), 'last_failed_at'],
        );
    }

    /**
     * @return EloquentQueryBuilder<self>
     */
    public function prunable(): EloquentQueryBuilder
    {
        return self::query()->where('last_failed_at', '<=', now()->subMonths(6));
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
            'failure' => ProbeFailure::class,
            'times' => 'integer',
            'first_failed_at' => 'datetime',
            'last_failed_at' => 'datetime',
        ];
    }
}
