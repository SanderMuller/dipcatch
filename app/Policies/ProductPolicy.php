<?php declare(strict_types=1);

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Product $product): bool
    {
        return $user->id === $product->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Product $product): bool
    {
        return $user->id === $product->user_id;
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->id === $product->user_id;
    }

    public function restore(User $user, Product $product): bool
    {
        return $user->id === $product->user_id;
    }

    public function forceDelete(User $user, Product $product): bool
    {
        return $user->id === $product->user_id;
    }
}
