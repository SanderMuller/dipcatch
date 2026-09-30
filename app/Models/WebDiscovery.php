<?php declare(strict_types=1);

namespace App\Models;

use App\Enums\WebDiscoveryState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Web shop discovery for one product as a whole.
 *
 * @property string $product_id
 * @property ?int $web_search_id
 * @property ?CarbonImmutable $search_searched_at
 * @property WebDiscoveryState $state
 * @property ?CarbonImmutable $queued_at
 * @property ?CarbonImmutable $finished_at
 */
#[WithoutTimestamps]
#[Unguarded]
#[WithoutIncrementing]
final class WebDiscovery extends Model
{
    protected $primaryKey = 'product_id';

    protected $keyType = 'string';

    /**
     * Marks discovery as on its way before a job is queued, so an open page
     * shows it from its first render.
     */
    public static function markQueued(Product $product): void
    {
        self::query()->upsert(
            [['product_id' => $product->id, 'state' => WebDiscoveryState::Queued->value, 'queued_at' => now(), 'finished_at' => null]],
            ['product_id'],
            ['state', 'queued_at', 'finished_at'],
        );
    }

    public static function mark(Product $product, WebDiscoveryState $state): void
    {
        self::query()->upsert(
            [['product_id' => $product->id, 'state' => $state->value, 'finished_at' => $state === WebDiscoveryState::Done ? now() : null]],
            ['product_id'],
            ['state', 'finished_at'],
        );
    }

    public static function finishIfDone(Product $product): void
    {
        $unfinished = WebShopFinding::query()
            ->where('product_id', $product->id)
            ->current($product)
            ->whereNull('dismissed_at')
            ->unfinished()
            ->exists();

        if (! $unfinished) {
            self::mark($product, WebDiscoveryState::Done);
        }
    }

    /**
     * @return BelongsTo<WebSearch, $this>
     */
    public function search(): BelongsTo
    {
        return $this->belongsTo(WebSearch::class, 'web_search_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => WebDiscoveryState::class,
            'search_searched_at' => 'datetime',
            'queued_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
