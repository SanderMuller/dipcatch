<?php declare(strict_types=1);

namespace App\Policies;

use App\Models\Shop;
use App\Models\User;

/**
 * A shop (offer) belongs to the user who owns its product. Without this
 * policy Filament hides the relation manager's delete action — it
 * authorizes `delete` against the related model, and an absent policy
 * denies everything.
 */
class ShopPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Shop $shop): bool
    {
        return $this->owns($user, $shop);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Shop $shop): bool
    {
        return $this->owns($user, $shop);
    }

    public function delete(User $user, Shop $shop): bool
    {
        return $this->owns($user, $shop);
    }

    public function restore(User $user, Shop $shop): bool
    {
        return $this->owns($user, $shop);
    }

    public function forceDelete(User $user, Shop $shop): bool
    {
        return $this->owns($user, $shop);
    }

    private function owns(User $user, Shop $shop): bool
    {
        return $shop->product?->user_id === $user->id;
    }
}
