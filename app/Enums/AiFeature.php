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

    public function promptHeading(): string
    {
        return match ($this) {
            self::Categories => __('Sort new products into categories with AI'),
            self::ShopChecks => __('Let AI check that a shop sells the same product'),
        };
    }

    public function promptText(): string
    {
        return match ($this) {
            self::Categories => __('Your plan includes it. DipCatch then places each product you add in a category, so you can filter your list. It is off until you switch it on.'),
            self::ShopChecks => __('Your plan includes it. DipCatch then warns you when a new shop sells a different product or pack, and finds more shops that sell yours. It is off until you switch it on.'),
        };
    }

    public function switchedOnText(): string
    {
        return match ($this) {
            self::Categories => __('Switched on. DipCatch sorts the products you add from now on.'),
            self::ShopChecks => __('Switched on. DipCatch checks the next shop you add.'),
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

    /**
     * Whether to offer the feature now: available here, the plan includes
     * it, it is still off, the person has not waved the prompts away, and
     * there is something for it to do.
     */
    public function isOfferedTo(User $user): bool
    {
        return $this->isAvailableTo($user)
            && $user->ai_prompts_dismissed_at === null
            && ! $this->isOn($user)
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
