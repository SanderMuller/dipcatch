<?php declare(strict_types=1);

namespace App\Services\TypeSafe;

/** Why a same-product check runs, which decides the budget it spends. */
enum ShopCheckPurpose: string
{
    /** The add-shop preview a person is looking at. */
    case AddShop = 'add-shop';

    /** The shop suggestions, checked after the response. */
    case Suggestions = 'suggestions';

    /** The first check of a web search result, on its title and snippet. */
    case WebDiscovery = 'web-discovery';

    /**
     * The second check of a web search result, on the page read. Counted
     * apart, so first checks never use up the checks that finish a product.
     */
    case WebDiscoveryConfirm = 'web-discovery-confirm';
}
