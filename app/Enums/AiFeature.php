<?php declare(strict_types=1);

namespace App\Enums;

use App\Models\Product;
use App\Models\User;
use App\Services\TypeSafe\TypeSafeClient;

/**
 * The AI features a person switches on themselves. The app offers each one
 * where it helps, and never switches one on by itself.
 */
enum AiFeature: string
{
    case Categories = 'categories';
    case ShopChecks = 'shop_checks';

    /** The `users` column that holds the opt-in. */
    public function column(): string
    {
        return match ($this) {
            self::Categories => 'auto_categories',
            self::ShopChecks => 'shop_checks',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Categories => __('Automatic categories'),
            self::ShopChecks => __('Shop checks'),
        };
    }

    /**
     * @return list<string> One line per thing it does.
     */
    public function does(): array
    {
        return match ($this) {
            self::ShopChecks => [
                __('Warns you when a new shop sells another flavour or pack size.'),
                __('Checks a page that hides its pack size, so the price per kilo stays right.'),
                __('Searches the web, by name, by barcode and on comparison sites, for more shops.'),
                __('Suggests an alert price from how deep a product usually goes on sale.'),
            ],
            self::Categories => [
                __('Files every product you add into a category.'),
                __('Sorts the ones already here without a category too.'),
                __('Never changes a category you chose yourself.'),
            ],
        };
    }

    public function switchedOnText(): string
    {
        return match ($this) {
            self::Categories => __('Switched on. DipCatch is sorting the products without a category now, and each one you add.'),
            self::ShopChecks => __('Switched on. DipCatch checks the shops you add and their pack sizes, and looks for more shops.'),
        };
    }

    /** Whether this account may switch it on: available here, and in the plan. */
    public function isAvailableTo(User $user): bool
    {
        return TypeSafeClient::configured() && $this->planAllows($user);
    }

    public function isOn(User $user): bool
    {
        return (bool) $user->getAttribute($this->column());
    }

    public function planAllows(User $user): bool
    {
        return match ($this) {
            self::Categories => $user->entitlements()->allowsAutoCategories(),
            self::ShopChecks => $user->entitlements()->allowsShopChecks(),
        };
    }

    public function isOfferedTo(User $user, AiPromptPlace $place): bool
    {
        return $this->isAvailableTo($user)
            && ! $this->isOn($user)
            && ! $place->isQuietFor($user)
            && $this->hasWorkFor($user);
    }

    private function hasWorkFor(User $user): bool
    {
        return match ($this) {
            self::Categories => Product::query()->where('user_id', $user->id)->whereNull('category')->exists(),
            self::ShopChecks => true,
        };
    }
}
