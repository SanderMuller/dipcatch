<?php declare(strict_types=1);

namespace App\Enums;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A place where the app offers an AI feature to a Pro account that has it
 * off: where the person does by hand what the feature would do. Each place
 * has its own "Not now", which hides that place for {@see self::QUIET_DAYS}
 * days; the other places still offer it.
 */
enum AiPromptPlace: string
{
    case ProductList = 'product_list';
    case ProductCategory = 'product_category';
    case AddShop = 'add_shop';
    case WizardShops = 'wizard_shops';
    case ShopSuggestions = 'shop_suggestions';
    case DashboardSuggestions = 'dashboard_suggestions';
    case PackSize = 'pack_size';
    case Alert = 'alert';

    public const int QUIET_DAYS = 30;

    public function feature(): AiFeature
    {
        return match ($this) {
            self::ProductList, self::ProductCategory => AiFeature::Categories,
            default => AiFeature::ShopChecks,
        };
    }

    public function text(): string
    {
        return match ($this) {
            self::ProductList => __('Switch it on and DipCatch puts your products in a category, the ones already here and each one you add, so you can filter your list.'),
            self::ProductCategory => __('Switch it on and DipCatch picks a category for each product you track, so you don\'t have to.'),
            self::AddShop => __('Switch it on and DipCatch warns you when a shop sells a different product or pack size.'),
            self::WizardShops => __('Switch it on and DipCatch searches the web for more shops that sell this product.'),
            self::ShopSuggestions => __('Switch it on and DipCatch searches the web for more shops that sell this product, and checks that each one sells the same thing.'),
            self::DashboardSuggestions => __('Switch it on and DipCatch searches the web for more shops that sell your products.'),
            self::PackSize => __('Some shops here don\'t show their pack size. Switch it on and DipCatch checks the page, so the price per kilo stays right.'),
            self::Alert => __('Switch it on and DipCatch suggests an alert price from how deep products like this usually go on sale.'),
        };
    }

    /** Whether "Not now" was clicked here in the last {@see self::QUIET_DAYS} days. */
    public function isQuietFor(User $user): bool
    {
        $dismissed = $user->ai_prompt_dismissals[$this->value] ?? null;

        return is_string($dismissed) && CarbonImmutable::parse($dismissed)->isAfter(now()->subDays(self::QUIET_DAYS));
    }

    public function dismissFor(User $user): void
    {
        $user->forceFill(['ai_prompt_dismissals' => [...$user->ai_prompt_dismissals ?? [], $this->value => now()->toIso8601String()]])->save();
    }
}
