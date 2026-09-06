<?php declare(strict_types=1);

namespace App\Billing;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The one place a plan limit is enforced. Limits bite on creation only:
 * nothing a user already tracks is ever removed, paused or hidden because
 * their plan changed, so a downgrade costs them the next product, never an
 * existing one.
 */
class PlanLimits
{
    /**
     * Null when the plan is unlimited.
     */
    public function remainingProducts(User $user): ?int
    {
        $limit = $user->entitlements()->maxProducts();

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - $this->countProducts($user));
    }

    /**
     * Null when the plan is unlimited.
     */
    public function remainingShops(Product $product): ?int
    {
        $user = $product->user;
        $limit = $user?->entitlements()->maxShopsPerProduct();

        if ($user === null || $limit === null) {
            return null;
        }

        return max(0, $limit - $this->countShops($product));
    }

    public function canAddProduct(User $user): bool
    {
        return $this->remainingProducts($user) !== 0;
    }

    public function canAddShop(Product $product): bool
    {
        return $this->remainingShops($product) !== 0;
    }

    /**
     * Locks the owner row first, so two requests racing at the boundary
     * serialize instead of both reading the same count and both passing.
     * Call inside the creating transaction.
     *
     * @throws PlanLimitReached
     */
    public function guardProduct(User $user): void
    {
        // Lock first, then decide. Reading the plan before the lock lets a
        // chargeback land in between, so an account could commit a creation
        // on an entitlement it had already lost.
        $this->lock($user);

        $limit = $user->entitlements()->maxProducts();

        if ($limit === null) {
            return;
        }

        if ($this->countProducts($user) >= $limit) {
            throw PlanLimitReached::products($limit);
        }
    }

    /**
     * @throws PlanLimitReached
     */
    public function guardShop(Product $product): void
    {
        $user = $product->user;

        if ($user === null) {
            return;
        }

        $this->lock($user);

        $limit = $user->entitlements()->maxShopsPerProduct();

        if ($limit === null) {
            return;
        }

        if ($this->countShops($product) >= $limit) {
            throw PlanLimitReached::shops($limit);
        }
    }

    /**
     * Only meaningful inside a transaction; outside one the row lock is
     * released immediately and the guard degrades to a plain count.
     *
     * Refreshes the model too: the locked row is the current truth, and the
     * entitlement decision that follows must read that, not a copy loaded
     * before a concurrent writer touched it.
     */
    private function lock(User $user): void
    {
        $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

        if ($locked !== null) {
            $user->setRawAttributes($locked->getAttributes(), true);
            $user->unsetRelation('subscriptions');
        }
    }

    private function countProducts(User $user): int
    {
        return DB::table('products')->where('user_id', $user->getKey())->count();
    }

    private function countShops(Product $product): int
    {
        return DB::table('shops')->where('product_id', $product->getKey())->count();
    }
}
