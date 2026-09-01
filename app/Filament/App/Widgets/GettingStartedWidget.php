<?php declare(strict_types=1);

namespace App\Filament\App\Widgets;

use App\Filament\App\Pages\NotificationSettings;
use App\Filament\App\Resources\Products\ProductResource;
use App\Models\Product;
use App\Support\Favicon;
use Filament\Widgets\Widget;

/**
 * First-run dashboard: shown instead of the stats and tables while the
 * user tracks nothing yet, so the empty dashboard explains the product
 * and points at the one action that matters.
 */
class GettingStartedWidget extends Widget
{
    protected static ?int $sort = -10;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.app.widgets.getting-started';

    /**
     * Shops with a dedicated adapter or data source; shown as logos so a
     * new user knows which links to try first.
     *
     * @var list<string>
     */
    private const array SUPPORTED_HOSTS = [
        'ah.nl', 'jumbo.com', 'dirk.nl', 'lidl.nl', 'spar.nl',
        'bol.com', 'amazon.nl', 'zooplus.nl',
    ];

    public static function canView(): bool
    {
        return ! Product::query()->where('user_id', auth()->id())->exists();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'createUrl' => ProductResource::getUrl('create'),
            'settingsUrl' => NotificationSettings::getUrl(),
            'shops' => array_map(
                static fn (string $host): array => ['host' => $host, 'favicon' => Favicon::url($host, 32)],
                self::SUPPORTED_HOSTS,
            ),
        ];
    }
}
