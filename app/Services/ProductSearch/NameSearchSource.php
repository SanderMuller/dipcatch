<?php declare(strict_types=1);

namespace App\Services\ProductSearch;

/** Where a name search result came from. */
enum NameSearchSource: string
{
    /** The supermarket catalogue (`checkjebon_prices`), bol.com feed rows included. */
    case Catalogue = 'catalogue';

    /** bol.com's Catalog API keyword search. */
    case Bol = 'bol';

    /** A web search through Serper. */
    case Web = 'web';
}
