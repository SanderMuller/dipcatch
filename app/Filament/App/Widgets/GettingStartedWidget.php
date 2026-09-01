<?php declare(strict_types=1);

namespace App\Filament\App\Widgets;

use App\Filament\App\Pages\NotificationSettings;
use App\Filament\App\Resources\Products\ProductResource;
use App\Models\Product;
use App\Support\SupportedShops;
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
            'shops' => SupportedShops::rows(),
        ];
    }
}
