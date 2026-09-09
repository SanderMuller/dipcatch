<?php declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\ShopPages;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One landing page per supported shop, plus the hub that lists them.
 *
 * The route's slug constraint is built from the same config the pages are, so
 * this guard only fires when a host is dropped between the route cache and
 * the request.
 */
final class ShopPageController extends Controller
{
    public function index(): View
    {
        return view('shops', ['shops' => ShopPages::all()]);
    }

    public function show(string $slug): View
    {
        $shop = ShopPages::find($slug);

        if ($shop === null) {
            throw new NotFoundHttpException();
        }

        return view('shop', ['shop' => $shop]);
    }
}
