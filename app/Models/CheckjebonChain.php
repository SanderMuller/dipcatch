<?php declare(strict_types=1);

namespace App\Models;

use App\Support\SupermarketChains;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-chain metadata from the checkjebon dataset: the base URL a product
 * link is appended to, and the display name. Owned by
 * RefreshCheckjebonDatasetCommand, read when a suggestion is rendered.
 *
 * @property int $id
 * @property string $chain
 * @property string $label
 * @property string $base_url
 * @property CarbonImmutable $refreshed_at
 */
#[WithoutTimestamps]
#[Unguarded]
final class CheckjebonChain extends Model
{
    /**
     * A chain whose newest row is older than this is dropped. Matches the
     * fail threshold of `CheckjebonFreshnessCheck`: the importer keeps a
     * chain's rows when upstream serves none, so one refreshed chain must
     * not make a stale catalogue look current.
     */
    public const int MAX_AGE_HOURS = 96;

    /**
     * Linkable chains holding rows no older than the freshness window, keyed
     * by chain. Per chain, never one global maximum: the importer keeps a
     * chain's rows when upstream serves none, so a refreshed AH would
     * otherwise make a month-old Jumbo catalogue look current.
     *
     * Not memoized: each caller keeps its own copy for one request.
     *
     * @return array<string, self>
     */
    public static function freshByKey(): array
    {
        $freshness = CheckjebonPrice::query()
            ->selectRaw('supermarket, max(refreshed_at) as chain_refreshed_at')
            ->groupBy('supermarket')
            ->pluck('chain_refreshed_at', 'supermarket');

        $cutoff = now()->subHours(self::MAX_AGE_HOURS);
        $chains = [];

        foreach (self::query()->get() as $chain) {
            if (! SupermarketChains::isLinkable($chain->chain)) {
                continue;
            }

            $refreshedAt = $freshness->get($chain->chain);

            if (! is_string($refreshedAt) || now()->parse($refreshedAt)->lt($cutoff)) {
                continue;
            }

            $chains[$chain->chain] = $chain;
        }

        return $chains;
    }

    public function productUrl(string $link): string
    {
        return $this->base_url . $link;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'refreshed_at' => 'datetime',
        ];
    }
}
