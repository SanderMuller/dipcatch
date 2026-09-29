<?php declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddShopTool;
use App\Mcp\Tools\AddToShoppingListTool;
use App\Mcp\Tools\CreateProductTool;
use App\Mcp\Tools\DeleteProductTool;
use App\Mcp\Tools\GetProductTool;
use App\Mcp\Tools\ListCategoriesTool;
use App\Mcp\Tools\ListProductsTool;
use App\Mcp\Tools\PriceHistoryTool;
use App\Mcp\Tools\RecheckTool;
use App\Mcp\Tools\RemoveFromShoppingListTool;
use App\Mcp\Tools\RemoveShopTool;
use App\Mcp\Tools\SetCategoryTool;
use App\Mcp\Tools\SetImageTool;
use App\Mcp\Tools\SetThresholdTool;
use App\Mcp\Tools\SetTitleTool;
use App\Mcp\Tools\ShoppingListTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('DipCatch')]
/*
 * Move this whenever a tool is added, removed, or changes its schema.
 *
 * The package declares `tools.listChanged: false`, which tells a client the
 * roster never changes and licenses it to cache the list and the schemas for
 * the life of the connection. A stateless HTTP server has no channel to push a
 * change notification on, so the advertised version is the only signal a client
 * gets that its cached copy is stale. Two tools were added without moving it,
 * and a live session kept seeing the nine that came before them.
 */
#[Version('1.12.0')]
#[Instructions(<<<'TEXT'
DipCatch tracks the price of things this user buys more than once, across Dutch
supermarkets and webshops, and tells them when one drops.

A product is one thing the user buys. It holds one or more shops, each a URL at
a different retailer selling that same thing. DipCatch re-checks each shop and
answers two questions about it: lowest price, the smallest amount of money at
the till, and best value, the lowest price per kilo, litre or piece. They are
often different shops, and a drop alert fires on best value.

**Price is not a reason to skip a shop.** The point of tracking is to catch a
future drop, so a shop selling the same product at 40% above the field is worth
adding — that is the one whose promotion nobody else will see. Judge a page on
whether it sells the same product, in the same size, as the ones already on it.
More shops means more chances to catch a fall.

A stored price or stock value is as old as its last check, and rechecks run
on a schedule. When a user says a value is wrong, call recheck rather than
explaining that it will correct itself.

Adding a shop is two steps on purpose. Call create_product or add_shop without
`confirm` first: DipCatch fetches the page and reports what it found — title,
price, shop, pack size. Show that to the user. Only call again with
`confirm: true` and the `draft` token from the first response once they agree.
Confirming costs nothing against the page budget: it writes what the first
call already read. A shop is one page fetch, not two.

Never confirm on the user's behalf; a scrape can read the wrong number, and the
first call exists so a person sees it before it is stored.

Every tool acts on this user's own data and takes no user id. Every plan has a
product limit, Free a small one and Pro a generous one; when it is reached the
tool says so and writes nothing. On Free the answer is to upgrade, on Pro to
remove a product, never to retry.

The shopping list holds tracked products the user means to buy, each under the
shop where it is the best buy now. To put a whole shopping list on it, find
each product in list_products and add them all in one add_to_shopping_list
call. Something the user does not track yet needs create_product first.
TEXT)]
final class DipCatchServer extends Server
{
    /**
     * The package pages tools/list at 15. Past that, a client that does not
     * follow `nextCursor` never sees the rest of the roster, so every tool
     * goes out in the first page.
     */
    public int $defaultPaginationLength = 50;

    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ListProductsTool::class,
        ListCategoriesTool::class,
        GetProductTool::class,
        CreateProductTool::class,
        AddShopTool::class,
        RecheckTool::class,
        RemoveShopTool::class,
        SetThresholdTool::class,
        SetTitleTool::class,
        SetCategoryTool::class,
        SetImageTool::class,
        PriceHistoryTool::class,
        DeleteProductTool::class,
        ShoppingListTool::class,
        AddToShoppingListTool::class,
        RemoveFromShoppingListTool::class,
    ];
}
