<?php declare(strict_types=1);

namespace App\Models;

use App\Enums\WebFindingStatus;
use App\Services\TypeSafe\TypeSafeClient;
use App\Support\PackSize;
use App\Support\UnitWord;
use App\Support\UrlNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One page a web search returned for one product, and how far its checks
 * got. See specs/web-shop-discovery.md §3.2.
 *
 * @property int $id
 * @property string $product_id
 * @property ?int $web_search_id
 * @property string $url
 * @property string $url_hash
 * @property string $host
 * @property ?string $add_url
 * @property ?string $served_host
 * @property string $search_title
 * @property ?string $snippet
 * @property ?float $first_chance
 * @property ?string $page_title
 * @property ?numeric-string $page_pack_quantity
 * @property ?string $page_pack_unit
 * @property ?numeric-string $page_price
 * @property ?string $page_currency
 * @property ?string $page_gtin
 * @property ?string $matched_gtin
 * @property ?list<string> $checked_gtins
 * @property ?CarbonImmutable $read_at
 * @property ?float $second_chance
 * @property WebFindingStatus $status
 * @property ?string $failure
 * @property int $attempts
 * @property ?CarbonImmutable $next_attempt_at
 * @property ?CarbonImmutable $claimed_at
 * @property string $fingerprint
 * @property int $generation
 * @property ?CarbonImmutable $checked_at
 * @property ?CarbonImmutable $dismissed_at
 * @property ?string $lead_url The Klarna page a lead finding came from; null for an open-search finding.
 * @property ?numeric-string $lead_pack_quantity
 * @property ?string $lead_pack_unit
 * @property ?string $variant_key The variant the page read picked.
 */
#[WithoutTimestamps]
#[Unguarded]
final class WebShopFinding extends Model
{
    /** What a finding that starts over forgets: every read and check. */
    public const array CLEARED_CHECKS = [
        'first_chance' => null,
        'add_url' => null,
        'served_host' => null,
        'page_title' => null,
        'page_pack_quantity' => null,
        'page_pack_unit' => null,
        'page_price' => null,
        'page_currency' => null,
        'page_gtin' => null,
        'matched_gtin' => null,
        'checked_gtins' => null,
        'variant_key' => null,
        'read_at' => null,
        'second_chance' => null,
        'failure' => null,
        'attempts' => 0,
        'next_attempt_at' => null,
        'checked_at' => null,
    ];

    /**
     * What a finding was checked against: the product title and its distinct
     * tracked pack sizes. Not the tracked shops' URLs or barcodes, so adding
     * one suggested shop of the same pack leaves every other finding
     * current. Barcodes are guarded apart, through `checked_gtins`.
     */
    public static function fingerprintFor(Product $product): string
    {
        $product->loadMissing('shops');

        return hash('sha256', mb_strtolower($product->title . '|' . implode(',', TypeSafeClient::trackedPackSizes($product))));
    }

    /**
     * @return list<string>
     */
    public static function trackedGtins(Product $product): array
    {
        $product->loadMissing('shops');

        $gtins = array_values(array_unique(array_filter(
            $product->shops->map(static fn (Shop $shop): ?string => $shop->gtin)->all(),
            static fn (?string $gtin): bool => $gtin !== null && $gtin !== '',
        )));
        sort($gtins);

        return $gtins;
    }

    /**
     * Whether a barcode this finding was checked against is gone from the
     * product: corrected or removed. An added barcode changes nothing.
     *
     * @param  list<string>  $trackedGtins
     */
    public function hasStaleGtins(array $trackedGtins): bool
    {
        return array_diff($this->checked_gtins ?? [], $trackedGtins) !== [];
    }

    /**
     * The suggestions to show for a product: proposed, not hidden, checked
     * against the product as it is now, and at a shop it does not track yet
     * and its owner did not hide.
     *
     * @return EloquentCollection<int, self>
     */
    public static function shownFor(Product $product): EloquentCollection
    {
        $product->loadMissing('shops');
        $trackedHosts = array_values($product->shops->map(static fn (Shop $shop): string => $shop->host)->all());
        $trackedGtins = self::trackedGtins($product);
        $hidden = HiddenShop::hostsOf($product->user);

        return self::query()
            ->where('product_id', $product->id)
            ->current($product)
            ->where('status', WebFindingStatus::Proposed)
            ->whereNull('dismissed_at')
            ->orderByDesc('second_chance')
            ->orderBy('id')
            ->get()
            ->filter(fn (self $finding): bool => $finding->isShowable($trackedHosts, $trackedGtins, $hidden))
            ->values();
    }

    /**
     * @param  list<string>  $trackedHosts
     * @param  list<string>  $trackedGtins
     * @param  list<string>  $hiddenHosts
     */
    private function isShowable(array $trackedHosts, array $trackedGtins, array $hiddenHosts): bool
    {
        $atTrackedShop = in_array($this->addHost(), $trackedHosts, strict: true)
            || ($this->served_host !== null && in_array($this->served_host, $trackedHosts, strict: true));
        $barcodeGone = $this->matched_gtin !== null && ! in_array($this->matched_gtin, $trackedGtins, strict: true);

        $hidden = HiddenShop::covers($hiddenHosts, $this->addHost()) || ($this->served_host !== null && HiddenShop::covers($hiddenHosts, $this->served_host));

        return ! $atTrackedShop && ! $barcodeGone && ! $hidden && ! $this->hasStaleGtins($trackedGtins);
    }

    /**
     * Writes a step's columns only while the finding is still where the step
     * read it: the same generation, fingerprint and status. A finding that
     * started over while a page read or a Jev call was on its way is left
     * alone. Never touches `dismissed_at`, so a Hide during a run stays.
     *
     * @param  array<string, mixed>  $columns
     */
    public function writeIfUnchanged(WebFindingStatus $expected, array $columns): int
    {
        return self::query()
            ->whereKey($this->id)
            ->where('generation', $this->generation)
            ->where('fingerprint', $this->fingerprint)
            ->where('status', $expected)
            ->toBase()
            ->update(array_map(static fn (mixed $value): mixed => is_array($value) ? json_encode($value) : $value, $columns));
    }

    public function isLead(): bool
    {
        return $this->lead_url !== null;
    }

    public function leadPackSize(): ?PackSize
    {
        return $this->lead_pack_quantity === null || $this->lead_pack_unit === null
            ? null
            : PackSize::of((float) $this->lead_pack_quantity, $this->lead_pack_unit);
    }

    /**
     * The page's size when no shop on the product tracks it: a suggestion in
     * another size, which the product compares per unit.
     */
    public function otherPackSize(Product $product): ?PackSize
    {
        $size = $this->page_pack_quantity === null || $this->page_pack_unit === null
            ? null
            : PackSize::of((float) $this->page_pack_quantity, $this->page_pack_unit);

        if (! $size instanceof PackSize) {
            return null;
        }

        $product->loadMissing('shops');
        $tracked = $product->shops->map(static fn (Shop $shop): ?PackSize => $shop->packSize())->filter();

        return $tracked->isEmpty() || $tracked->contains(static fn (PackSize $other): bool => $other->isSameSizeAs($size)) ? null : $size;
    }

    /** "Other size: 7 kg — compared per kilo", or null when the page states no size, the product tracks none, or it tracks this one. */
    public function otherSizeNote(Product $product): ?string
    {
        $size = $this->otherPackSize($product);

        $note = $size instanceof PackSize
            ? __('Other size: :pack — compared per :unit', ['pack' => UnitWord::pack($size), 'unit' => UnitWord::noun($size->unit)])
            : null;

        return is_string($note) ? $note : null;
    }

    /** The host Add would track: the read page's address, else the search result's. */
    public function addHost(): string
    {
        $host = parse_url($this->add_url ?? $this->url, PHP_URL_HOST);

        return is_string($host) ? UrlNormalizer::normalizeHost($host) : $this->host;
    }

    /**
     * @param  EloquentQueryBuilder<$this>  $query
     */
    #[Scope]
    protected function current(EloquentQueryBuilder $query, Product $product): void
    {
        $query->where('fingerprint', self::fingerprintFor($product));
    }

    /**
     * @param  EloquentQueryBuilder<$this>  $query
     */
    #[Scope]
    protected function unfinished(EloquentQueryBuilder $query): void
    {
        $query->whereIn('status', WebFindingStatus::unfinished());
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WebFindingStatus::class,
            'page_pack_quantity' => 'decimal:3',
            'page_price' => 'decimal:2',
            'lead_pack_quantity' => 'decimal:2',
            'first_chance' => 'float',
            'second_chance' => 'float',
            'checked_gtins' => 'array',
            'read_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'claimed_at' => 'datetime',
            'checked_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'attempts' => 'integer',
            'generation' => 'integer',
        ];
    }
}
