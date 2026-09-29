<?php declare(strict_types=1);

namespace App\Mcp\Concerns;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
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

    /**
     * A model's key as the string it is. `getKey()` is typed mixed, and these
     * are uuid columns.
     */
    protected function key(Model $model): string
    {
        $key = $model->getKey();

        return is_scalar($key) ? (string) $key : '';
    }

    protected function ownedProduct(Request $request, string $id): ?Product
    {
        return Product::query()
            ->where('user_id', $this->user($request)->getKey())
            ->find($id);
    }

    /**
     * The account's products among the given ids. An id of another account's
     * product is simply missing, as one that never existed is.
     *
     * @param  list<string>  $ids
     * @return EloquentCollection<int, Product>
     */
    protected function ownedProducts(Request $request, array $ids): EloquentCollection
    {
        return Product::query()
            ->where('user_id', $this->user($request)->getKey())
            ->whereKey($ids)
            ->get();
    }

    /**
     * A validated list of uuids, lowercased as the database returns them, so
     * an id sent in capitals is not reported as missing.
     *
     * @param  array<string, mixed>  $validated
     * @return list<string>
     */
    protected function uuids(array $validated, string $key): array
    {
        $values = $validated[$key] ?? null;

        return is_array($values) ? array_values(array_unique(array_map(strtolower(...), array_filter($values, is_string(...))))) : [];
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
