<?php declare(strict_types=1);

namespace App\Services\ShopDiscovery;

use Illuminate\Support\Facades\Config;

/**
 * The app-wide daily counter a paid web search spends. Name searches count
 * apart, so people typing names never use up background discovery.
 */
enum WebSearchBudget
{
    /** Shop discovery and the Klarna steps. */
    case Discovery;

    /** A product name typed in the add-product wizard. */
    case NameSearch;

    public function counterKey(string $date): string
    {
        return match ($this) {
            self::Discovery => "web-discovery:searches:{$date}",
            self::NameSearch => "name-search:searches:{$date}",
        };
    }

    /** Zero or less lifts the cap. */
    public function dailyLimit(): int
    {
        return match ($this) {
            self::Discovery => Config::integer('dipcatch.web_discovery.daily_search_limit'),
            self::NameSearch => Config::integer('dipcatch.web_discovery.name_search_daily_limit'),
        };
    }
}
