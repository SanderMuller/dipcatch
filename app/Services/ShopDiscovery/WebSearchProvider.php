<?php declare(strict_types=1);

namespace App\Services\ShopDiscovery;

use Illuminate\Container\Attributes\Bind;

#[Bind(SerperProvider::class)]
interface WebSearchProvider
{
    public function configured(): bool;

    /**
     * The organic results for a query, best first, as shoppers in the
     * country (a lowercase ISO 3166 code) see them.
     *
     * @return list<array{title: string, link: string, snippet: string, position: int}>
     *
     * @throws WebSearchFailed
     */
    public function search(string $query, string $country): array;
}
