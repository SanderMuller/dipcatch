<?php declare(strict_types=1);

namespace App\Services\TypeSafe;

/** Why a same-product check runs, which decides the budget it spends. */
enum ShopCheckPurpose: string
{
    /** The add-shop preview a person is looking at. */
    case AddShop = 'add-shop';

    /** The shop suggestions, checked after the response. */
    case Suggestions = 'suggestions';
}
