<?php declare(strict_types=1);

namespace App\Analytics\Models;

use App\Analytics\Enums\StatPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Unique visitors and users for one calendar week or month and one section.
 * Built by `analytics:rollup` and kept after the raw page views are pruned.
 *
 * Attributes
 * @property int $id
 * @property StatPeriod $period
 * @property string $section
 * @property CarbonImmutable $starts_on
 * @property int $users
 * @property int $visitors
 */
final class PageViewPeriodStat extends Model
{
    /** The `section` of the whole-site row: counted on its own, not summed. */
    final public const string ALL_SECTIONS = 'all';

    /* Attributes */
    final public const string
        ID = 'id',
        PERIOD = 'period',
        SECTION = 'section',
        STARTS_ON = 'starts_on',
        USERS = 'users',
        VISITORS = 'visitors';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            self::PERIOD => StatPeriod::class,
            self::STARTS_ON => 'immutable_date',
            self::USERS => 'integer',
            self::VISITORS => 'integer',
        ];
    }
}
