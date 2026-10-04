<?php declare(strict_types=1);

namespace App\Models;

use App\Enums\WebDiscoveryState;
use App\Jobs\SearchProductBarcode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Config;

/**
 * Web shop discovery for one product as a whole.
 *
 * @property string $product_id
 * @property ?int $web_search_id
 * @property ?CarbonImmutable $search_searched_at
 * @property WebDiscoveryState $state
 * @property ?CarbonImmutable $queued_at
 * @property ?CarbonImmutable $finished_at
 * @property ?string $klarna_url The Klarna page the product's shop leads come from.
 * @property ?int $klarna_search_id
 * @property int $klarna_generation Raised each time the lead source is resolved again.
 * @property int $klarna_attempts Attempts of the source or page step of this generation.
 * @property ?list<array{host: string, title: string, pack_quantity: ?float, pack_unit: ?string, state: string, attempts: int}> $klarna_leads
 * @property ?CarbonImmutable $klarna_checked_at When the Klarna work of this generation finished.
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

        if (! $unfinished && ! self::query()->find($product->id)?->klarnaUnfinished() && ! SearchProductBarcode::isPending($product)) {
            self::mark($product, WebDiscoveryState::Done);
        }
    }

    public function klarnaUnfinished(): bool
    {
        return Config::boolean('dipcatch.web_discovery.klarna_leads') && $this->klarna_checked_at === null;
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
            'klarna_generation' => 'integer',
            'klarna_attempts' => 'integer',
            'klarna_leads' => 'array',
            'klarna_checked_at' => 'datetime',
        ];
    }
}
