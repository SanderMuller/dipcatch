<?php declare(strict_types=1);

namespace App\Filament\App\Widgets;

use App\Filament\App\Pages\NotificationSettings;
use App\Filament\App\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\Shop;
use Filament\Widgets\Widget;

/**
 * Onboarding checklist for users who track something but have not yet
 * reached the point where DipCatch pays off: a second shop on a product.
 * Disappears on its own once every step is done.
 */
class NextStepsWidget extends Widget
{
    protected static ?int $sort = -9;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.app.widgets.next-steps';

    public static function canView(): bool
    {
        $userId = auth()->id();

        return Product::query()->where('user_id', $userId)->exists()
            && ! self::hasComparison($userId);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $userId = auth()->id();

        $firstProduct = Product::query()
            ->where('user_id', $userId)
            ->orderBy('created_at')
            ->first();

        return [
            'settingsUrl' => NotificationSettings::getUrl(),
            'steps' => [
                [
                    'title' => 'Track a product',
                    'description' => 'Paste a product link; DipCatch reads the price and starts watching.',
                    'done' => true,
                    'url' => null,
                    'cta' => null,
                ],
                [
                    'title' => 'Add a second shop to compare',
                    'description' => 'Open a product and add the same item from another shop. The cheapest offer wins, and unit prices (€/kg) make different pack sizes comparable.',
                    'done' => false,
                    'url' => $firstProduct instanceof Product
                        ? ProductResource::getUrl('view', ['record' => $firstProduct])
                        : ProductResource::getUrl('index'),
                    'cta' => 'Add a shop',
                ],
            ],
        ];
    }

    private static function hasComparison(int|string|null $userId): bool
    {
        return Shop::query()
            ->whereIn('product_id', Product::query()->where('user_id', $userId)->select('id'))
            ->groupBy('product_id')
            ->havingRaw('COUNT(*) >= 2')
            ->exists();
    }
}
