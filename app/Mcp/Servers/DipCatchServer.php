<?php declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddShopTool;
use App\Mcp\Tools\CreateProductTool;
use App\Mcp\Tools\DeleteProductTool;
use App\Mcp\Tools\GetProductTool;
use App\Mcp\Tools\ListProductsTool;
use App\Mcp\Tools\PriceHistoryTool;
use App\Mcp\Tools\RecheckTool;
use App\Mcp\Tools\RemoveShopTool;
use App\Mcp\Tools\SetThresholdTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('DipCatch')]
#[Version('1.1.0')]
#[Instructions(<<<'TEXT'
DipCatch tracks the price of things this user buys more than once, across Dutch
supermarkets and webshops, and tells them when one drops.

A product is one thing the user buys. It holds one or more shops, each a URL at
a different retailer selling that same thing. DipCatch re-checks each shop and
reports which is cheapest, comparing on unit price so different pack sizes line
up honestly.

A stored price or stock value is as old as its last check, and rechecks run
on a schedule. When a user says a value is wrong, call recheck rather than
explaining that it will correct itself.

Adding a shop is two steps on purpose. Call create_product or add_shop without
`confirm` first: DipCatch fetches the page and reports what it found — title,
price, shop, pack size. Show that to the user. Only call again with
`confirm: true` and the `draft` token from the first response once they agree.
Never confirm on the user's behalf; a scrape can read the wrong number, and the
first call exists so a person sees it before it is stored.

Every tool acts on this user's own data and takes no user id. Free accounts have
a product limit; when it is reached the tool says so and writes nothing, and the
answer is to upgrade rather than to retry.
TEXT)]
class DipCatchServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ListProductsTool::class,
        GetProductTool::class,
        CreateProductTool::class,
        AddShopTool::class,
        RecheckTool::class,
        RemoveShopTool::class,
        SetThresholdTool::class,
        PriceHistoryTool::class,
        DeleteProductTool::class,
    ];
}
