<?php declare(strict_types=1);

namespace App\Services\ShopDiscovery;

use Illuminate\Container\Attributes\Bind;

#[Bind(SerperProvider::class)]
interface WebSearchProvider
{
    public function configured(): bool;

    /**
     * The organic results for a query, best first.
     *
     * @return list<array{title: string, link: string, snippet: string, position: int}>
     *
     * @throws WebSearchFailed
     */
    public function search(string $query): array;
}
