<?php declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\ProductMarkdown;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * `/app/products/{product}.md`: the product page as markdown, for pasting
 * into a note or handing to an assistant.
 *
 * The same access as the page: the `app` group signs the reader in, and the
 * policy checked here is the one `ProductShow::mount()` checks.
 */
final class ProductMarkdownController extends Controller
{
    public function __invoke(Product $product): Response
    {
        Gate::authorize('view', $product);

        return response(ProductMarkdown::of($product))
            ->header('Content-Type', 'text/markdown; charset=utf-8')
            // Private to the owner, so no shared cache may keep a copy.
            ->header('Cache-Control', 'private, no-store');
    }
}
