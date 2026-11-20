<?php declare(strict_types=1);

namespace App\Analytics\Models;

use App\Analytics\Enums\PageViewSection;
use App\Analytics\Enums\StatDimension;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Daily totals for one section, dimension and value. Built by
 * `analytics:rollup` and kept after the raw page views are pruned.
 *
 * Attributes
 * @property CarbonImmutable $date
 * @property StatDimension $dimension
 * @property int $id
 * @property PageViewSection $section
 * @property int $users
 * @property string $value
 * @property int $views
 * @property int $visitors
 */
final class PageViewDailyStat extends Model
{
    /* Attributes */
    final public const string
        DATE = 'date',
        DIMENSION = 'dimension',
        ID = 'id',
        SECTION = 'section',
        USERS = 'users',
        VALUE = 'value',
        VIEWS = 'views',
        VISITORS = 'visitors';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            self::DATE => 'immutable_date',
            self::DIMENSION => StatDimension::class,
            self::SECTION => PageViewSection::class,
            self::USERS => 'integer',
            self::VIEWS => 'integer',
            self::VISITORS => 'integer',
        ];
    }
}
