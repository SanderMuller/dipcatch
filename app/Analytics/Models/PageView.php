<?php declare(strict_types=1);

namespace App\Analytics\Models;

use App\Analytics\Enums\DeviceClass;
use App\Analytics\Enums\PageViewSection;
use Carbon\CarbonImmutable;
use Database\Factories\PageViewFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder as EloquentQueryBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One counted page load. Holds no IP address, query string or full referrer
 * URL. `user_id` is set only for a signed-in person who did not opt out.
 *
 * Attributes
 * @property string|null $country
 * @property DeviceClass $device
 * @property int $id
 * @property bool $is_authenticated
 * @property string $path
 * @property string|null $referrer_host
 * @property string|null $route_name
 * @property PageViewSection $section
 * @property int|null $user_id
 * @property string|null $utm_campaign
 * @property string|null $utm_medium
 * @property string|null $utm_source
 * @property CarbonImmutable $viewed_at
 * @property string|null $visitor_id
 * @property string $visitor_hash
 *
 * n:1 relations
 * @property-read Model|null $user
 */
#[UseFactory(PageViewFactory::class)]
final class PageView extends Model
{
    /** @use HasFactory<PageViewFactory> */
    use HasFactory;

    use MassPrunable;

    /* Attributes */
    final public const string
        COUNTRY = 'country',
        DEVICE = 'device',
        ID = 'id',
        IS_AUTHENTICATED = 'is_authenticated',
        PATH = 'path',
        REFERRER_HOST = 'referrer_host',
        ROUTE_NAME = 'route_name',
        SECTION = 'section',
        USER_ID = 'user_id',
        UTM_CAMPAIGN = 'utm_campaign',
        UTM_MEDIUM = 'utm_medium',
        UTM_SOURCE = 'utm_source',
        VIEWED_AT = 'viewed_at',
        VISITOR_HASH = 'visitor_hash',
        VISITOR_ID = 'visitor_id';

    /* Relations */
    final public const string
        USER = 'user';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return EloquentQueryBuilder<self>
     */
    public function prunable(): EloquentQueryBuilder
    {
        $anonymousDays = config()->integer('analytics.retention.anonymous_days', 32);
        $linkedDays = config()->integer('analytics.retention.linked_days', 90);

        return self::query()
            ->where(fn (EloquentQueryBuilder $query) => $query
                ->whereNull(self::USER_ID)
                ->where(self::VIEWED_AT, '<', now()->subDays($anonymousDays)))
            ->orWhere(fn (EloquentQueryBuilder $query) => $query
                ->whereNotNull(self::USER_ID)
                ->where(self::VIEWED_AT, '<', now()->subDays($linkedDays)));
    }

    /**
     * The app's user model, from the auth config, so this class names no
     * app model.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $model */
        $model = config()->string('auth.providers.users.model');

        return $this->belongsTo($model, self::USER_ID);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            self::DEVICE => DeviceClass::class,
            self::IS_AUTHENTICATED => 'boolean',
            self::SECTION => PageViewSection::class,
            self::VIEWED_AT => 'immutable_datetime',
        ];
    }
}
