<?php declare(strict_types=1);

namespace App\Models;

use App\Enums\CanaryOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;

/**
 * What the last canary run learned about one host adapter.
 *
 * One row per adapter, replaced on every run: the health check reads the
 * current state, and `last_ok_price` carries the only history the design
 * needs. The `url` is stored so a repointed entry can drop its baseline
 * instead of measuring a new product against the price of the old one.
 *
 * @property int $id
 * @property string $adapter
 * @property string $url
 * @property CanaryOutcome $outcome
 * @property string|null $observed_adapter
 * @property string|null $price
 * @property string|null $last_ok_price
 * @property int $consecutive_unreachable
 * @property string|null $detail
 * @property CarbonImmutable $checked_at
 */
#[WithoutTimestamps]
#[Unguarded]
final class AdapterCanaryResult extends Model
{
    public const string ADAPTER = 'adapter';

    public const string URL = 'url';

    public const string OUTCOME = 'outcome';

    public const string OBSERVED_ADAPTER = 'observed_adapter';

    public const string PRICE = 'price';

    public const string LAST_OK_PRICE = 'last_ok_price';

    public const string CONSECUTIVE_UNREACHABLE = 'consecutive_unreachable';

    public const string DETAIL = 'detail';

    public const string CHECKED_AT = 'checked_at';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            self::OUTCOME => CanaryOutcome::class,
            self::PRICE => 'decimal:2',
            self::LAST_OK_PRICE => 'decimal:2',
            self::CONSECUTIVE_UNREACHABLE => 'integer',
            self::CHECKED_AT => 'datetime',
        ];
    }
}
