<?php declare(strict_types=1);

namespace App\Mcp\Concerns;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;

/**
 * Every tool resolves its user from the token and starts every query there.
 * No tool takes a user id, and another account's record answers exactly like
 * one that never existed, so an id cannot be probed.
 */
trait InteractsWithOwner
{
    protected function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new ModelNotFoundException('No authenticated user.');
        }

        return $user;
    }

    /**
     * `validate()` returns mixed values; every tool reads its ids through
     * this rather than casting.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function str(array $validated, string $key, string $default = ''): string
    {
        $value = $validated[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    protected function ownedProduct(Request $request, string $id): ?Product
    {
        return Product::query()
            ->where('user_id', $this->user($request)->getKey())
            ->find($id);
    }

    /**
     * Scoped through the product: a shop id alone says nothing about who owns
     * it, so `Shop::find()` here would be cross-account access.
     */
    protected function ownedShop(Request $request, string $id): ?Shop
    {
        return Shop::query()
            ->whereHas('product', fn (Builder $query): Builder => $query->where('user_id', $this->user($request)->getKey()))
            ->find($id);
    }
}
